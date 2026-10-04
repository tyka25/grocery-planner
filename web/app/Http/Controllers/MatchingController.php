<?php

namespace App\Http\Controllers;

use App\Models\CanonicalItem;
use App\Models\Order;
use App\Models\StoreProduct;
use App\Services\Matching\MatchSuggester;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Human review of canonical-item matching. Every mutation re-runs the
 * suggester, since each confirm/reject/new item changes what the best
 * suggestion is for everything else.
 */
class MatchingController extends Controller
{
    private const TABS = [
        'review' => ['auto'],
        'unlinked' => ['unmatched', 'rejected'],
        'linked' => ['confirmed'],
    ];

    public function index(Request $request): Response
    {
        $tab = array_key_exists($request->query('tab'), self::TABS) ? $request->query('tab') : 'review';

        $products = StoreProduct::query()
            ->with(['store:id,name', 'canonicalItem:id,name'])
            ->whereIn('match_status', self::TABS[$tab])
            ->withCount(['orderLines as purchases' => fn ($q) => $q->where('line_type', 'delivered')])
            ->addSelect(['last_bought' => Order::query()
                ->selectRaw('max(orders.ordered_on)')
                ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
                ->whereColumn('order_lines.store_product_id', 'store_products.id'),
            ])
            ->orderByDesc('purchases')
            ->orderBy('description')
            ->get()
            ->map(fn (StoreProduct $p) => [
                'id' => $p->id,
                'description' => $p->description,
                'size' => $p->size_text,
                'store' => $p->store->name,
                'purchases' => $p->purchases,
                'last_bought' => $p->last_bought,
                'status' => $p->match_status,
                'score' => $p->match_score,
                'item' => $p->canonicalItem?->only(['id', 'name']),
            ]);

        $counts = StoreProduct::query()
            ->selectRaw('match_status, count(*) as n')
            ->groupBy('match_status')
            ->pluck('n', 'match_status');

        return Inertia::render('Matching/Index', [
            'tab' => $tab,
            'products' => $products,
            'counts' => collect(self::TABS)->map(fn ($statuses) => $counts->only($statuses)->sum()),
            'items' => CanonicalItem::query()
                ->withCount(['storeProducts as linked' => fn ($q) => $q->where('match_status', 'confirmed')])
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /** Pre-fill data for "make a new item from this product". */
    public function similar(StoreProduct $storeProduct): JsonResponse
    {
        $suggester = MatchSuggester::fromConfig();
        $preselect = (float) config('grocery_planner.matching.preselect_threshold');

        return response()->json([
            'name' => $suggester->normalizer()->displayName($storeProduct->description),
            'similar' => $suggester->similarUnlinked($storeProduct)->map(fn (StoreProduct $p) => [
                'id' => $p->id,
                'description' => $p->description,
                'store' => $p->store->name,
                'similarity' => $p->similarity,
                'preselected' => $p->similarity >= $preselect,
            ])->values(),
        ]);
    }

    public function confirm(Request $request, StoreProduct $storeProduct): RedirectResponse
    {
        $data = $request->validate([
            'canonical_item_id' => ['nullable', 'integer', 'exists:canonical_items,id'],
        ]);

        $itemId = $data['canonical_item_id'] ?? $storeProduct->canonical_item_id;
        abort_if($itemId === null, 422, 'Nothing to confirm: no suggestion and no item chosen.');

        $storeProduct->confirmMatch(CanonicalItem::findOrFail($itemId));
        MatchSuggester::fromConfig()->run();

        return back();
    }

    public function reject(StoreProduct $storeProduct): RedirectResponse
    {
        $storeProduct->rejectMatch();
        MatchSuggester::fromConfig()->run();

        return back();
    }

    /** Create a canonical item and confirm the chosen products into it. */
    public function storeItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('canonical_items', 'name')],
            'store_product_ids' => ['required', 'array', 'min:1'],
            'store_product_ids.*' => ['integer', 'exists:store_products,id'],
        ]);

        DB::transaction(function () use ($data) {
            $item = CanonicalItem::create(['name' => trim($data['name'])]);
            StoreProduct::whereKey($data['store_product_ids'])->get()
                ->each(fn (StoreProduct $p) => $p->confirmMatch($item));
        });

        MatchSuggester::fromConfig()->run();

        return back();
    }
}
