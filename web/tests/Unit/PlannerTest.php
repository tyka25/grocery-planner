<?php

namespace Tests\Unit;

use App\Services\Planner\Planner;
use PHPUnit\Framework\TestCase;

/** Plain PHPUnit: Planner is pure. Store setup mirrors the household's real ones. */
class PlannerTest extends TestCase
{
    private const HYVEE = 1;
    private const COSTCO = 2;
    private const FAREWAY = 3;

    private function stores(array $overrides = []): array
    {
        $stores = [
            self::HYVEE => ['id' => self::HYVEE, 'name' => 'Hy-Vee', 'fulfillment' => 'delivery', 'free_delivery_threshold' => 10.0, 'hard_minimum' => null, 'delivery_fee' => null],
            self::COSTCO => ['id' => self::COSTCO, 'name' => 'Costco', 'fulfillment' => 'delivery', 'free_delivery_threshold' => 35.0, 'hard_minimum' => null, 'delivery_fee' => null],
            self::FAREWAY => ['id' => self::FAREWAY, 'name' => 'Fareway', 'fulfillment' => 'pickup', 'free_delivery_threshold' => null, 'hard_minimum' => null, 'delivery_fee' => null],
        ];
        foreach ($overrides as $id => $o) {
            $stores[$id] = $o + $stores[$id];
        }

        return $stores;
    }

    private function opt(int $store, ?float $price = null, ?bool $available = null, bool $pinned = false): array
    {
        static $productId = 100;

        return ['store_id' => $store, 'store_product_id' => $productId++, 'pinned' => $pinned, 'available' => $available, 'unit_price' => $price, 'price_source' => $price === null ? null : 'history'];
    }

    private function line(int $id, array $options, float $qty = 1, ?int $manual = null): array
    {
        return ['list_item_id' => $id, 'qty' => $qty, 'manual_store_id' => $manual, 'options' => $options];
    }

    private function plan(array $lines, array $stores = null): array
    {
        return (new Planner(pickupTripCost: 10.0, assumedDeliveryFee: 3.99))->plan($lines, $stores ?? $this->stores());
    }

    public function test_items_go_to_their_usual_store(): void
    {
        $r = $this->plan([
            $this->line(1, [$this->opt(self::HYVEE, 12.0), $this->opt(self::COSTCO, 9.0)]),
        ]);

        $this->assertSame(self::HYVEE, $r['assignments'][1]['store_id']);
        $this->assertSame('preferred', $r['assignments'][1]['reason']);
        $this->assertSame('Usual store', $r['assignments'][1]['note']);
    }

    public function test_out_of_stock_falls_back_and_unknown_stock_does_not(): void
    {
        $r = $this->plan([
            $this->line(1, [$this->opt(self::HYVEE, 12.0, available: false), $this->opt(self::COSTCO, 40.0, available: true)]),
            $this->line(2, [$this->opt(self::HYVEE, 12.0, available: null), $this->opt(self::COSTCO, 40.0)]),
        ]);

        $this->assertSame(self::COSTCO, $r['assignments'][1]['store_id']);
        $this->assertSame('fallback', $r['assignments'][1]['reason']);
        $this->assertSame('Hy-Vee is out of stock', $r['assignments'][1]['note']);
        $this->assertSame(self::HYVEE, $r['assignments'][2]['store_id']);
    }

    public function test_unplaceable_items_are_reported_not_dropped_silently(): void
    {
        $r = $this->plan([
            $this->line(1, []),
            $this->line(2, [$this->opt(self::HYVEE, 3.0, available: false)]),
        ]);

        $this->assertSame([], $r['assignments']);
        $this->assertStringContainsString('Not linked', $r['unassigned'][1]);
        $this->assertStringContainsString('Out of stock', $r['unassigned'][2]);
    }

    public function test_small_costco_order_is_folded_into_hyvee_when_hyvee_is_already_in_the_plan(): void
    {
        $r = $this->plan([
            $this->line(1, [$this->opt(self::HYVEE, 15.0)]),
            $this->line(2, [$this->opt(self::COSTCO, 8.0), $this->opt(self::HYVEE, 8.5)]),
        ]);

        $this->assertSame(self::HYVEE, $r['assignments'][2]['store_id']);
        $this->assertSame('minimum_repair', $r['assignments'][2]['reason']);
        $this->assertStringContainsString('Moved from Costco', $r['assignments'][2]['note']);
        $this->assertSame([self::HYVEE], array_keys($r['stores']));
    }

