<?php

namespace App\Services\Matching;

use App\Models\CanonicalItem;
use App\Models\MatchRejection;
use App\Models\StoreProduct;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Writes fuzzy-match *suggestions* onto store_products. Never confirms
 * anything and never touches a confirmed row -- the yes/no is always a
 * human's (see StoreProduct::confirmMatch / rejectMatch).
 *
 * Status rules it maintains, for every non-confirmed product:
 *   best candidate >= threshold          -> 'auto', canonical_item_id + score set
 *   no candidate, was 'auto'             -> 'unmatched' (stale suggestion withdrawn)
 *   no candidate, 'unmatched'/'rejected' -> left as is
 * A (product, item) pair in match_rejections is never suggested again.
 */
class MatchSuggester
{
    public function __construct(
        private Normalizer $normalizer,
        private Scorer $scorer,
        private float $threshold,
    ) {}

    public static function fromConfig(): self
    {
        $cfg = config('grocery_planner.matching');

        return new self(
            new Normalizer($cfg['noise_phrases'], $cfg['stopwords']),
            new Scorer,
            (float) $cfg['suggest_threshold'],
        );
    }

    public function normalizer(): Normalizer
    {
        return $this->normalizer;
    }

    /**
     * @return array{suggested: int, withdrawn: int, unchanged: int}
     */
    public function run(): array
    {
        $items = $this->candidateProfiles();
        $rejected = MatchRejection::query()
            ->get(['store_product_id', 'canonical_item_id'])
            ->groupBy('store_product_id')
            ->map(fn ($rows) => $rows->pluck('canonical_item_id')->flip());

        $counts = ['suggested' => 0, 'withdrawn' => 0, 'unchanged' => 0];

        DB::transaction(function () use ($items, $rejected, &$counts) {
            StoreProduct::query()
                ->where('match_status', '!=', 'confirmed')
                ->get()
                ->each(function (StoreProduct $p) use ($items, $rejected, &$counts) {
                    [$itemId, $score] = $this->best($p, $items, $rejected->get($p->id, collect()));

                    if ($itemId !== null) {
                        $changed = $p->match_status !== 'auto'
                            || $p->canonical_item_id !== $itemId
                            || (float) $p->match_score !== $score;
                        $p->forceFill(['canonical_item_id' => $itemId, 'match_status' => 'auto', 'match_score' => $score]);
                        $counts[$changed ? 'suggested' : 'unchanged']++;
                    } elseif ($p->match_status === 'auto') {
                        $p->forceFill(['canonical_item_id' => null, 'match_status' => 'unmatched', 'match_score' => null]);
                        $counts['withdrawn']++;
                    } else {
                        $counts['unchanged']++;
                    }

                    $p->isDirty() && $p->save();
                });
        });

        return $counts;
    }

    /**
     * Products that look like the same thing as $product and aren't linked to
     * anything yet -- offered as pre-ticked extras when creating a new
     * canonical item from $product, so one click can group "Lemon" at three
     * stores.
     *
     * @return Collection<int, StoreProduct>
     */
    public function similarUnlinked(StoreProduct $product): Collection
    {
        $tokens = $this->normalizer->tokens($product->description);

        return StoreProduct::query()
            ->with('store')
            ->whereKeyNot($product->id)
            ->where('match_status', '!=', 'confirmed')
            ->get()
            ->map(function (StoreProduct $other) use ($product, $tokens) {
                $other->similarity = $other->instacart_product_id === $product->instacart_product_id
                    ? 1.0
                    : round($this->scorer->dice($tokens, $this->normalizer->tokens($other->description)), 4);

                return $other;
            })
            ->filter(fn ($o) => $o->similarity >= $this->threshold)
            ->sortByDesc('similarity')
            ->values();
    }

    /**
     * @return Collection<int, array{name: string[], members: array<int, string[]>, product_ids: array<string, true>}>
     */
    private function candidateProfiles(): Collection
    {
        return CanonicalItem::query()
            ->with(['storeProducts' => fn ($q) => $q->where('match_status', 'confirmed')])
            ->get()
            ->mapWithKeys(fn (CanonicalItem $item) => [$item->id => [
                'name' => $this->normalizer->tokens($item->name),
                'members' => $item->storeProducts->map(fn ($sp) => $this->normalizer->tokens($sp->description))->all(),
                'product_ids' => $item->storeProducts->pluck('instacart_product_id')->flip()->map(fn () => true)->all(),
            ]]);
    }

    /**
     * @return array{0: ?int, 1: ?float}
     */
    private function best(StoreProduct $p, Collection $items, Collection $rejectedItemIds): array
    {
        $tokens = $this->normalizer->tokens($p->description);
        $bestId = null;
        $bestScore = 0.0;

        foreach ($items as $itemId => $profile) {
            if ($rejectedItemIds->has($itemId)) {
                continue;
            }
            $score = isset($profile['product_ids'][$p->instacart_product_id])
                ? 1.0
                : $this->scorer->score($tokens, $profile['name'], $profile['members']);

            if ($score > $bestScore) {
                [$bestId, $bestScore] = [$itemId, $score];
            }
        }

        return $bestScore >= $this->threshold ? [$bestId, $bestScore] : [null, null];
    }
}
