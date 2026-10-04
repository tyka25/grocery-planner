<?php

namespace App\Services\InstacartImport;

/**
 * Parses the two Instacart CSV exports (Order History, Purchased Items) into
 * normalized rows ready to upsert into the orders / order_lines / store_products
 * tables. Framework-agnostic on purpose: this class has no Eloquent/Laravel
 * dependency, so it can be unit tested (and was validated) with plain `php`
 * outside the full application.
 *
 * Rules encoded here were verified against the user's real exports:
 *  - Order History: data starts at the row beginning "Order Type,Order ID";
 *    everything above is a preamble block, everything below the last data
 *    row is a totals footer. Only "Delivered" and "Partial Refund" rows are
 *    orders; a trailing totals row is not.
 *  - Purchased Items: only "Delivered" and "Refund" rows are line items; a
 *    "Total Items" footer row is not. There are no duplicate
 *    (order, item, type) keys.
 *  - Order IDs in both files have a leading apostrophe (Excel text-escaping);
 *    strip it. IDs are kept as strings everywhere -- never cast to a number.
 *  - Line prices do NOT reliably reconcile against the order subtotal
 *    (verified: only 32 of 53 orders matched within 2 cents), so order money
 *    fields come only from the Order History file and line amounts are kept
 *    for reference only, never summed into spend totals.
 *  - A delivered line with a negative Price Paid does NOT reliably mean the
 *    item was uncharged (verified against two real orders: the negative
 *    lines were billed in full as part of the order total). It's stored as
 *    'negative_unverified', not treated as an out-of-stock signal.
 *  - Shipping Address identifies the fulfillment location. The caller
 *    supplies a $locationResolver (address substring -> location config)
 *    because address-to-location mapping is a one-time human judgement call,
 *    not something to infer blindly (e.g. a Fareway address is a pickup
 *    site, not a delivery address, even though it's shaped like one).
 */
class Importer
{
    /** @var array<string, array{kind: string, fulfillment: string, store_slug_hint: ?string}> */
    private array $locationResolver;

    /**
     * @param array<string, array{kind: string, fulfillment: string, store_slug_hint: ?string}> $locationResolver
     *   Map of an address substring to how to treat it, e.g.:
     *   ['123 Main' => ['kind' => 'home', 'fulfillment' => 'delivery', 'store_slug_hint' => null],
     *    'Market Road' => ['kind' => 'pickup_site', 'fulfillment' => 'pickup', 'store_slug_hint' => 'fareway-meat-grocery'],
     *    'Seaside' => ['kind' => 'travel', 'fulfillment' => 'delivery', 'store_slug_hint' => null]]
     */
    public function __construct(array $locationResolver)
    {
        $this->locationResolver = $locationResolver;
    }

    /** @return array{orders: array<int, array<string, mixed>>, unresolved_addresses: array<int, string>} */
    public function parseOrderHistory(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        $headerIdx = null;
        foreach ($lines as $i => $line) {
            if (str_starts_with($line, 'Order Type,Order ID')) {
                $headerIdx = $i;
                break;
            }
        }
        if ($headerIdx === null) {
            throw new \RuntimeException("Could not find the 'Order Type,Order ID' header row in {$path}");
        }

        $csv = implode("\n", array_slice($lines, $headerIdx));
        $rows = $this->readCsvString($csv);

        $orders = [];
        $unresolved = [];
        foreach ($rows as $row) {
            if (!in_array($row['Order Type'] ?? null, ['Delivered', 'Partial Refund'], true)) {
                continue; // skips the totals footer row and any blank rows
            }

            $address = trim($row['Shipping Address'] ?? '');
            $loc = $this->resolveAddress($address);
            if ($loc === null && $address !== '') {
                $unresolved[] = $address;
            }

            $orders[] = [
                'instacart_order_id' => ltrim($row['Order ID'] ?? '', "'"),
                'ordered_on' => $this->parseDate($row['Order Date'] ?? ''),
                'status' => $row['Order Type'] ?? '',
                'subtotal' => $this->money($row['Sub Total'] ?? null),
                'promotion' => $this->money($row['Promotion'] ?? null),
                'coupon' => $this->money($row['Coupon'] ?? null),
                'fees' => $this->money($row['Additional Fees'] ?? null),
                'tax' => $this->money($row['VAT (TAX)'] ?? null),
                'total' => $this->money($row['Grand Total'] ?? null),
                'refund_total' => $this->money($row['Refund Total'] ?? null),
                'order_url' => $row['Order URL'] ?? null,
                'shipping_address' => $address,
                'location_kind' => $loc['kind'] ?? null,
                'fulfillment_mode' => $loc['fulfillment'] ?? 'delivery',
                'store_slug_hint' => $loc['store_slug_hint'] ?? null,
            ];
        }

        return ['orders' => $orders, 'unresolved_addresses' => array_values(array_unique($unresolved))];
    }

