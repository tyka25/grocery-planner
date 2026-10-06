<?php

namespace App\Http\Controllers;

use App\Models\CanonicalItem;
use App\Models\ItemStorePref;
use App\Models\ListItem;
use App\Models\ShoppingList;
use App\Models\Store;
use App\Services\Planner\PlanService;
use App\Services\Sidecar\SidecarRuns;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The household's current shopping list and its store plan. Once a list has
 * been planned, every edit re-plans it, so the store split on screen is never
 * stale. Hand-chosen stores survive re-planning.
 */
class ShoppingListController extends Controller
{
    public function show(): Response
    {
        $list = ShoppingList::current();
        $service = PlanService::fromConfig();
        $input = $service->builder()->build($list);
        $optionsByLine = collect($input['lines'])->pluck('options', 'list_item_id');
        $stores = Store::where('enabled', true)->get()->keyBy('id');

        $items = $list->items()->with(['canonicalItem', 'assignment'])->orderBy('id')->get();

        $lines = $items->map(function (ListItem $li) use ($optionsByLine, $stores, $list) {
            $options = $optionsByLine->get($li->id, []);
            $a = $li->assignment;

            return [
                'id' => $li->id,
                'name' => $li->canonicalItem?->name ?? $li->free_text,
                'qty' => (float) $li->qty,
                'source' => $li->source,
                'canonical_item_id' => $li->canonical_item_id,
                'is_staple' => (bool) $li->canonicalItem?->is_staple,
                'assignment' => $a ? [
                    'store_id' => $a->store_id,
                    'reason' => $a->reason,
                    'note' => $a->note,
                    'unit_price' => $a->estimated_unit_price,
                    'price_source' => $a->price_source,
                    'available' => $a->available,
                ] : null,
                'unplanned_reason' => $a || $list->status !== 'planned' ? null : $this->unplannedReason($li, $options),
                'move_options' => collect($options)->pluck('store_id')->unique()->values()
                    ->map(fn ($id) => ['id' => $id, 'label' => $stores[$id]->label]),
            ];
        });

        return Inertia::render('List/Show', [
            'list' => $list->only(['id', 'status', 'planned_at']),
            'lines' => $lines,
            'stores' => $list->status === 'planned' ? $this->storeSummaries($lines, $stores, $service) : [],
            'catalog' => CanonicalItem::orderBy('name')->get(['id', 'name', 'is_staple']),
            'stockCheck' => app(SidecarRuns::class)->statusForUi(),
        ]);
    }

