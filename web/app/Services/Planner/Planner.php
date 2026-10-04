<?php

namespace App\Services\Planner;

/**
 * Decides which store each shopping-list line goes to. No framework or
 * database dependency (PlanInputBuilder does the loading), so the rules
 * below are unit-tested directly.
 *
 * Objective: predictability over savings. Each line goes to its usual store
 * (pinned store, else where it's been bought most); it only moves when
 *   1. that store is out of stock (per a fresh snapshot) -> 'fallback', or
 *   2. that store's order misses a minimum               -> 'minimum_repair'.
 *
 * Minimum repair, smallest order first. A store's order is a problem if it's
 * under its hard minimum (must fix), or for delivery under its free-delivery
 * threshold (costs the delivery fee), or is a pickup order at all (costs the
 * trip). The cheapest of these wins:
 *   - keep it and pay the fee / make the trip     (not allowed for a hard minimum)
 *   - move its lines to stores already in the plan (cost: price difference, when known)
 *   - move them to a pickup store not yet in the plan (cost: pickup trip + price difference)
 * Lines chosen by hand or at a pinned store are never moved; a store holding
 * any of them is left as is and gets a warning instead.
 *
 * Prices are estimates (snapshot price or last-paid unit price) used only
 * to compare against thresholds -- never as real spend.
 *
 * Input shapes:
 *   $stores: [store_id => [id, name, fulfillment, free_delivery_threshold, hard_minimum, delivery_fee]]
 *   $lines:  [[list_item_id, qty, manual_store_id, options => [[store_id, store_product_id,
 *              pinned, available (?bool), unit_price (?float), price_source (?string)], ...]], ...]
 *            options are in preference order (best first).
 */
class Planner
{
    public function __construct(
        private float $pickupTripCost,
        private float $assumedDeliveryFee,
    ) {}

    /**
     * @return array{
     *   assignments: array<int, array{store_id: int, store_product_id: int, reason: string, note: string, unit_price: ?float, price_source: ?string, available: ?bool}>,
     *   unassigned: array<int, string>,
     *   stores: array<int, array{subtotal: float, unpriced: int, items: int, warnings: string[]}>
     * }
     */
    public function plan(array $lines, array $stores): array
    {
        $lines = array_column($lines, null, 'list_item_id');
        $assignments = [];
        $unassigned = [];

        foreach ($lines as $id => $line) {
            $result = $this->initial($line, $stores);
            if (is_string($result)) {
                $unassigned[$id] = $result;
            } else {
                $assignments[$id] = $result;
            }
        }

        // Each successful repair removes a store from the plan, so this ends
        // within count($stores) passes; the guard is belt and braces.
        for ($pass = 0; $pass <= count($stores); $pass++) {
            if (!$this->repairOne($assignments, $lines, $stores)) {
                break;
            }
        }

        return [
            'assignments' => $assignments,
            'unassigned' => $unassigned,
            'stores' => $this->summaries($assignments, $lines, $stores),
        ];
    }

    /**
     * Warnings for one store's order, given its estimated totals. Public so
     * the list page can show the same wording for a saved plan.
     *
     * @return string[]
     */
    public function warnings(array $store, float $subtotal, int $unpriced, bool $hasLockedLines = false): array
    {
        $warnings = [];
        $problem = $this->problem($store, $subtotal);
        if ($problem && $problem['kind'] === 'trip') {
            $problem = null; // a pickup trip that survived repair is the best option, not a problem
        }

        if ($problem && $problem['kind'] === 'hard') {
            $warnings[] = sprintf('Order is about $%.2f, under the $%.2f minimum. Add items or move these elsewhere.', $subtotal, $problem['target']);
        } elseif ($problem) {
            $warnings[] = sprintf('Order is about $%.2f, $%.2f short of free delivery ($%.2f). Expect a delivery fee (~$%.2f).',
                $subtotal, $problem['target'] - $subtotal, $problem['target'], $problem['cost']);
        }
        if ($problem && $hasLockedLines) {
            $warnings[] = 'Not re-routed because some items here were chosen by hand or pinned.';
        }
        if ($unpriced > 0) {
            $warnings[] = sprintf('%d item%s with no price estimate, so the real total is higher.', $unpriced, $unpriced === 1 ? '' : 's');
        }

        return $warnings;
    }

