<?php

namespace Tests\Feature;

use App\Models\AvailabilitySnapshot;
use App\Models\CanonicalItem;
use App\Models\ItemStorePref;
use App\Models\Location;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\ShoppingList;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingListPlanTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 1;

    private Store $hyvee;
    private Store $costco;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hyvee = $this->store('hy-vee', 10);
        $this->costco = $this->store('costco', 35);
    }

    private function store(string $slug, ?float $threshold = null, bool $enabled = true): Store
    {
        $location = Location::firstOrCreate(['label' => 'Home'], ['address' => '1 Test St', 'kind' => 'home']);

        return Store::create(['retailer_slug' => $slug, 'name' => $slug, 'fulfillment' => 'delivery',
            'location_id' => $location->id, 'free_delivery_threshold' => $threshold, 'enabled' => $enabled]);
    }

    /** A confirmed product with $times past purchases at $price each. */
    private function product(Store $store, CanonicalItem $item, int $times = 1, float $price = 5.0, string $status = 'confirmed'): StoreProduct
    {
        $p = StoreProduct::create(['store_id' => $store->id, 'instacart_product_id' => (string) $this->seq++,
            'description' => $item->name.' at '.$store->retailer_slug, 'canonical_item_id' => $item->id, 'match_status' => $status]);

        for ($i = 0; $i < $times; $i++) {
            $order = Order::create(['instacart_order_id' => (string) $this->seq++, 'store_id' => $store->id,
                'location_id' => $store->location_id, 'ordered_on' => now()->subDays(10 + $i)->toDateString(), 'status' => 'delivered']);
            OrderLine::create(['order_id' => $order->id, 'store_product_id' => $p->id, 'qty' => 2,
                'line_total' => $price * 2, 'paid_raw' => $price * 2]);
        }

        return $p;
    }

    public function test_usual_store_comes_from_purchase_history_and_only_confirmed_links_count(): void
    {
        $milk = CanonicalItem::create(['name' => 'Whole milk']);
        $this->product($this->hyvee, $milk, times: 1, price: 4.0);
        $this->product($this->costco, $milk, times: 3, price: 12.0);
        // An unreviewed suggestion at Hy-Vee bought 9 times must not count.
        $this->product($this->hyvee, $milk, times: 9, status: 'auto');

        $list = ShoppingList::current();
        $list->items()->create(['canonical_item_id' => $milk->id, 'qty' => 3]);

        $this->actingAs(User::factory()->create())->post(route('list.plan'))->assertRedirect();

        $a = $list->items()->first()->assignment;
        $this->assertSame($this->costco->id, $a->store_id);
        $this->assertSame('preferred', $a->reason);
        $this->assertSame(12.0, $a->estimated_unit_price); // last-paid line_total / qty
        $this->assertSame('history', $a->price_source);
        $this->assertSame('planned', $list->fresh()->status);
    }

    public function test_a_pinned_store_beats_purchase_history(): void
    {
        $milk = CanonicalItem::create(['name' => 'Whole milk']);
        $this->product($this->hyvee, $milk, times: 1);
        $this->product($this->costco, $milk, times: 5);
        ItemStorePref::create(['canonical_item_id' => $milk->id, 'store_id' => $this->hyvee->id, 'rank' => 1, 'source' => 'manual', 'pinned' => true]);

        $list = ShoppingList::current();
        $list->items()->create(['canonical_item_id' => $milk->id, 'qty' => 1]);
        $this->actingAs(User::factory()->create())->post(route('list.plan'));

        $this->assertSame($this->hyvee->id, $list->items()->first()->assignment->store_id);
        $this->assertSame('Always bought here', $list->items()->first()->assignment->note);
    }

    public function test_a_fresh_out_of_stock_snapshot_reroutes_and_a_stale_one_is_ignored(): void
    {
        $milk = CanonicalItem::create(['name' => 'Whole milk']);
        $eggs = CanonicalItem::create(['name' => 'Eggs']);
        $milkCostco = $this->product($this->costco, $milk, times: 3, price: 20.0);
        $this->product($this->hyvee, $milk, times: 1, price: 20.0);
        // $40 so the Costco order clears its $35 threshold and isn't folded into Hy-Vee.
        $eggsCostco = $this->product($this->costco, $eggs, times: 3, price: 40.0);
        $this->product($this->hyvee, $eggs, times: 1, price: 40.0);

        AvailabilitySnapshot::create(['store_product_id' => $milkCostco->id, 'observed_at' => now()->subHour(), 'available' => false]);
        AvailabilitySnapshot::create(['store_product_id' => $eggsCostco->id, 'observed_at' => now()->subDays(5), 'available' => false]);

        $list = ShoppingList::current();
        $milkLine = $list->items()->create(['canonical_item_id' => $milk->id, 'qty' => 1]);
        $eggLine = $list->items()->create(['canonical_item_id' => $eggs->id, 'qty' => 1]);
        $this->actingAs(User::factory()->create())->post(route('list.plan'));

        $this->assertSame($this->hyvee->id, $milkLine->fresh()->assignment->store_id);
        $this->assertSame('fallback', $milkLine->fresh()->assignment->reason);
        $this->assertSame($this->costco->id, $eggLine->fresh()->assignment->store_id);
        $this->assertNull($eggLine->fresh()->assignment->available);
    }

    public function test_disabled_stores_are_never_planned_to(): void
    {
        $publix = $this->store('publix', enabled: false);
        $cream = CanonicalItem::create(['name' => 'Half & half']);
        $this->product($publix, $cream, times: 5);

        $list = ShoppingList::current();
        $list->items()->create(['canonical_item_id' => $cream->id, 'qty' => 1]);
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('list.plan'));

        $this->assertNull($list->items()->first()->assignment);
        $this->actingAs($user)->get(route('list.show'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('lines.0.unplanned_reason', 'Never bought at an active store yet. Link a product to it on the Item matching page.'));
    }

    public function test_adding_by_name_ignores_iphone_smart_punctuation_and_spacing(): void
    {
        $user = User::factory()->create();
        $tea = CanonicalItem::create(['name' => "Trader Joe's Half-Caff Tea"]);

        $this->actingAs($user)->post(route('list.items.store'), ['name' => "  trader joe’s  half–caff tea "]);

        $line = ShoppingList::current()->items()->sole();
        $this->assertSame($tea->id, $line->canonical_item_id);
        $this->assertNull($line->free_text);
    }

    public function test_list_flow_add_staples_move_remember_and_replan(): void
    {
        $user = User::factory()->create();
        $milk = CanonicalItem::create(['name' => 'Whole milk', 'is_staple' => true]);
        $yogurt = CanonicalItem::create(['name' => 'Greek yogurt', 'is_staple' => true]);
        $this->product($this->costco, $milk, times: 3, price: 20.0);
        $this->product($this->hyvee, $milk, times: 1, price: 4.0);
        $this->product($this->costco, $yogurt, times: 2, price: 20.0);

        $this->actingAs($user)->get(route('list.show'))->assertOk();
        $this->actingAs($user)->post(route('list.staples'))->assertRedirect();
        $this->actingAs($user)->post(route('list.items.store'), ['name' => 'birthday candles'])->assertRedirect();
        $this->actingAs($user)->post(route('list.items.store'), ['name' => 'WHOLE MILK', 'qty' => 2]);

        $list = ShoppingList::current();
        $this->assertSame(4, $list->items()->count());
        $this->assertSame(['adhoc', 'staple'], $list->items()->distinct()->orderBy('source')->pluck('source')->all());
        $this->assertSame(2, $list->items()->where('canonical_item_id', $milk->id)->count());

        $this->actingAs($user)->post(route('list.plan'));
        $milkLine = $list->items()->where('canonical_item_id', $milk->id)->where('source', 'staple')->first();
        $this->assertSame($this->costco->id, $milkLine->assignment->store_id);

        // Move by hand and remember it; re-planning keeps it and pins Hy-Vee for milk.
        $this->actingAs($user)->post(route('list.items.move', $milkLine), ['store_id' => $this->hyvee->id, 'remember' => true]);
        $this->actingAs($user)->post(route('list.plan'));

        $a = $milkLine->fresh()->assignment;
        $this->assertSame($this->hyvee->id, $a->store_id);
        $this->assertSame('manual', $a->reason);
        $this->assertNotNull($a->store_product_id);
        $this->assertTrue(ItemStorePref::where('canonical_item_id', $milk->id)->where('store_id', $this->hyvee->id)->where('pinned', true)->exists());

        // Free text is listed but never assigned.
        $candles = $list->items()->whereNull('canonical_item_id')->first();
        $this->assertNull($candles->assignment);

        // Editing a planned list re-plans it.
        $this->actingAs($user)->delete(route('list.items.destroy', $candles));
        $this->actingAs($user)->patch(route('list.items.update', $milkLine), ['qty' => 4]);
        $this->assertSame(3, $list->items()->count());

        $this->actingAs($user)->get(route('list.show'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('list.status', 'planned')->has('stores'));

        // Finishing starts a fresh list.
        $this->actingAs($user)->post(route('list.finish'));
        $this->assertNotSame($list->id, ShoppingList::current()->id);
    }
}