    public function addItem(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'qty' => ['nullable', 'numeric', 'gt:0'],
        ]);

        $list = ShoppingList::current();
        $name = trim(preg_replace('/\s+/u', ' ', $data['name']));
        $item = CanonicalItem::findByTypedName($name);

        $list->items()->create([
            'canonical_item_id' => $item?->id,
            'free_text' => $item ? null : $name,
            'qty' => $data['qty'] ?? ($item?->default_qty ?? 1),
            'source' => 'adhoc',
        ]);
        if ($item) {
            app(SidecarRuns::class)->requestCheck('unchecked');
        }

        return $this->replanAndBack($list);
    }

    public function addStaples(): RedirectResponse
    {
        $list = ShoppingList::current();
        $already = $list->items()->whereNotNull('canonical_item_id')->pluck('canonical_item_id');

        CanonicalItem::where('is_staple', true)->whereNotIn('id', $already)->get()
            ->each(fn (CanonicalItem $i) => $list->items()->create([
                'canonical_item_id' => $i->id,
                'qty' => $i->default_qty,
                'source' => 'staple',
            ]));
        app(SidecarRuns::class)->requestCheck('unchecked');

        return $this->replanAndBack($list);
    }

    public function updateItem(Request $request, ListItem $listItem): RedirectResponse
    {
        $data = $request->validate(['qty' => ['required', 'numeric', 'gt:0']]);
        $listItem->update($data);

        return $this->replanAndBack($listItem->shoppingList);
    }

    public function removeItem(ListItem $listItem): RedirectResponse
    {
        $list = $listItem->shoppingList;
        $listItem->delete();

        return $this->replanAndBack($list);
    }

    public function toggleStaple(CanonicalItem $canonicalItem): RedirectResponse
    {
        $canonicalItem->update(['is_staple' => !$canonicalItem->is_staple]);

        return back();
    }

    public function plan(): RedirectResponse
    {
        PlanService::fromConfig()->plan(ShoppingList::current());

        return back();
    }

    /**
     * Put one item at a store by hand. With `remember`, also pin that store
     * for the item so future lists start there too.
     */
    public function move(Request $request, ListItem $listItem): RedirectResponse
    {
        $data = $request->validate([
            'store_id' => ['required', 'integer', 'exists:stores,id'],
            'remember' => ['boolean'],
        ]);

        DB::transaction(function () use ($listItem, $data) {
            $listItem->assignment()->updateOrCreate([], [
                'store_id' => $data['store_id'],
                'reason' => 'manual',
                'note' => 'Chosen by hand',
            ]);

            if (($data['remember'] ?? false) && $listItem->canonical_item_id) {
                ItemStorePref::where('canonical_item_id', $listItem->canonical_item_id)->delete();
                ItemStorePref::create([
                    'canonical_item_id' => $listItem->canonical_item_id,
                    'store_id' => $data['store_id'],
                    'rank' => 1,
                    'source' => 'manual',
                    'pinned' => true,
                ]);
            }
        });

        // Re-plan fills in the product/price for the chosen store and lets
        // the store the item left re-check its minimum.
        return $this->replanAndBack($listItem->shoppingList);
    }

    /** Ask the sidecar for a stock check; it picks this up on its next poll. */
    public function checkStock(SidecarRuns $runs): RedirectResponse
    {
        $runs->requestCheck();

        return back();
    }

    public function finish(): RedirectResponse
    {
        ShoppingList::current()->update(['status' => 'done']);

        return back();
    }

    private function replanAndBack(ShoppingList $list): RedirectResponse
    {
        if ($list->status === 'planned') {
            PlanService::fromConfig()->plan($list);
        }

        return back();
    }

    private function unplannedReason(ListItem $li, array $options): string
    {
        if ($li->canonical_item_id === null) {
            return 'Typed-in item, not linked to any store. Buy it wherever is convenient.';
        }

        return $options
            ? 'Out of stock at every store that carries it.'
            : 'Never bought at an active store yet. Link a product to it on the Item matching page.';
    }

    private function storeSummaries($lines, $stores, PlanService $service): array
    {
        return $lines->whereNotNull('assignment')
            ->groupBy(fn ($l) => $l['assignment']['store_id'])
            ->map(function ($storeLines, $storeId) use ($stores, $service) {
                $store = $stores[$storeId];
                $subtotal = round($storeLines->sum(fn ($l) => ($l['assignment']['unit_price'] ?? 0) * $l['qty']), 2);
                $unpriced = $storeLines->whereNull('assignment.unit_price')->count();
                $locked = $storeLines->contains(fn ($l) => $l['assignment']['reason'] === 'manual');

                return [
                    'id' => $store->id,
                    'label' => $store->label,
                    'fulfillment' => $store->fulfillment,
                    'subtotal' => $subtotal,
                    'free_delivery_threshold' => $store->free_delivery_threshold === null ? null : (float) $store->free_delivery_threshold,
                    'warnings' => $service->planner()->warnings([
                        'fulfillment' => $store->fulfillment,
                        'free_delivery_threshold' => $store->free_delivery_threshold === null ? null : (float) $store->free_delivery_threshold,
                        'hard_minimum' => $store->hard_minimum === null ? null : (float) $store->hard_minimum,
                        'delivery_fee' => $store->delivery_fee === null ? null : (float) $store->delivery_fee,
                    ], $subtotal, $unpriced, $locked),
                ];
            })
            ->sortByDesc('subtotal')
            ->values()
            ->all();
    }
}