    /** @return array<int, array<string, mixed>> */
    public function parsePurchasedItems(string $path): array
    {
        $raw = file_get_contents($path);
        $rows = $this->readCsvString($raw);

        $lines = [];
        $seen = []; // (order, product, type) -> occurrence count, for the unique key
        foreach ($rows as $row) {
            $type = $row['Product Order Type'] ?? '';
            if (!in_array($type, ['Delivered', 'Refund'], true)) {
                continue; // drops the "Total Items" / totals footer rows
            }

            $oid = ltrim($row['Order ID'] ?? '', "'");
            $productId = $row['Item Number/ASIN'] ?? '';
            $key = $oid . '|' . $productId . '|' . $type;
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            $qty = (float) ($row['Product Quantity'] ?? 0);
            $price = (float) ($row['Product Price'] ?? 0);
            $paidRaw = (float) ($row['Price Paid (Before-Tax)'] ?? 0);

            $lineType = $type === 'Refund' ? 'refunded' : 'ok';
            if ($lineType === 'ok' && $paidRaw < 0) {
                $lineType = 'negative_unverified';
            }

            $lines[] = [
                'instacart_order_id' => $oid,
                'store_slug' => $row['Store Name'] ?? null,
                'instacart_product_id' => (string) $productId,
                'description' => $row['Product Description'] ?? '',
                'image_url' => $row['Product Image'] ?? null,
                'line_type' => $type === 'Refund' ? 'refund' : 'delivered',
                'occurrence' => $seen[$key],
                'qty' => $qty,
                'line_total' => $price,
                'paid_raw' => $paidRaw,
                'outcome' => $lineType,
                'ordered_on' => $this->parseDate($row['Order Date'] ?? ''),
            ];
        }

        return $lines;
    }

    private function resolveAddress(string $address): ?array
    {
        foreach ($this->locationResolver as $needle => $config) {
            if ($needle !== '' && str_contains($address, $needle)) {
                return $config;
            }
        }

        return null;
    }

    private function money(null|string|int|float $v): ?float
    {
        if ($v === null || $v === '') {
            return null;
        }

        return round((float) $v, 2);
    }

    private function parseDate(string $v): ?string
    {
        if ($v === '') {
            return null;
        }
        $d = \DateTime::createFromFormat('M d, Y', trim($v));

        return $d ? $d->format('Y-m-d') : null;
    }

    /** @return array<int, array<string, string>> */
    private function readCsvString(string $csv): array
    {
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);

        $header = fgetcsv($fh);
        $rows = [];
        while (($data = fgetcsv($fh)) !== false) {
            if (count($data) === 1 && $data[0] === null) {
                continue; // blank line
            }
            $row = [];
            foreach ($header as $i => $col) {
                $row[trim((string) $col)] = $data[$i] ?? null;
            }
            $rows[] = $row;
        }
        fclose($fh);

        return $rows;
    }
}
