<?php

namespace Tests\Unit;

use App\Services\InstacartImport\Importer;
use PHPUnit\Framework\TestCase;

/**
 * Deliberately a plain PHPUnit TestCase (not Laravel's) -- the Importer class
 * has no framework dependency, so these run without booting the app or a
 * database. Fixtures under tests/Fixtures are small, hand-picked excerpts of
 * the real exports (one home delivery, one home partial refund, one Fareway
 * pickup, one travel/Florida order) covering every location kind and line
 * outcome the import logic distinguishes.
 */
class InstacartImportTest extends TestCase
{
    private function importer(): Importer
    {
        $config = require __DIR__.'/../../config/grocery_planner.php';

        return new Importer($config['location_resolver']);
    }

    private function fixture(string $name): string
    {
        return __DIR__.'/../Fixtures/'.$name;
    }

    public function test_order_history_resolves_every_known_location_kind(): void
    {
        $result = $this->importer()->parseOrderHistory($this->fixture('order_history_sample.csv'));

        $this->assertCount(4, $result['orders']);
        $this->assertSame([], $result['unresolved_addresses']);

        $byId = collect($result['orders'])->keyBy('instacart_order_id');
        $this->assertSame('home', $byId['21328956422496480']['location_kind']);
        $this->assertSame('delivery', $byId['21328956422496480']['fulfillment_mode']);

        $this->assertSame('pickup_site', $byId['21311852541498428']['location_kind']);
        $this->assertSame('pickup', $byId['21311852541498428']['fulfillment_mode']);

        $this->assertSame('travel', $byId['19489085054493748']['location_kind']);
    }

    public function test_an_unmapped_address_is_reported_not_guessed(): void
    {
        $importer = new Importer(['123 Main' => ['kind' => 'home', 'fulfillment' => 'delivery', 'store_slug_hint' => null]]);
        $result = $importer->parseOrderHistory($this->fixture('order_history_sample.csv'));

        // Only the home address matches this deliberately incomplete resolver;
        // the other three addresses in the fixture must come back unresolved.
        $this->assertCount(3, $result['unresolved_addresses']);
        $unresolvedOrder = collect($result['orders'])->firstWhere('instacart_order_id', '21311852541498428');
        $this->assertNull($unresolvedOrder['location_kind']);
    }

    public function test_purchased_items_classifies_line_outcomes(): void
    {
        $lines = $this->importer()->parsePurchasedItems($this->fixture('purchased_items_sample.csv'));

        $this->assertCount(40, $lines);

        $byOutcome = collect($lines)->countBy('outcome');
        $this->assertSame(35, $byOutcome['ok']);
        $this->assertSame(4, $byOutcome['refunded']);
        $this->assertSame(1, $byOutcome['negative_unverified']);

        // A refund line is never marked negative_unverified -- those two
        // outcomes describe different things (an explicit refund row vs. a
        // delivered line with an unexplained negative amount) and must not
        // collapse into one bucket.
        $refunded = collect($lines)->where('outcome', 'refunded');
        $this->assertTrue($refunded->every(fn ($l) => $l['line_type'] === 'refund'));
    }

    public function test_line_keys_are_unique_per_order_product_type(): void
    {
        $lines = $this->importer()->parsePurchasedItems($this->fixture('purchased_items_sample.csv'));

        $keys = collect($lines)->map(
            fn ($l) => $l['instacart_order_id'].'|'.$l['instacart_product_id'].'|'.$l['line_type'].'|'.$l['occurrence']
        );

        $this->assertCount($keys->count(), $keys->unique());
    }

    public function test_order_ids_keep_their_leading_apostrophe_stripped_and_stay_strings(): void
    {
        $result = $this->importer()->parseOrderHistory($this->fixture('order_history_sample.csv'));

        foreach ($result['orders'] as $order) {
            $this->assertIsString($order['instacart_order_id']);
            $this->assertStringStartsNotWith("'", $order['instacart_order_id']);
            $this->assertMatchesRegularExpression('/^\d{17}$/', $order['instacart_order_id']);
        }
    }
}
