<?php

namespace App\Services\Sidecar;

use App\Models\AvailabilitySnapshot;
use App\Models\ScrapeRun;
use App\Models\ShoppingList;
use App\Models\Store;
use App\Models\StoreProduct;
use App\Services\Planner\PlanService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Coordinates stock checks with the home-machine sidecar. The sidecar only
 * ever asks "is there work?" and reports back; deciding *what* to check
 * and *whether* a check is due lives here.
 *
 * Failures are meant to be loud: a run that loaded pages but captured
 * nothing is recorded as an error even if the sidecar called it ok, and
 * products whose page carried no data are listed as "missing" -- likely
 * discontinued or re-numbered at that store, and NOT treated as out of
 * stock (only an explicit available: false is).
 */
class SidecarRuns
{
    private const REQUEST_KEY = 'sidecar.check_request';

    /**
     * @param  'all'|'unchecked'  $scope  'all' = "Check stock now" (every
     *   product on the list); 'unchecked' = only products with no fresh
     *   snapshot, requested automatically when items are added -- so adding
     *   Butternut Squash right after a check gets it checked in seconds
     *   instead of waiting up to auto_check_hours. A pending 'all' is never
     *   narrowed by a later 'unchecked'.
     */
    public function requestCheck(string $scope = 'all'): void
    {
        $pending = Cache::get(self::REQUEST_KEY);
        if (($pending['scope'] ?? null) === 'all') {
            $scope = 'all';
        } elseif ($scope === 'unchecked' && !$this->lookupsForCurrentList(uncheckedOnly: true)) {
            return; // everything on the list is already fresh
        }
        Cache::forever(self::REQUEST_KEY, ['at' => now()->toIso8601String(), 'scope' => $scope]);
    }

    public function requestedAt(): ?Carbon
    {
        $at = Cache::get(self::REQUEST_KEY)['at'] ?? null;

        return $at ? Carbon::parse($at) : null;
    }

    /**
     * Starts a run if one is due and there's something to check.
     *
     * @return ?array{run: ScrapeRun, lookups: array}
     */
    public function claimWork(): ?array
    {
        $running = ScrapeRun::where('status', 'running')->latest('id')->first();
        if ($running && !$running->isStalled()) {
            return null;
        }
        if ($running) {
            $running->update([
                'status' => 'error',
                'finished_at' => now(),
                'error' => 'Stalled: the sidecar never reported finishing (crashed, or the computer went to sleep).',
            ]);
        }

        $request = Cache::get(self::REQUEST_KEY);
        $requested = $request !== null;
        $lastFinished = ScrapeRun::whereNotNull('finished_at')->latest('finished_at')->first();
        $due = $requested
            || !$lastFinished
            || $lastFinished->finished_at->lt(now()->subHours((int) config('grocery_planner.sidecar.auto_check_hours')));

        if (!$due) {
            return null;
        }

        $uncheckedOnly = ($request['scope'] ?? 'all') === 'unchecked';
        $lookups = $this->lookupsForCurrentList($uncheckedOnly);
        if (!$lookups) {
            Cache::forget(self::REQUEST_KEY); // nothing (left) on the list to check
            return null;
        }

        $run = ScrapeRun::create([
            'started_at' => now(),
            'status' => 'running',
            'trigger' => !$requested ? 'scheduled' : ($uncheckedOnly ? 'added' : 'requested'),
            'expected' => array_column($lookups, 'storeProductId'),
        ]);
        Cache::forget(self::REQUEST_KEY);

        return ['run' => $run, 'lookups' => $lookups];
    }

    /**
     * One product-page lookup per confirmed product of every item on the
     * current list, at enabled stores. Product pages, not searches: on the
     * first real run, Hy-Vee search for a product's exact name didn't
     * return that (in-stock) product, so search can't be relied on to find
     * a specific one.
     *
     * With $uncheckedOnly, products that already have a snapshot fresh
     * enough for the planner to use are skipped.
     */
    public function lookupsForCurrentList(bool $uncheckedOnly = false): array
    {
        return $this->listProducts()
            ->when($uncheckedOnly, fn ($ps) => $ps->reject(fn ($p) => $p->fresh_snapshot))
            ->map(fn (StoreProduct $p) => [
                'storeSlug' => $p->store->retailer_slug,
                'instacartProductId' => $p->instacart_product_id,
                'description' => $p->description,
                'storeProductId' => $p->id,
            ])
            ->sortBy(['storeSlug', 'description'])
            ->values()
            ->all();
    }

    /**
     * Names of list items none of whose products has a fresh snapshot, so
     * the banner never implies the whole list was checked. Products the
     * last ok run found no data for are left out -- they're shown as
     * "not found" instead.
     *
     * @return string[]
     */
    public function uncheckedItemNames(): array
    {
        $missing = array_flip(ScrapeRun::where('status', 'ok')->latest('id')->first()?->missing ?? []);

        return $this->listProducts()
            ->groupBy('canonical_item_id')
            ->reject(fn ($ps) => $ps->contains(fn ($p) => $p->fresh_snapshot || isset($missing[$p->id])))
            ->map(fn ($ps) => $ps->first()->canonicalItem->name)
            ->sort()
            ->values()
            ->all();
    }

