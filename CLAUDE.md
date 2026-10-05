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
appearing once items exist.

The store-assignment planner is built (`app/Services/Planner`, list UI at
`/list`). It reads only `confirmed` links.

The sidecar run loop is wired (web: `app/Services/Sidecar`,
`routes/api.php`; sidecar: `npm run login | loop | once`). Web suite
72/72, sidecar 12/12. **Verified with a real logged-in run (2026-10-04)**
against a local server on a DB copy: 9 of 9 products checked across Aldi,
Costco and Hy-Vee in 45s, run `ok`, prices matching last-paid. It also
caught the first real out-of-stock item (see below). Fareway pickup
lookups verified the same day (Butternut Squash, in stock, $3.20/lb). `SIDECAR_TOKEN` is
set in both `web/.env` and `sidecar/.env`.

## Running the sidecar

1. `cd web && php artisan serve` (the API the sidecar polls).
2. `cd sidecar && npm run login`: sign in, check the home address and
   the Fareway Riverside pickup store, press Enter. Saves `storage-state.json`.
3. `npm start -- product hy-vee 19036914`: debug read of one product
   page (what runs do) that posts nothing. Good first check that capture
   still works. (`npm start -- search <store> <query>` also exists.)
4. `npm run loop`: polls every 60s. A check runs when "Check stock now"
   is pressed on `/list`, or the last finished run is over 6h old.

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
  First live out-of-stock example (2026-10-04): product 17327146 (Organic
  Blueberries Package) at both Aldi and Hy-Vee.
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
- **Stock level is stored as free text, not an enum, and `available` is
  the only source of truth.** Confirmed values differ: `highlyInStock` /
  `inStock` across stores. `stockLevel` can even contradict availability:
  the out-of-stock blueberries above came back `available: false`,
  `stockLevel: "inStock"`, while Instacart's own label read "Out of stock".
  Treat anything other than `available: true` as unavailable; log
  whatever stock-level strings show up rather than assuming a fixed set.
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
- **Planner objective: usual store, re-route only when needed** (household's
  choice over cheapest-total). Usual = pinned `item_store_prefs`, else most
  delivered purchases. Items move only for a fresh out-of-stock snapshot or
  minimum repair; hand-moved and pinned items never move. A pickup trip is
  valued at $10 (`config grocery_planner.planner.pickup_trip_cost`, the
  household's number), so a Fareway trip is added only when it beats a
  delivery fee or rescues a hard minimum, and a small Fareway order folds
  into stores already in the plan.
- **Price estimates for minimums use last-paid unit price** (`line_total /
  qty` of the latest delivered line, or a snapshot price). This doesn't
  conflict with "line prices are not trusted": they're shown as `~$`
  estimates, stored only on `plan_assignments.estimated_unit_price`, and
  never reported as spend. Delivery fees are still unverified;
  `assumed_delivery_fee` ($3.99) is a placeholder until real ones are seen.
- **Publix and ABC are disabled** (`stores.enabled = false`). Vacation
  purchases; history is kept, never planned to. Aldi, Target and Fresh
  Thyme stay enabled.
- **The web app decides what to check; the sidecar only reads pages.**
  `GET /api/sidecar/work` creates a `scrape_runs` row and returns one
  lookup per confirmed product of each list item, at enabled stores.
  Snapshots are saved only for products already in `store_products`.
- **Adding an item queues a check of just the unchecked products**
  (`trigger = 'added'`). Found in real use: an item added 37s after a
  check finished was never checked, while the banner said "Stock checked
  1 min ago". "Check stock now" still checks everything, and a pending
  full request is never narrowed. The banner also names any list items
  with no fresh snapshot ("Not checked yet: ...").
- **Stock checks read product pages, not search.** Verified 2026-10-04: a
  Hy-Vee search for "Hy-Vee Hy-Vee Half & Half" (the exact name) returned
  46 products, not including that one, though its product page showed it
  highly in stock. Search ranking can't be relied on to find a specific
  product. The product page's server-rendered HTML embeds Instacart's
  Apollo cache (`<script id="node-apollo-state">`, URL-encoded JSON) with
  the same `Items` objects search returns (productId, size, price,
  availability), scoped to the session's store and ZIP. It also reports
  out-of-stock products, which search might not. Reading it from the HTML
  doesn't depend on GraphQL query hashes at all.
- **Sidecar failures are loud by construction.** Every claimed run gets
  finished. A missing session or a sign-in redirect is `session_expired`.
  Three product pages with no data before any capture is `error`
  (catches a sign-in wall we don't recognise, or a page change). An "ok" run with zero
  captures is downgraded to `error` server-side. A run with no finish
  after 30 min is marked stalled. Auto-checks are spaced by the last
  *finished* run of any outcome, so an expired session isn't retried
  every poll. The `/list` banner shows all of these.
- **"No data on its product page" is not "out of stock".** It most
  likely means the store stopped carrying it or re-numbered it. Those are
  listed on `/list` (`scrape_runs.missing`, ok runs only) with a prompt to
  link a replacement. Only an explicit `available: false` re-routes the
  planner.
- **Never wait for `networkidle` on Instacart.** Analytics and polling
  traffic never stops, so it always hit the 30s timeout (the first real
  run failed this way). Use `domcontentloaded`. Search waits until the
  captured result count settles.
- **`php artisan serve` doesn't pass shell env vars to the server**
  (found while testing). To run the app against another DB, start
  `php -S` from `web/public` with the env vars set instead.
- **Name scoring is scaled by description length.** Plain "are the name's
  words in the description" suggested "Lemon" for lemon hummus, lemon
  seltzer, and lemon-garlic pork on the real data. Thresholds live in
  `config/grocery_planner.php` `matching`.

## Next steps (paused 2026-10-04)

All four planned phases are merged (matching, planner + list UI, sidecar
stock checks, checking newly added items). Remaining, roughly in order:

1. **Enter real household data (the user's job, in the UI).** Only 4
   canonical items, 11 confirmed products, and 0 staples exist; most of
   the 499 imported products are unlinked. Set up the regular items on
   `/matching` (milk, yogurt, berries, cheese, other baby staples), most
   bought first, and star staples so "Add staples" builds a weekly list.
2. **Verify real fees and hard minimums per store.** The planner still
   assumes a $3.99 delivery fee and no hard minimums. These only show
   with items in a cart. Do it together with the user, never alone,
   since it touches the real household cart.
3. **Make it run unattended:** launchd jobs for the web server and
   `npm run loop`, started at login and restarted on crash, with the Mac
   set not to sleep while plugged in.
4. **Phone access on the home network.** Set up 2026-10-04; verified
   over the LAN address from the Mac, not yet from a phone.
   - Serve with `php artisan serve --host=0.0.0.0` and built assets
     (`npm run build`, no `public/hot`). A running `npm run dev` writes
     `public/hot` pointing at 127.0.0.1:5173, so a phone gets a blank page.
   - URL: `http://Tylers-MacBook-Pro-2.local:8000`. The LAN IP
     (192.168.0.10) can change.
   - Local `.env` has `APP_DEBUG=false` and the LAN `APP_URL`.
   - `/register` 404s unless `REGISTRATION_OPEN=true` (config
     `grocery_planner.registration_open`). Only Katie's account exists;
     flip it on briefly to add another. Plain HTTP is fine on the home
     LAN; don't port-forward without HTTPS and hardening.
5. **Watch real use** for the items under "What's still unverified".

Leftovers: `web/CLAUDE.md` (Laravel Boost guidance) asks to install
`laravel/boost`; not done, as it's unrelated to the work so far. The
vite@8 / plugin-react peer-range mismatch (see Build-environment note)
is still open.

Local state that is not in git (by design) and not backed up:
`web/database/database.sqlite` (orders, items, lists, stock history; the
one worth backing up), `sidecar/storage-state.json` (Instacart sign-in;
recreate with `npm run login`), and `web/.env` / `sidecar/.env` (the
`SIDECAR_TOKEN` must match in both).

## What's still unverified

- Exact service-fee amounts and hard order minimums per store (only visible
  with items actually in a cart -- not tested yet to avoid touching the
  real household cart without asking first).
- What an expired session actually looks like. `looksSignedOut()` is a
  URL guess; the "no data on the first 3 pages" rule is the backstop.
- Whether `node-apollo-state` survives Instacart frontend deploys
  unchanged. If it moves, runs fail loudly with the "no product data"
  error, and `npm start -- product ...` is the quickest way to check.

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