    /** @return array|string assignment, or the reason it can't be placed */
    private function initial(array $line, array $stores): array|string
    {
        $options = $line['options'];
        if (!$options) {
            return 'Not linked to any store product yet. Link it on the Item matching page.';
        }

        if ($line['manual_store_id'] !== null) {
            $manual = $this->firstOption($options, fn ($o) => $o['store_id'] === $line['manual_store_id'] && $o['available'] !== false)
                ?? $this->firstOption($options, fn ($o) => $o['store_id'] === $line['manual_store_id']);
            if ($manual) {
                return $this->assign($manual, 'manual', 'Chosen by hand');
            }
        }

        $usable = $this->firstOption($options, fn ($o) => $o['available'] !== false);
        if (!$usable) {
            return 'Out of stock at every store that carries it.';
        }

        $top = $options[0];
        if ($usable['store_id'] === $top['store_id']) {
            return $this->assign($usable, 'preferred', $usable['pinned'] ? 'Always bought here' : 'Usual store');
        }

        return $this->assign($usable, 'fallback', $this->storeName($stores, $top['store_id']).' is out of stock');
    }

    /** Fix the smallest problem order, if any can be fixed. Returns whether anything moved. */
    private function repairOne(array &$assignments, array $lines, array $stores): bool
    {
        $byStore = $this->groupByStore($assignments);
        $totals = array_map(fn ($ids) => $this->subtotal($ids, $assignments, $lines)['subtotal'], $byStore);
        asort($totals);

        foreach ($totals as $storeId => $subtotal) {
            $problem = $this->problem($stores[$storeId], $subtotal);
            if (!$problem) {
                continue;
            }

            $lineIds = $byStore[$storeId];
            if ($this->hasLockedLines($lineIds, $assignments)) {
                continue;
            }

            $best = ['cost' => $problem['cost'], 'moves' => null, 'via' => null];
            $inPlan = array_values(array_diff(array_keys($byStore), [$storeId]));

            $destinations = [[$inPlan, 0.0, null]];
            foreach ($stores as $s) {
                if ($s['fulfillment'] === 'pickup' && $s['id'] !== $storeId && !isset($byStore[$s['id']])) {
                    $destinations[] = [[...$inPlan, $s['id']], $this->pickupTripCost, $s['id']];
                }
            }

            foreach ($destinations as [$allowed, $extra, $via]) {
                $moves = $this->movesTo($lineIds, $allowed, $assignments, $lines);
                if ($moves === null) {
                    continue;
                }
                $cost = $extra + $moves['price_diff'];
                if ($cost < $best['cost']) {
                    $best = ['cost' => $cost, 'moves' => $moves['moves'], 'via' => $via];
                }
            }

            if ($best['moves'] === null) {
                continue; // paying the fee (or living with it) is cheapest
            }

            $note = $problem['kind'] === 'trip'
                ? sprintf('Moved from %s: saves a separate pickup trip for ~$%.2f of items', $this->storeName($stores, $storeId), $subtotal)
                : sprintf('Moved from %s: that order (~$%.2f) was under its $%.2f %s', $this->storeName($stores, $storeId), $subtotal,
                    $problem['target'], $problem['kind'] === 'hard' ? 'minimum' : 'free-delivery threshold');
            if ($best['via'] !== null) {
                $note .= sprintf('; adds a %s pickup', $this->storeName($stores, $best['via']));
            }
            foreach ($best['moves'] as $lineId => $option) {
                $assignments[$lineId] = $this->assign($option, 'minimum_repair', $note);
            }

            return true;
        }

        return false;
    }

