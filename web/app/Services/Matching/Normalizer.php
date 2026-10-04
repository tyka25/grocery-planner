<?php

namespace App\Services\Matching;

/**
 * Turns an Instacart product description into a comparable bag of tokens.
 * No framework dependency (same as the importer), so it's unit-testable
 * without booting the app.
 *
 * Descriptions are noisy in store-specific ways: Hy-Vee repeats its own brand
 * ("Hy-Vee Hy-Vee Half & Half"), Costco appends size and count ("..., 18 oz",
 * "3-count"), and the same thing is "Package" at Aldi and nothing at Hy-Vee.
 * All of that is stripped so what remains is roughly *what the item is*.
 */
class Normalizer
{
    private const UNITS = 'fl\.?\s*oz|oz|ounces?|lbs?|pounds?|ct|count|pk|packs?|gal(?:lons?)?|qts?|quarts?|pints?|ml|l|liters?|g|grams?|kg';

    /** @var string[] */
    private array $noisePatterns;

    /** @var array<string, true> */
    private array $stopwords;

    /**
     * @param  string[]  $noisePhrases
     * @param  string[]  $stopwords
     */
    public function __construct(array $noisePhrases, array $stopwords)
    {
        // Longest first so "fresh thyme market" wins over "fresh thyme".
        usort($noisePhrases, fn ($a, $b) => strlen($b) <=> strlen($a));
        $this->noisePatterns = array_map(
            fn ($p) => '/(?<![\pL\pN])'.preg_quote(mb_strtolower($p), '/').'(?![\pL\pN])/u',
            $noisePhrases
        );
        $this->stopwords = array_fill_keys(array_map('mb_strtolower', $stopwords), true);
    }

    /**
     * @return string[] unique tokens, in first-seen order
     */
    public function tokens(string $description): array
    {
        $s = mb_strtolower($description);
        $s = str_replace(['’', '‘'], "'", $s);
        $s = $this->stripNoise($s);
        $s = $this->stripSizes($s);

        // "2%" milk vs whole milk matters; keep percentages as a token.
        $s = preg_replace('/(\d+(?:\.\d+)?)\s*%/u', ' $1pct ', $s);
        $s = str_replace('&', ' and ', $s);
        $s = preg_replace("/'/u", '', $s);                    // mann's -> manns
        $s = preg_replace('/(?<=\pL)-(?=\pL)/u', '', $s);      // pre-cut -> precut

        $tokens = [];
        foreach (preg_split('/[^\pL\pN]+/u', $s, -1, PREG_SPLIT_NO_EMPTY) as $t) {
            if (ctype_digit($t) || mb_strlen($t) < 2 || isset($this->stopwords[$t])) {
                continue;
            }
            $tokens[$this->singular($t)] = true;
        }

        return array_keys($tokens);
    }

    /**
     * A human-friendly starting name for a new canonical item: brand noise
     * and sizes removed, original casing kept. Only ever a prefill -- the
     * person creating the item edits it.
     */
    public function displayName(string $description): string
    {
        $s = str_replace(['’', '‘'], "'", $description);
        foreach ($this->noisePatterns as $pattern) {
            $s = preg_replace($pattern.'i', ' ', $s);
        }
        $s = $this->stripSizes($s);

        // Collapse "Famous Famous" / "Short Cuts Short Cuts" style repeats.
        $words = preg_split('/\s+/u', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        $out = [];
        foreach ($words as $w) {
            if ($out && mb_strtolower(end($out)) === mb_strtolower($w)) {
                continue;
            }
            $out[] = $w;
        }

        $name = trim(implode(' ', $out), " ,-");
        $name = preg_replace('/\s*,\s*(,\s*)*/u', ', ', $name);

        return trim($name, " ,") ?: trim($description);
    }

    private function stripNoise(string $s): string
    {
        foreach ($this->noisePatterns as $pattern) {
            $s = preg_replace($pattern, ' ', $s);
        }

        return $s;
    }

    private function stripSizes(string $s): string
    {
        $s = preg_replace('/\bhalf[\s-]+gallons?\b/iu', ' ', $s);
        // "18 oz", "3-count", "2Ct", "3.17 oz", "1.5 lbs"
        $s = preg_replace('/\b\d+(?:\.\d+)?\s*-?\s*(?:'.self::UNITS.')\b\.?/iu', ' ', $s);

        return $s;
    }

    private function singular(string $t): string
    {
        $len = mb_strlen($t);
        if ($len > 4 && str_ends_with($t, 'ies')) {
            return mb_substr($t, 0, -3).'y';          // berries -> berry
        }
        if ($len > 4 && str_ends_with($t, 'oes')) {
            return mb_substr($t, 0, -2);              // tomatoes -> tomato
        }
        if ($len > 3 && str_ends_with($t, 's') && !str_ends_with($t, 'ss') && !str_ends_with($t, 'us')) {
            return mb_substr($t, 0, -1);              // limes -> lime, but not hummus
        }

        return $t;
    }
}