    public function test_paying_the_fee_wins_when_moving_costs_more_in_price(): void
    {
        // Moving 3 items costs 3 x $2 = $6 more than the ~$3.99 fee.
        $r = $this->plan([
            $this->line(1, [$this->opt(self::HYVEE, 15.0)]),
            $this->line(2, [$this->opt(self::COSTCO, 5.0), $this->opt(self::HYVEE, 7.0)], qty: 3),
        ]);

        $this->assertSame(self::COSTCO, $r['assignments'][2]['store_id']);
        $this->assertSame('preferred', $r['assignments'][2]['reason']);
        $this->assertStringContainsString('short of free delivery', $r['stores'][self::COSTCO]['warnings'][0]);
    }

    public function test_a_fareway_trip_is_not_worth_it_just_to_dodge_a_delivery_fee(): void
    {
        // $10 trip > $3.99 fee.
        $r = $this->plan([
            $this->line(1, [$this->opt(self::COSTCO, 8.0), $this->opt(self::FAREWAY, 8.0)]),
        ]);

        $this->assertSame(self::COSTCO, $r['assignments'][1]['store_id']);
        $this->assertArrayNotHasKey(self::FAREWAY, $r['stores']);
    }

    public function test_a_fareway_trip_is_used_when_it_beats_the_fee(): void
    {
        $r = $this->plan(
            [$this->line(1, [$this->opt(self::COSTCO, 8.0), $this->opt(self::FAREWAY, 8.0)])],
            $this->stores([self::COSTCO => ['delivery_fee' => 12.0]]),
        );

        $this->assertSame(self::FAREWAY, $r['assignments'][1]['store_id']);
        $this->assertStringContainsString('adds a Fareway pickup', $r['assignments'][1]['note']);
    }

    public function test_a_small_fareway_order_is_folded_into_a_store_already_in_the_plan(): void
    {
        // Real case: a lone $6.99 pizza whose usual store is Fareway.
        $r = $this->plan([
            $this->line(1, [$this->opt(self::HYVEE, 15.0)]),
            $this->line(2, [$this->opt(self::FAREWAY, 6.99), $this->opt(self::HYVEE, 7.49)]),
        ]);

        $this->assertSame(self::HYVEE, $r['assignments'][2]['store_id']);
        $this->assertStringContainsString('saves a separate pickup trip', $r['assignments'][2]['note']);
    }

    public function test_a_fareway_trip_stays_when_fareway_is_the_only_source_and_gets_no_warning(): void
    {
        $r = $this->plan([
            $this->line(1, [$this->opt(self::HYVEE, 15.0)]),
            $this->line(2, [$this->opt(self::FAREWAY, 6.99)]),
        ]);

        $this->assertSame(self::FAREWAY, $r['assignments'][2]['store_id']);
        $this->assertSame([], $r['stores'][self::FAREWAY]['warnings']);
    }

    public function test_a_hard_minimum_must_be_fixed_if_anywhere_can_take_the_items(): void
    {
        $r = $this->plan(
            [$this->line(1, [$this->opt(self::COSTCO, 8.0), $this->opt(self::FAREWAY, 9.0)])],
            $this->stores([self::COSTCO => ['hard_minimum' => 30.0]]),
        );

        $this->assertSame(self::FAREWAY, $r['assignments'][1]['store_id']);
    }

    public function test_hand_chosen_items_are_never_moved_and_their_store_gets_a_warning(): void
    {
        $r = $this->plan([
            $this->line(1, [$this->opt(self::HYVEE, 15.0)]),
            $this->line(2, [$this->opt(self::HYVEE, 8.5), $this->opt(self::COSTCO, 8.0)], manual: self::COSTCO),
        ]);

        $this->assertSame(self::COSTCO, $r['assignments'][2]['store_id']);
        $this->assertSame('manual', $r['assignments'][2]['reason']);
        $this->assertContains('Not re-routed because some items here were chosen by hand or pinned.', $r['stores'][self::COSTCO]['warnings']);
    }

    public function test_unpriced_items_are_flagged_since_the_total_is_a_lower_bound(): void
    {
        $r = $this->plan([$this->line(1, [$this->opt(self::HYVEE, null)]), $this->line(2, [$this->opt(self::HYVEE, 20.0)])]);

        $this->assertSame(20.0, $r['stores'][self::HYVEE]['subtotal']);
        $this->assertSame(1, $r['stores'][self::HYVEE]['unpriced']);
        $this->assertStringContainsString('1 item with no price estimate', implode(' ', $r['stores'][self::HYVEE]['warnings']));
    }
}
