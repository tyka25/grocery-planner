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
  step.** Only 6 of 470 distinct historical products were ever bought at
  more than one store, and only 8 were bought 5+ times -- "where do I
  usually get milk" cannot come from SKUs alone. `store_products.match_status`
  (`unmatched` / `auto` / `confirmed` / `rejected`) exists so fuzzy-match
  suggestions get a human yes/no, not a silent guess.

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

The cloud workspace used to build the initial `overlay/` files has no
network access to `packagist.org` (confirmed via its proxy's egress
policy -- blocked by host, unrelated to any GitHub account/identity). That's
why `web/` isn't a full Laravel install yet -- see `scripts/bootstrap-web.sh`.
npm access was fine, so `sidecar/` is a real, installed, typechecked package.

## Conventions

- Instacart order IDs and product IDs are always strings. Never cast to a
  number (order IDs are 17 digits and the CSV export escapes them with a
  leading `'`; strip that on import, don't parse as int).
- Money columns from the CSV exports are approximate/non-reconciling by
  nature (see above) -- don't add assertions that expect them to sum
  cleanly.
