<?php

namespace Tests\Unit;

use App\Services\Matching\Normalizer;
use App\Services\Matching\Scorer;
use PHPUnit\Framework\TestCase;

/**
 * Plain PHPUnit, like InstacartImportTest. Descriptions are real ones from
 * the imported store_products table.
 */
class MatchingNormalizerTest extends TestCase
{
    private function normalizer(): Normalizer
    {
        $config = require __DIR__.'/../../config/grocery_planner.php';

        return new Normalizer($config['matching']['noise_phrases'], $config['matching']['stopwords']);
    }

    public function test_store_brand_sizes_and_packaging_are_stripped(): void
    {
        $n = $this->normalizer();

        $this->assertSame(['organic', 'raspberry'], $n->tokens('Organic Raspberries, 12 oz'));
        $this->assertSame(['heavy', 'whipping', 'cream'], $n->tokens('Hy-Vee Hy-Vee Heavy Whipping Cream'));
        $this->assertSame(['organic', 'a2', 'whole', 'milk'], $n->tokens('Kirkland Signature Organic A2 Whole Milk, Half Gallon, 3-count'));
        $this->assertSame(['white', 'hot', 'dog', 'bun'], $n->tokens('Hy-Vee White Hot Dog Buns, 8 Count'));
    }

    public function test_aldi_package_and_hyvee_listing_of_same_product_normalize_identically(): void
    {
        $n = $this->normalizer();

        $this->assertSame(
            $n->tokens('Organic Blueberries Package'),
            $n->tokens('Organic Blueberries, Package'),
        );
        $this->assertSame($n->tokens('Organic Blueberries, 18 oz'), $n->tokens('Organic Blueberries, Package'));
    }

    public function test_store_name_phrase_is_removed_without_eating_the_herb(): void
    {
        $n = $this->normalizer();

        $this->assertSame(['zesty', 'jicama', 'kick'], $n->tokens('Fresh Thyme Market Zesty Jicama Kick'));
        $this->assertContains('thyme', $n->tokens('Organic Thyme, 0.25 oz'));
    }

    public function test_percentages_survive_as_tokens(): void
    {
        $this->assertSame(['skotidaki', '5pct', 'greek', 'yogurt'], $this->normalizer()->tokens('Skotidakis 5% Greek Yogurt, 48 oz'));
    }

    public function test_display_name_drops_brand_size_and_repeats(): void
    {
        $n = $this->normalizer();

        $this->assertSame('Heavy Whipping Cream', $n->displayName('Hy-Vee Hy-Vee Heavy Whipping Cream'));
        $this->assertSame('Organic Raspberries', $n->displayName('Organic Raspberries, 12 oz'));
        $this->assertSame('Organic A2 Whole Milk', $n->displayName('Kirkland Signature Organic A2 Whole Milk, Half Gallon, 3-count'));
        $this->assertSame('Lemon', $n->displayName('Lemon'));
    }

    public function test_short_name_matches_long_description_but_not_a_different_thing(): void
    {
        $n = $this->normalizer();
        $s = new Scorer;

        $yogurt = $s->score($n->tokens('Skotidakis 5% Greek Yogurt, 48 oz'), $n->tokens('Greek yogurt'));
        $milkVsChocolate = $s->score($n->tokens('Ghirardelli Premium 100% Cacao Unsweetened Chocolate Baking Bar'), $n->tokens('Whole milk'));

        $this->assertGreaterThanOrEqual(0.6, $yogurt);
        $this->assertSame(0.0, $milkVsChocolate);
    }

    public function test_one_word_item_is_not_suggested_for_products_merely_flavored_with_it(): void
    {
        $n = $this->normalizer();
        $s = new Scorer;
        $lemon = $n->tokens('Lemon');

        foreach (['Ithaca Hummus Lemon Beet', 'Polar Pink Apple & Lemon Premium Seltzer', 'Freestyle Snacks Green Olives Lemon Garlic'] as $flavored) {
            $this->assertLessThan(0.6, $s->score($n->tokens($flavored), $lemon), $flavored);
        }
        $this->assertSame(1.0, $s->score($n->tokens('Lemon'), $lemon));
    }

    public function test_confirmed_member_pulls_in_the_same_item_at_another_store(): void
    {
        $n = $this->normalizer();
        $s = new Scorer;

        $score = $s->score(
            $n->tokens('Highline Mushrooms Sliced Baby Portobella Mushrooms'),
            $n->tokens('Baby bella mushrooms'),
            [$n->tokens('Highline Sliced Baby Bella Mushrooms')],
        );

        $this->assertGreaterThanOrEqual(0.6, $score);
    }
}
