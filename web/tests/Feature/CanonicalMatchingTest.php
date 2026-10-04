<?php

namespace Tests\Feature;

use App\Models\CanonicalItem;
use App\Models\Location;
use App\Models\MatchRejection;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Models\User;
use App\Services\Matching\MatchSuggester;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalMatchingTest extends TestCase
{
    use RefreshDatabase;

    private int $productSeq = 1000;

    private function store(string $slug): Store
    {
        $location = Location::firstOrCreate(['label' => 'Home'], ['address' => '1 Test St', 'kind' => 'home']);

        return Store::create(['retailer_slug' => $slug, 'name' => $slug, 'fulfillment' => 'delivery', 'location_id' => $location->id]);
    }

    private function product(Store $store, string $description, ?string $productId = null): StoreProduct
    {
        return StoreProduct::create([
            'store_id' => $store->id,
            'instacart_product_id' => $productId ?? (string) $this->productSeq++,
            'description' => $description,
        ]);
    }

    public function test_suggestions_are_auto_never_confirmed_and_confirmed_rows_are_untouched(): void
    {
        $hyvee = $this->store('hy-vee');
        $item = CanonicalItem::create(['name' => 'Heavy whipping cream']);
        $cream = $this->product($hyvee, 'Hy-Vee Hy-Vee Heavy Whipping Cream');
        $other = $this->product($hyvee, 'Lemon');
        $confirmed = $this->product($hyvee, 'Hy-Vee Heavy Whipping Cream, 1 pint');
        $confirmed->confirmMatch(CanonicalItem::create(['name' => 'Something else']));

        MatchSuggester::fromConfig()->run();

        $this->assertSame('auto', $cream->fresh()->match_status);
        $this->assertSame($item->id, $cream->fresh()->canonical_item_id);
        $this->assertSame('unmatched', $other->fresh()->match_status);
        $this->assertNull($other->fresh()->canonical_item_id);
        $this->assertSame('confirmed', $confirmed->fresh()->match_status);
        $this->assertSame('Something else', $confirmed->fresh()->canonicalItem->name);
    }

    public function test_rejected_pair_is_never_resuggested_but_another_item_can_be(): void
    {
        $costco = $this->store('costco');
        $wrong = CanonicalItem::create(['name' => 'Organic raspberries']);
        $p = $this->product($costco, 'Organic Raspberries, 12 oz');

        MatchSuggester::fromConfig()->run();
        $this->assertSame($wrong->id, $p->fresh()->canonical_item_id);

        $p->fresh()->rejectMatch();
        MatchSuggester::fromConfig()->run();

        $p->refresh();
        $this->assertSame('rejected', $p->match_status);
        $this->assertNull($p->canonical_item_id);
        $this->assertTrue(MatchRejection::where('store_product_id', $p->id)->where('canonical_item_id', $wrong->id)->exists());

        $right = CanonicalItem::create(['name' => 'Raspberries']);
        MatchSuggester::fromConfig()->run();

        $this->assertSame('auto', $p->fresh()->match_status);
        $this->assertSame($right->id, $p->fresh()->canonical_item_id);
    }

    public function test_same_instacart_product_id_at_another_store_scores_full_match(): void
    {
        $hyvee = $this->store('hy-vee');
        $fareway = $this->store('fareway-meat-grocery');
        $item = CanonicalItem::create(['name' => 'Baby kale']);
        $this->product($hyvee, 'Organic Girl Baby Kale', '16865351')->confirmMatch($item);
        $p = $this->product($fareway, 'Organic Girl Baby Kale', '16865351');

        MatchSuggester::fromConfig()->run();

        $this->assertSame(1.0, $p->fresh()->match_score);
    }

    public function test_stale_auto_suggestion_is_withdrawn_when_its_item_stops_matching(): void
    {
        $hyvee = $this->store('hy-vee');
        $item = CanonicalItem::create(['name' => 'Lemon']);
        $p = $this->product($hyvee, 'Lemon');
        MatchSuggester::fromConfig()->run();
        $this->assertSame('auto', $p->fresh()->match_status);

        $item->update(['name' => 'Lime']);
        MatchSuggester::fromConfig()->run();

        $this->assertSame('unmatched', $p->fresh()->match_status);
        $this->assertNull($p->fresh()->canonical_item_id);
    }

    public function test_review_ui_flow_create_confirm_reject(): void
    {
        $user = User::factory()->create();
        $hyvee = $this->store('hy-vee');
        $fresh = $this->store('fresh-thyme-farmers-market');
        $lemon = $this->product($hyvee, 'Lemon');
        $lemon2 = $this->product($fresh, 'Lemon');
        $lime = $this->product($hyvee, 'Limes');

        $this->actingAs($user)->get(route('matching.index'))->assertOk();

        $similar = $this->actingAs($user)->getJson(route('matching.similar', $lemon))->assertOk()->json();
        $this->assertSame('Lemon', $similar['name']);
        $this->assertSame([$lemon2->id], array_column($similar['similar'], 'id'));

        $this->actingAs($user)->post(route('matching.items.store'), [
            'name' => 'Lemons',
            'store_product_ids' => [$lemon->id, $lemon2->id],
        ])->assertRedirect();

        $item = CanonicalItem::where('name', 'Lemons')->firstOrFail();
        $this->assertSame('confirmed', $lemon->fresh()->match_status);
        $this->assertSame('confirmed', $lemon2->fresh()->match_status);
        $this->assertSame($item->id, $lemon2->fresh()->canonical_item_id);

        // Duplicate item names are refused rather than silently creating a second "Lemons".
        $this->actingAs($user)->post(route('matching.items.store'), [
            'name' => 'Lemons', 'store_product_ids' => [$lime->id],
        ])->assertSessionHasErrors('name');

        // Linking to an existing item by hand, then unlinking it.
        $this->actingAs($user)->post(route('matching.confirm', $lime), ['canonical_item_id' => $item->id])->assertRedirect();
        $this->assertSame('confirmed', $lime->fresh()->match_status);

        $this->actingAs($user)->post(route('matching.reject', $lime))->assertRedirect();
        $this->assertSame('rejected', $lime->fresh()->match_status);
        $this->assertNull($lime->fresh()->canonical_item_id);
    }

    public function test_matching_requires_login(): void
    {
        $this->get(route('matching.index'))->assertRedirect(route('login'));
    }
}
