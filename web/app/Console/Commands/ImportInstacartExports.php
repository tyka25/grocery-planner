<?php

namespace App\Console\Commands;

use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Services\InstacartImport\Importer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ImportInstacartExports extends Command
{
    protected $signature = 'instacart:import
        {order_history : Path to the "Order History" CSV export}
        {purchased_items : Path to the "Purchased Items" CSV export}
        {--dry-run : Report what would happen without writing to the database}';

    protected $description = 'Import Instacart order-history and purchased-items CSV exports (idempotent: safe to re-run on overlapping exports)';

    public function handle(): int
    {
        $importer = new Importer(config('grocery_planner.location_resolver'));

        $orderHistoryPath = $this->argument('order_history');
        $purchasedItemsPath = $this->argument('purchased_items');

        $oh = $importer->parseOrderHistory($orderHistoryPath);
        $lines = $importer->parsePurchasedItems($purchasedItemsPath);

        if (!empty($oh['unresolved_addresses'])) {
            $this->warn('Unresolved shipping addresses (add these to config/grocery_planner.php location_resolver):');
            foreach ($oh['unresolved_addresses'] as $addr) {
                $this->line("  - {$addr}");
            }
        }

        $this->info(sprintf(
            'Parsed %d orders and %d line items.',
            count($oh['orders']),
            count($lines)
        ));

        if ($this->option('dry-run')) {
            $byKind = [];
            foreach ($oh['orders'] as $o) {
                $byKind[$o['location_kind'] ?? 'unresolved'] = ($byKind[$o['location_kind'] ?? 'unresolved'] ?? 0) + 1;
            }
            $this->table(['location_kind', 'orders'], collect($byKind)->map(fn ($n, $k) => [$k, $n])->values());

            return self::SUCCESS;
        }

        DB::transaction(function () use ($oh, $lines) {
            // Locations: one row per distinct (address, kind) combination seen.
            $locationIds = [];
            foreach ($oh['orders'] as $o) {
                $addr = $o['shipping_address'];
                if ($addr === '' || isset($locationIds[$addr])) {
                    continue;
                }
                $resolved = collect(config('grocery_planner.location_resolver'))
                    ->first(fn ($cfg, $needle) => $needle !== '' && str_contains($addr, $needle));

                $location = Location::updateOrCreate(
                    ['address' => $addr],
                    [
                        'label' => $resolved['label'] ?? $addr,
                        'kind' => $o['location_kind'] ?? 'home',
                        'import_enabled' => ($o['location_kind'] ?? 'home') !== 'travel',
                    ]
                );
                $locationIds[$addr] = $location->id;
            }

            // Stores: keyed by the retailer slug seen in the Purchased Items file.
            $storeIds = [];
            foreach ($lines as $l) {
                $slug = $l['store_slug'];
                if (!$slug || isset($storeIds[$slug])) {
                    continue;
                }
                // Find a location whose resolver hint matches this slug (pickup sites),
                // otherwise fall back to the home location.
                $homeAddr = collect($oh['orders'])->firstWhere('location_kind', 'home')['shipping_address'] ?? null;
                $pickupMatch = collect(config('grocery_planner.location_resolver'))
                    ->first(fn ($cfg) => ($cfg['store_slug_hint'] ?? null) === $slug);

                $locationId = $pickupMatch
                    ? ($locationIds[array_search($pickupMatch, config('grocery_planner.location_resolver'))] ?? ($locationIds[$homeAddr] ?? null))
                    : ($locationIds[$homeAddr] ?? null);

                $store = Store::updateOrCreate(
                    ['retailer_slug' => $slug],
                    [
                        'name' => $slug,
                        'fulfillment' => $pickupMatch ? 'pickup' : 'delivery',
                        'location_id' => $locationId,
                    ]
                );
                $storeIds[$slug] = $store->id;
            }

            // Orders.
            $orderIds = [];
            foreach ($oh['orders'] as $o) {
                $order = Order::updateOrCreate(
                    ['instacart_order_id' => $o['instacart_order_id']],
                    [
                        'location_id' => $locationIds[$o['shipping_address']] ?? null,
                        'fulfillment_mode' => $o['fulfillment_mode'],
                        'ordered_on' => $o['ordered_on'],
                        'status' => $o['status'],
                        'subtotal' => $o['subtotal'],
                        'promotion' => $o['promotion'],
                        'coupon' => $o['coupon'],
                        'fees' => $o['fees'],
                        'tax' => $o['tax'],
                        'total' => $o['total'],
                        'refund_total' => $o['refund_total'],
                        'order_url' => $o['order_url'],
                    ]
                );
                $orderIds[$o['instacart_order_id']] = $order->id;
            }

            // Store products + order lines + backfill order.store_id from the line's store.
            $orderStoreSeen = [];
            foreach ($lines as $l) {
                $storeId = $storeIds[$l['store_slug']] ?? null;
                if (!$storeId) {
                    continue;
                }

                $product = StoreProduct::updateOrCreate(
                    ['store_id' => $storeId, 'instacart_product_id' => $l['instacart_product_id']],
                    [
                        'description' => $l['description'],
                        'image_url' => $l['image_url'],
                        'first_seen_on' => $l['ordered_on'],
                        'last_seen_on' => $l['ordered_on'],
                    ]
                );
                // Keep first/last seen accurate across repeated imports.
                if ($l['ordered_on']) {
                    if (!$product->first_seen_on || $l['ordered_on'] < $product->first_seen_on->format('Y-m-d')) {
                        $product->first_seen_on = $l['ordered_on'];
                    }
                    if (!$product->last_seen_on || $l['ordered_on'] > $product->last_seen_on->format('Y-m-d')) {
                        $product->last_seen_on = $l['ordered_on'];
                    }
                    $product->save();
                }

                $orderId = $orderIds[$l['instacart_order_id']] ?? null;
                if (!$orderId) {
                    continue;
                }

                if (!isset($orderStoreSeen[$orderId])) {
                    Order::whereKey($orderId)->update(['store_id' => $storeId]);
                    $orderStoreSeen[$orderId] = true;
                }

                OrderLine::updateOrCreate(
                    [
                        'order_id' => $orderId,
                        'store_product_id' => $product->id,
                        'line_type' => $l['line_type'],
                        'occurrence' => $l['occurrence'],
                    ],
                    [
                        'qty' => $l['qty'],
                        'line_total' => $l['line_total'],
                        'paid_raw' => $l['paid_raw'],
                        'outcome' => $l['outcome'],
                    ]
                );
            }
        });

        $this->info('Import complete.');

        return self::SUCCESS;
    }
}
