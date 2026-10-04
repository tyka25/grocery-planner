<?php

namespace App\Services\Planner;

use App\Models\AvailabilitySnapshot;
use App\Models\ItemStorePref;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ShoppingList;
use App\Models\Store;
use App\Models\StoreProduct;
use Illuminate\Support\Collection;

/**
 * Loads a shopping list into the plain arrays Planner works on.
 *
 * Only *confirmed* canonical links count -- an 'auto' suggestion hasn't had
 * a human yes yet, so it never decides where real groceries are bought.
 *
 * Store preference order per item:
 *   1. a pinned item_store_prefs row (lowest rank first)
 *   2. most delivered purchases of the item's products at that store
 *   3. most recently bought there
 * Within a store, its most-bought product for the item comes first.
 */
class PlanInputBuilder
{
    public function __construct(private int $snapshotMaxAgeHours) {}

    /**
     * @return array{lines: array, stores: array<int, array>}
     */
    public function build(ShoppingList $list): array
    {
        $stores = Store::where('enabled', true)->get()->keyBy('id');
        $items = $list->items()->with('assignment')->get();
        $itemIds = $items->pluck('canonical_item_id')->filter()->unique()->values();

        $products = StoreProduct::query()
            ->whereIn('canonical_item_id', $itemIds)
            ->where('match_status', 'confirmed')
            ->whereIn('store_id', $stores->keys())
            ->withCount(['orderLines as purchases' => fn ($q) => $q->where('line_type', 'delivered')])
            ->addSelect(['last_bought' => Order::query()
                ->selectRaw('max(orders.ordered_on)')
                ->join('order_lines', 'order_lines.order_id', '=', 'orders.id')
                ->whereColumn('order_lines.store_product_id', 'store_products.id'),
            ])
            ->get();

        $snapshots = $this->freshSnapshots($products->pluck('id'));
        $lastPaid = $this->lastPaidUnitPrices($products->pluck('id'));
        $pins = ItemStorePref::whereIn('canonical_item_id', $itemIds)->where('pinned', true)->get()
            ->groupBy('canonical_item_id')
            ->map(fn ($rows) => $rows->pluck('rank', 'store_id'));

        $optionsByItem = $products->groupBy('canonical_item_id')->map(
            fn (Collection $ps, $itemId) => $this->options($ps, $pins->get($itemId, collect()), $snapshots, $lastPaid)
        );

        $lines = $items->map(fn ($li) => [
            'list_item_id' => $li->id,
            'qty' => (float) $li->qty,
            'manual_store_id' => $li->assignment?->reason === 'manual' ? $li->assignment->store_id : null,
            'options' => $li->canonical_item_id ? $optionsByItem->get($li->canonical_item_id, []) : [],
        ])->all();

        return [
            'lines' => $lines,
            'stores' => $stores->map(fn (Store $s) => [
                'id' => $s->id,
                'name' => $s->label,
                'fulfillment' => $s->fulfillment,
                'free_delivery_threshold' => $s->free_delivery_threshold === null ? null : (float) $s->free_delivery_threshold,
                'hard_minimum' => $s->hard_minimum === null ? null : (float) $s->hard_minimum,
                'delivery_fee' => $s->delivery_fee === null ? null : (float) $s->delivery_fee,
            ])->all(),
        ];
    }

    private function options(Collection $products, Collection $pinRanks, Collection $snapshots, Collection $lastPaid): array
    {
        $byStore = $products->groupBy('store_id')->map(fn ($ps) => $ps->sortByDesc('purchases')->values());

        $storeOrder = $byStore->keys()->sort(function ($a, $b) use ($byStore, $pinRanks) {
            $pa = $pinRanks->get($a);
            $pb = $pinRanks->get($b);
            if ($pa !== null || $pb !== null) {
                return ($pa ?? PHP_INT_MAX) <=> ($pb ?? PHP_INT_MAX);
            }

            return [$byStore[$b]->sum('purchases'), $byStore[$b]->max('last_bought')]
                <=> [$byStore[$a]->sum('purchases'), $byStore[$a]->max('last_bought')];
        });

        $options = [];
        foreach ($storeOrder as $storeId) {
            foreach ($byStore[$storeId] as $p) {
                $snap = $snapshots->get($p->id);
                $snapPrice = $snap?->price === null ? null : (float) $snap->price;
                $histPrice = $lastPaid->get($p->id);

                $options[] = [
                    'store_id' => $p->store_id,
                    'store_product_id' => $p->id,
                    'pinned' => $pinRanks->has($storeId),
                    'available' => $snap?->available,
                    'unit_price' => $snapPrice ?? $histPrice,
                    'price_source' => $snapPrice !== null ? 'snapshot' : ($histPrice !== null ? 'history' : null),
                ];
            }
        }

        return $options;
    }

    /** Latest snapshot per product, if it's recent enough to trust. */
    private function freshSnapshots(Collection $productIds): Collection
    {
        return AvailabilitySnapshot::query()
            ->whereIn('store_product_id', $productIds)
            ->where('observed_at', '>=', now()->subHours($this->snapshotMaxAgeHours))
            ->orderByDesc('observed_at')
            ->get()
            ->unique('store_product_id')
            ->keyBy('store_product_id');
    }

    /**
     * line_total / qty of the most recent delivered line (line_total is the
     * whole line: 4 pizzas at 6.99 -> 27.96). An estimate for minimum checks.
     */
    private function lastPaidUnitPrices(Collection $productIds): Collection
    {
        return OrderLine::query()
            ->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->whereIn('order_lines.store_product_id', $productIds)
            ->where('order_lines.line_type', 'delivered')
            ->where('order_lines.qty', '>', 0)
            ->where('order_lines.line_total', '>', 0)
            ->orderByDesc('orders.ordered_on')
            ->get(['order_lines.store_product_id', 'order_lines.line_total', 'order_lines.qty'])
            ->unique('store_product_id')
            ->mapWithKeys(fn ($l) => [$l->store_product_id => round($l->line_total / $l->qty, 2)]);
    }
}
