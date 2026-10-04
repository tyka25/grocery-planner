# grocery-planner

Personal household app (Tyler + wife; wife is the only end user of the UI).
Not multi-tenant, no public users, no auth hardening needed beyond basic
household access control.

## Why this exists

Shopping list spans recipes plus recurring baby staples (milk, yogurt,
berries, cheese...), split across Hy-Vee, Costco, Sam's Club, and Fareway
(pickup-only, at a Riverside location), based on live stock and price, with
re-routing when a store's order minimum isn't met.

## Architecture

- `web/` -- Laravel + Inertia/React. Does CSV import, canonical-item
  matching, the store-assignment planner, and the UI.
- `sidecar/` -- Node/TypeScript + Playwright, runs on a home machine with a
  persistent logged-in Instacart browser profile. Reads availability/price
  by capturing the storefront's own GraphQL responses (not re-implementing
  Instacart's API against hardcoded URLs -- their persisted-query hashes
  change across deploys). Posts snapshots to `web`'s API.
- Single Instacart adapter covers all four stores -- verified they return
  the same response shape (`availability.available`, `availability.stockLevel`,
  `price.viewSection.priceValueString`, `size`, `productId`) regardless of
  retailer.

## Current status (2026-10-04)

`web/` is fully bootstrapped (Laravel + Breeze/Inertia/React, `npm install`
done with `--legacy-peer-deps` -- see note below) and the real import has
been run and verified against the live database, not just the CSVs:
53 orders, 663 lines, 499 distinct (store, product) pairs, 16
`negative_unverified` lines -- all matching independent recomputation.
All 5 importer tests pass.

Canonical-item matching is built (`app/Services/Matching`, review UI at
`/matching`, `php artisan canonical:suggest`); full suite is 43/43. The
live DB has the migration but **no canonical items yet** -- they get
created by hand in the UI ("New item" on a product, which pre-ticks
near-identical products at other stores), and suggestions only start
appearing once items exist. Next up: the store-assignment planner (which
should read only `confirmed` links, never `auto`), then wiring the
sidecar's run loop.

## Decisions and why

- **No per-retailer scrapers.** Everything the household buys goes through
  Instacart, including Fareway (pickup). One adapter, not five.
- **Scraping, not the Instacart developer API.** The public API is handoff-
  only (build a cart link, no availability/inventory data). Scraping a
  logged-in session was a deliberate choice the user made knowingly (ToS
  risk accepted; low volume, home-only use).
- **Line prices from the CSV exports are not trusted for spend totals.**
  Verified: summed line prices matched the order subtotal in only 32 of 53
  real orders. Order-level money fields (subtotal, fees, etc.) come only
  from the Order History export; line amounts are kept for reference
  (matching, popularity) but never summed into a total.
- **A delivered line with a negative "Price Paid" does NOT mean the item
  was uncharged/unfulfilled.** Checked against two real orders where the
  math is unambiguous: the negative lines were billed in full as part of
  the order total. Stored as `negative_unverified`, not used as an
  out-of-stock signal. Out-of-stock history, if it exists, has to come from
  `refunded` lines and live `availability_snapshots` going forward.
  (No out-of-stock example has actually been observed yet in ~60 live
  product lookups across four stores -- every item checked was in stock.)
- **Shipping Address identifies *fulfillment location*, not delivery
  destination.** A pickup order's "address" is the store you drove to.
  Confirmed: all 3 Fareway orders list a Riverside address because Fareway
  pickup-only, not because groceries were delivered there. See
  `config/grocery_planner.php` `location_resolver` -- it's a hand-maintained
  map because this is a one-time human judgement call, not something to
  infer from the data.
- **Fulfillment cost models differ by store.** Delivery has a free-delivery
  spend threshold (Hy-Vee $10, Costco $35 -- confirmed from the storefront
  header). Pickup has no delivery fee but costs a drive; moving an item to
  Fareway to hit a minimum is a different trade-off than moving it to
  Costco. The planner must not use one cost model for both.
- **Stock level is stored as free text, not an enum.** Confirmed values
  differ: `highlyInStock` / `inStock` across stores, no label at all on the
  base "in stock" level. Treat anything other than `available: true` as
  unavailable; log whatever stock-level strings actually show up over time
  rather than assuming a fixed set.
- **Canonical-item matching is a reviewed step, not an automatic import
  step.** Of 499 distinct (store, product) rows in `store_products`, only
  8 product IDs appear at more than one store, and only 8 historical items
  were bought 5+ times -- "where do I usually get milk" cannot come from
  SKUs alone. `store_products.match_status` (`unmatched` / `auto` /
  `confirmed` / `rejected`) exists so fuzzy-match suggestions get a human
  yes/no, not a silent guess. Invariant: `canonical_item_id` is set iff
  status is `auto` (a suggestion) or `confirmed`. `rejected` means the
  latest suggestion was declined; every declined (product, item) pair is
  kept in `match_rejections` so it's never re-suggested.
- **Name scoring is scaled by description length.** Plain "are the name's
  words in the description" suggested "Lemon" for lemon hummus, lemon
  seltzer, and lemon-garlic pork on the real data. Thresholds live in
  `config/grocery_planner.php` `matching`.

## What's still unverified

- Exact service-fee amounts and hard order minimums per store (only visible
  with items actually in a cart -- not tested yet to avoid touching the
  real household cart without asking first).
- Whether Instacart's search-replay approach (varying the `k` query
  variable on a captured request) works for discovering out-of-stock items,
  or whether it only returns matches for genuinely available products.
- How often Instacart's persisted-query hashes actually change in practice
  (the sidecar should degrade loudly, not silently, when a capture finds
  nothing).

## Build-environment note

The cloud workspace used to build the initial `overlay/` files had no
network access to `packagist.org` (confirmed via its proxy's egress
policy -- blocked by host, unrelated to any GitHub account/identity), so
`scripts/bootstrap-web.sh` existed to let composer/npm run with real
network access instead. That script has already been run (by hand, not by
it directly) -- `web/` is a complete Laravel install; the script is now
just a record of the steps, not something you need to re-run.
`resources/js/bootstrap.js` was never committed by that bootstrap (Breeze's
`app.jsx` imports it), so `npm run build` -- and every Breeze test that
renders a page -- failed until it was added on 2026-10-04. Also on this
Mac, `@rolldown/binding-darwin-arm64` came down empty (npm optional-deps
bug); fixed locally with `npm install --no-save --legacy-peer-deps
@rolldown/binding-darwin-arm64@1.2.12`.
`npm install` needed `--legacy-peer-deps`: Breeze's installer pinned
`vite@^8.0.0`, newer than `@vitejs/plugin-react@4.7.0`'s peer range --
unresolved as of this writing, worth revisiting if a plugin-react bump
fixes it cleanly.

## Conventions

- Instacart order IDs and product IDs are always strings. Never cast to a
  number (order IDs are 17 digits and the CSV export escapes them with a
  leading `'`; strip that on import, don't parse as int).
- Money columns from the CSV exports are approximate/non-reconciling by
  nature (see above) -- don't add assertions that expect them to sum
  cleanly.
