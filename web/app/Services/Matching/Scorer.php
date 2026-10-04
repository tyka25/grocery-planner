<?php

namespace App\Services\Matching;

/**
 * Scores how well a store product fits a canonical item, 0..1. Pure: works
 * on token arrays from Normalizer, no database.
 *
 * Two signals, best one wins:
 *  - the item's *name*: "are the name's words in the description",
 *    scaled down by how much longer the description is. A short name has
 *    to fit a fairly short description: "Greek yogurt" fits "Skotidakis 5%
 *    Greek Yogurt" (0.71), but "Lemon" does not fit "Ithaca Hummus Lemon
 *    Beet" (0.5) -- on real data, unscaled containment suggested Lemon for
 *    every lemon-flavored product.
 *  - the item's *confirmed products*: Dice overlap with the closest one, so
 *    once a human links "Organic Blueberries, 18 oz" the Aldi "Organic
 *    Blueberries, Package" finds it too.
 * Same Instacart product ID as a confirmed product is an outright 1.0.
 */
class Scorer
{
    /**
     * @param  string[]  $productTokens
     * @param  string[]  $nameTokens
     * @param  array<int, string[]>  $memberTokenSets  token sets of the item's confirmed products
     */
    public function score(array $productTokens, array $nameTokens, array $memberTokenSets = []): float
    {
        $best = 0.0;

        if ($nameTokens && $productTokens) {
            $containment = count(array_intersect($nameTokens, $productTokens)) / count($nameTokens);
            $best = $containment * sqrt(min(1, count($nameTokens) / count($productTokens)));
        }

        foreach ($memberTokenSets as $member) {
            $best = max($best, $this->dice($productTokens, $member));
        }

        return round($best, 4);
    }

    /**
     * @param  string[]  $a
     * @param  string[]  $b
     */
    public function dice(array $a, array $b): float
    {
        if (!$a || !$b) {
            return 0.0;
        }

        return 2 * count(array_intersect($a, $b)) / (count($a) + count($b));
    }
}
