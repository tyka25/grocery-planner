<?php

namespace Tests\Feature;

use App\Models\CanonicalItem;
use App\Models\Location;
use App\Models\ScrapeRun;
use App\Models\ShoppingList;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SidecarApiTest extends TestCase
{
    use RefreshDatabase;

    private Store $hyvee;
    private Store $costco;
    private StoreProduct $milkHyvee;
    private StoreProduct $milkCostco;

    protected function setUp(): void
    {
        parent::setUp();
        config(['grocery_planner.sidecar.token' => 'test-token']);

        $location = Location::create(['label' => 'Home', 'address' => '1 Test St', 'kind' => 'home']);
        $this->hyvee = Store::create(['retailer_slug' => 'hy-vee', 'name' => 'hy-vee', 'fulfillment' => 'delivery', 'location_id' => $location->id]);
        $this->costco = Store::create(['retailer_slug' => 'costco', 'name' => 'costco', 'fulfillment' => 'delivery', 'location_id' => $location->id]);

        $milk = CanonicalItem::create(['name' => 'Whole milk']);
        $this->milkHyvee = StoreProduct::create(['store_id' => $this->hyvee->id, 'instacart_product_id' => '111', 'description' => 'Hy-Vee Whole Milk', 'canonical_item_id' => $milk->id, 'match_status' => 'confirmed']);
        $this->milkCostco = StoreProduct::create(['store_id' => $this->costco->id, 'instacart_product_id' => '222', 'description' => 'Kirkland Whole Milk', 'canonical_item_id' => $milk->id, 'match_status' => 'confirmed']);
        // An unconfirmed suggestion must not generate a search.
        StoreProduct::create(['store_id' => $this->costco->id, 'instacart_product_id' => '333', 'description' => 'Some Other Milk', 'canonical_item_id' => $milk->id, 'match_status' => 'auto']);

        ShoppingList::current()->items()->create(['canonical_item_id' => $milk->id, 'qty' => 1]);
    }

    private function api(): self
    {
        return $this->withToken('test-token');
    }

    private function snapshot(string $store, string $productId, bool $available = true, ?float $price = 4.99): array
    {
        return ['storeSlug' => $store, 'instacartProductId' => $productId, 'observedAt' => now()->toIso8601String(),
            'available' => $available, 'stockLevel' => $available ? 'inStock' : null, 'price' => $price, 'sizeText' => null, 'query' => 'milk'];
    }

    public function test_requests_without_the_token_are_refused(): void
    {
        $this->getJson('/api/sidecar/work')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/sidecar/work')->assertUnauthorized();

        config(['grocery_planner.sidecar.token' => null]);
        $this->withToken('')->getJson('/api/sidecar/work')->assertUnauthorized();
    }

    public function test_work_is_one_product_lookup_per_confirmed_product_on_the_list(): void
    {
        $work = $this->api()->getJson('/api/sidecar/work')->assertOk()->json();

        $this->assertNotNull($work['runId']);
        $this->assertSame([
            ['storeSlug' => 'costco', 'instacartProductId' => '222', 'description' => 'Kirkland Whole Milk'],
            ['storeSlug' => 'hy-vee', 'instacartProductId' => '111', 'description' => 'Hy-Vee Whole Milk'],
        ], $work['lookups']);
        $this->assertSame('scheduled', ScrapeRun::find($work['runId'])->trigger);
    }

    public function test_no_new_work_while_a_run_is_going_or_a_recent_one_finished_unless_requested(): void
    {
        $runId = $this->api()->getJson('/api/sidecar/work')->json('runId');
        $this->assertNull($this->api()->getJson('/api/sidecar/work')->json('runId'), 'second claim while running');

        $this->api()->postJson("/api/sidecar/runs/{$runId}/snapshots", ['snapshots' => [$this->snapshot('hy-vee', '111')]]);
        $this->api()->postJson("/api/sidecar/runs/{$runId}/finish", ['status' => 'ok', 'searches' => 2])->assertOk();
        $this->assertNull($this->api()->getJson('/api/sidecar/work')->json('runId'), 'recent run finished');

        $this->actingAs(User::factory()->create())->post(route('list.check-stock'))->assertRedirect();
        $next = $this->api()->getJson('/api/sidecar/work')->json('runId');
        $this->assertNotNull($next);
        $this->assertSame('requested', ScrapeRun::find($next)->trigger);
    }

    public function test_a_failed_run_is_not_retried_every_poll(): void
    {
        $runId = $this->api()->getJson('/api/sidecar/work')->json('runId');
        $this->api()->postJson("/api/sidecar/runs/{$runId}/finish", ['status' => 'session_expired', 'error' => 'Redirected to login', 'searches' => 0]);

        $this->assertNull($this->api()->getJson('/api/sidecar/work')->json('runId'));
        $this->travel(7)->hours();
        $this->assertNotNull($this->api()->getJson('/api/sidecar/work')->json('runId'));
    }

    public function test_a_stalled_run_is_closed_out_and_replaced(): void
    {
        $runId = $this->api()->getJson('/api/sidecar/work')->json('runId');
        $this->travel(31)->minutes();

        $this->actingAs(User::factory()->create())->post(route('list.check-stock'));
        $this->assertNotNull($this->api()->getJson('/api/sidecar/work')->json('runId'));
        $this->assertSame('error', ScrapeRun::find($runId)->status);
        $this->assertStringContainsString('Stalled', ScrapeRun::find($runId)->error);
    }

    public function test_snapshots_for_known_products_are_saved_unknown_ones_counted_and_missing_ones_listed(): void
    {
        $runId = $this->api()->getJson('/api/sidecar/work')->json('runId');

        $this->api()->postJson("/api/sidecar/runs/{$runId}/snapshots", ['snapshots' => [
            $this->snapshot('hy-vee', '111', available: false),
            $this->snapshot('hy-vee', '999'),          // in results, not a product we know
            $this->snapshot('costco', '111'),          // right ID, wrong store
        ]])->assertOk()->assertJson(['saved' => 1, 'unknown' => 2]);

        $run = $this->api()->postJson("/api/sidecar/runs/{$runId}/finish", ['status' => 'ok', 'searches' => 2])->assertOk()->json();

        $this->assertSame('ok', $run['status']);
        $this->assertSame([$this->milkCostco->id], $run['missing']);
        $this->assertFalse($this->milkHyvee->availabilitySnapshots()->first()->available);
        $this->assertSame($runId, $this->milkHyvee->availabilitySnapshots()->first()->scrape_run_id);
    }

    public function test_a_run_that_captured_nothing_is_recorded_as_an_error_even_if_reported_ok(): void
    {
        $runId = $this->api()->getJson('/api/sidecar/work')->json('runId');
        $run = $this->api()->postJson("/api/sidecar/runs/{$runId}/finish", ['status' => 'ok', 'searches' => 2])->json();

        $this->assertSame('error', $run['status']);
        $this->assertStringContainsString('none carried product data', $run['error']);
        $this->assertSame([], $run['missing'], 'a failed run lists nothing as missing');
    }

    public function test_finishing_a_run_replans_the_list_with_fresh_stock(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->post(route('list.plan'));
        $line = ShoppingList::current()->items()->first();
        $before = $line->assignment->store_id;

        $runId = $this->api()->getJson('/api/sidecar/work')->json('runId');
        $outStore = $before === $this->hyvee->id ? ['hy-vee', '111'] : ['costco', '222'];
        $inStore = $before === $this->hyvee->id ? ['costco', '222'] : ['hy-vee', '111'];
        $this->api()->postJson("/api/sidecar/runs/{$runId}/snapshots", ['snapshots' => [
            $this->snapshot($outStore[0], $outStore[1], available: false),
            $this->snapshot($inStore[0], $inStore[1], available: true),
        ]]);
        $this->api()->postJson("/api/sidecar/runs/{$runId}/finish", ['status' => 'ok', 'searches' => 2]);

        $a = $line->fresh()->assignment;
        $this->assertNotSame($before, $a->store_id);
        $this->assertSame('fallback', $a->reason);
        $this->assertTrue($a->available);

        $this->actingAs($user)->get(route('list.show'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('stockCheck.last.status', 'ok'));
    }

    public function test_a_finished_run_cannot_be_written_to_again(): void
    {
        $runId = $this->api()->getJson('/api/sidecar/work')->json('runId');
        $this->api()->postJson("/api/sidecar/runs/{$runId}/finish", ['status' => 'error', 'error' => 'boom', 'searches' => 0]);

        $this->api()->postJson("/api/sidecar/runs/{$runId}/snapshots", ['snapshots' => []])->assertStatus(409);
        $this->api()->postJson("/api/sidecar/runs/{$runId}/finish", ['status' => 'ok', 'searches' => 0])->assertStatus(409);
    }
}