    /** Confirmed products of current-list items at enabled stores, each with `fresh_snapshot`. */
    private function listProducts(): Collection
    {
        $itemIds = ShoppingList::current()->items()->whereNotNull('canonical_item_id')->pluck('canonical_item_id')->unique();
        $freshSince = now()->subHours((int) config('grocery_planner.planner.snapshot_max_age_hours'));

        return StoreProduct::query()
            ->with(['store', 'canonicalItem'])
            ->whereIn('canonical_item_id', $itemIds)
            ->where('match_status', 'confirmed')
            ->whereIn('store_id', Store::where('enabled', true)->select('id'))
            ->withExists(['availabilitySnapshots as fresh_snapshot' => fn ($q) => $q->where('observed_at', '>=', $freshSince)])
            ->get();
    }

    /**
     * Saves snapshots for products we know. Anything else in the search
     * results is counted, not stored -- creating store_products from
     * search noise would flood the matching review queue.
     *
     * @return array{saved: int, unknown: int}
     */
    public function ingest(ScrapeRun $run, array $snapshots): array
    {
        $stores = Store::pluck('id', 'retailer_slug');
        $keys = collect($snapshots)->map(fn ($s) => ($stores[$s['storeSlug']] ?? 0).':'.$s['instacartProductId']);
        $known = StoreProduct::query()
            ->whereIn('store_id', $stores->values())
            ->whereIn('instacart_product_id', collect($snapshots)->pluck('instacartProductId')->unique())
            ->get(['id', 'store_id', 'instacart_product_id'])
            ->keyBy(fn ($p) => $p->store_id.':'.$p->instacart_product_id);

        $saved = 0;
        DB::transaction(function () use ($snapshots, $keys, $known, $run, &$saved) {
            foreach ($snapshots as $i => $s) {
                $product = $known->get($keys[$i]);
                if (!$product) {
                    continue;
                }
                AvailabilitySnapshot::create([
                    'store_product_id' => $product->id,
                    'scrape_run_id' => $run->id,
                    'observed_at' => Carbon::parse($s['observedAt']),
                    'available' => $s['available'],
                    'stock_level' => $s['stockLevel'] ?? null,
                    'price' => $s['price'] ?? null,
                    'size_text' => $s['sizeText'] ?? null,
                    'query' => $s['query'] ?? null,
                ]);
                $saved++;
            }
        });

        $unknown = count($snapshots) - $saved;
        $run->increment('snapshots_saved', $saved);
        $run->increment('unknown_products', $unknown);

        return ['saved' => $saved, 'unknown' => $unknown];
    }

    public function finish(ScrapeRun $run, string $status, ?string $error, int $searches): ScrapeRun
    {
        if ($status === 'ok' && $searches > 0 && $run->snapshots_saved === 0 && $run->unknown_products === 0) {
            $status = 'error';
            $error = 'Product pages loaded but none carried product data. The Instacart session may be in a bad state, '
                .'or Instacart changed its page so the capture no longer matches.';
        }

        // "No data on its page" only means something for a run that got
        // through its lookups; a failed run would list everything.
        $seen = $run->snapshots()->distinct()->pluck('store_product_id')->all();
        $missing = $status === 'ok' ? array_values(array_diff($run->expected ?? [], $seen)) : [];

        $run->update([
            'status' => $status,
            'error' => $error,
            'searches' => $searches,
            'missing' => $missing,
            'finished_at' => now(),
        ]);

        $list = ShoppingList::current();
        if ($list->status === 'planned' && $run->snapshots_saved > 0) {
            PlanService::fromConfig()->plan($list);
        }

        return $run;
    }

    /** What the list page shows about stock checking. */
    public function statusForUi(): array
    {
        $last = ScrapeRun::latest('id')->first();
        $requestedAt = $this->requestedAt();

        $missing = collect($last?->missing ?? []);
        $missingNames = $missing->isEmpty() ? [] : StoreProduct::with('store')->whereKey($missing)->get()
            ->map(fn ($p) => $p->description.' ('.$p->store->label.')')->all();

        return [
            'requested_at' => $requestedAt?->toIso8601String(),
            'unchecked' => $this->uncheckedItemNames(),
            'last' => $last ? [
                'status' => $last->isStalled() ? 'stalled' : $last->status,
                'started_at' => $last->started_at->toIso8601String(),
                'finished_at' => $last->finished_at?->toIso8601String(),
                'error' => $last->error,
                'missing' => $missingNames,
                'snapshots_saved' => $last->snapshots_saved,
            ] : null,
        ];
    }
}