    /** @return ?array{moves: array<int, array>, price_diff: float} null if some line has nowhere to go */
    private function movesTo(array $lineIds, array $allowedStores, array $assignments, array $lines): ?array
    {
        $moves = [];
        $diff = 0.0;

        foreach ($lineIds as $lineId) {
            $alt = $this->firstOption($lines[$lineId]['options'],
                fn ($o) => in_array($o['store_id'], $allowedStores, true) && $o['available'] !== false);
            if (!$alt) {
                return null;
            }

            $current = $assignments[$lineId]['unit_price'];
            if ($current !== null && $alt['unit_price'] !== null) {
                $diff += ($alt['unit_price'] - $current) * $lines[$lineId]['qty'];
            }
            $moves[$lineId] = $alt;
        }

        return ['moves' => $moves, 'price_diff' => $diff];
    }

    /** @return ?array{kind: string, target: float, cost: float} */
    private function problem(array $store, float $subtotal): ?array
    {
        if ($store['hard_minimum'] !== null && $subtotal < $store['hard_minimum']) {
            return ['kind' => 'hard', 'target' => (float) $store['hard_minimum'], 'cost' => INF];
        }
        if ($store['fulfillment'] === 'delivery' && $store['free_delivery_threshold'] !== null && $subtotal < $store['free_delivery_threshold']) {
            return ['kind' => 'fee', 'target' => (float) $store['free_delivery_threshold'], 'cost' => (float) ($store['delivery_fee'] ?? $this->assumedDeliveryFee)];
        }
        // A pickup store in the plan always costs its trip, so a small pickup
        // order gets folded into stores already in the plan when that's
        // cheaper (on real data: a lone $6.99 pizza made its own Fareway trip).
        if ($store['fulfillment'] === 'pickup') {
            return ['kind' => 'trip', 'target' => 0.0, 'cost' => $this->pickupTripCost];
        }

        return null;
    }

    private function summaries(array $assignments, array $lines, array $stores): array
    {
        $out = [];
        foreach ($this->groupByStore($assignments) as $storeId => $lineIds) {
            $t = $this->subtotal($lineIds, $assignments, $lines);
            $out[$storeId] = $t + [
                'items' => count($lineIds),
                'warnings' => $this->warnings($stores[$storeId], $t['subtotal'], $t['unpriced'], $this->hasLockedLines($lineIds, $assignments)),
            ];
        }

        return $out;
    }

    /** @return array{subtotal: float, unpriced: int} */
    private function subtotal(array $lineIds, array $assignments, array $lines): array
    {
        $subtotal = 0.0;
        $unpriced = 0;
        foreach ($lineIds as $id) {
            $price = $assignments[$id]['unit_price'];
            if ($price === null) {
                $unpriced++;
            } else {
                $subtotal += $price * $lines[$id]['qty'];
            }
        }

        return ['subtotal' => round($subtotal, 2), 'unpriced' => $unpriced];
    }

    private function hasLockedLines(array $lineIds, array $assignments): bool
    {
        foreach ($lineIds as $id) {
            if ($assignments[$id]['reason'] === 'manual' || $assignments[$id]['pinned']) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, int[]> store_id => list_item_ids */
    private function groupByStore(array $assignments): array
    {
        $by = [];
        foreach ($assignments as $lineId => $a) {
            $by[$a['store_id']][] = $lineId;
        }

        return $by;
    }

    private function assign(array $option, string $reason, string $note): array
    {
        return [
            'store_id' => $option['store_id'],
            'store_product_id' => $option['store_product_id'],
            'reason' => $reason,
            'note' => $note,
            'unit_price' => $option['unit_price'],
            'price_source' => $option['price_source'],
            'available' => $option['available'],
            'pinned' => $option['pinned'],
        ];
    }

    private function firstOption(array $options, callable $match): ?array
    {
        foreach ($options as $o) {
            if ($match($o)) {
                return $o;
            }
        }

        return null;
    }

    private function storeName(array $stores, int $id): string
    {
        return $stores[$id]['name'] ?? "store #{$id}";
    }
}
