# grocery-planner

Personal household app (Tyler + wife; wife is the only end user of the UI).
Not multi-tenant, no public users, no auth hardening needed beyond basic
household access control.

## Why this exists

Shopping list spans recipes plus recurring baby staples (milk, yogurt,
berries, cheese...), split across Hy-Vee, Costco, Sam's Club, and Fareway
(pickup-only), based on live stock and price, with
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
   your Fareway pickup store, press Enter. Saves `storage-state.json`.
3. `npm start -- product hy-vee 19036914`: debug read of one product
   page (what runs do) that posts nothing. Good first check that capture
   still works. (`npm start -- search <store> <query>` also exists.)
4. `npm run loop`: polls every 60s. A check runs when "Check stock now"
   is pressed on `/list`, or the last finished run is over 6h old.

Normally steps 1 and 4 run unattended instead: `scripts/install-launchd.sh`
installs two launchd agents (`com.grocery-planner.web`,
`com.grocery-planner.sidecar`) that start at login and restart within 30s
if they exit. The script also does a fresh `npm run build`. Logs are in
`~/Library/Logs/grocery-planner/`. Use `status` and `uninstall` as
arguments. `sidecar` installs only the sidecar agent (and removes the web
one), for when the web app is hosted elsewhere and `sidecar/.env`'s
`GROCERY_PLANNER_API_URL` points there. Re-run it after changing the PHP or Node version (it writes
absolute paths from your shell, since launchd doesn't read your profile).
A sidecar restart rebuilds `dist/`, but a PHP or asset change in `web/`
needs a re-run, or `launchctl kickstart -k gui/$(id -u)/com.grocery-planner.web`
for PHP only. Before running steps 1 or 4 by hand, run
`scripts/install-launchd.sh uninstall`, or the port and the sidecar session
are already in use.

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
  Confirmed: all 3 Fareway orders list the store's address because Fareway
  is pickup-only, not because groceries were delivered there. See
  `location_resolver` (real addresses in the gitignored
  `config/locations.local.php`) -- it's a hand-maintained
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
- **The sidecar can't run on a cloud server.** Tried 2026-10-07 on a
  DigitalOcean droplet (Forge): every Instacart page returned a CloudFront
  `403 Request blocked` before the session was even read, so it's an IP
  block, not a session problem. The web app can live on a server; the
  sidecar stays on a home machine (`install-launchd.sh sidecar`) and posts
  out to it. It only makes outbound requests, so no tunnel is needed.
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
- **Availability has to account for quantity, not just in-stock -- not yet
  built.** If a list item wants 1 gallon and a store only carries
  half-gallons, the planner needs to combine packs (two half-gallons), not
  just check whether *a* pack of the item is in stock. `store_products`
  has `size_text` (free text, e.g. "6 x 8 fl oz") and `pack_count` today,
  with no normalized quantity+unit, and `Planner.php` currently assigns one
  `store_product_id` per line with no multi-pack combination step.
  Needs, in order:
  1. A normalized quantity+unit column, sourced from Instacart's own
     `pricingUnitString` (e.g. "0.5 gal") rather than re-parsed from
     `size_text`.
  2. A per-store pack-combination step, run before `Planner::initial()`'s
     availability check, that finds every combination of available packs
     meeting or exceeding the target quantity.
  3. **Pick the closest match, not the cheapest** (household's call,
     2026-10-08: price isn't a routine concern, so don't optimize for it
     here). Among combinations that meet or exceed the target, choose the
     one with the smallest overage -- two half-gallons over one 64 fl oz
     jug if that's closer to a 1 gal target, regardless of which costs
     less. Ties (equal overage) fall through to the existing usual-store
     preference, then the price guardrail above. Assumption, not yet
     confirmed with the user: a combination is only valid if it meets or
     exceeds the target -- under-target is never chosen even if closer.
  4. **Still open, and genuinely ambiguous -- not ready to hand to a
     coding agent as-is:** the normalization scheme itself. `size_text`
     mixes volume ("0.5 gal"), weight (future items), and count/multipack
     ("6 x 8 fl oz") -- an agent needs a rule for which unit family each
     canonical item normalizes to, and whether a multipack's total
     (48 fl oz) is the comparable quantity, or whether the household
     actually wants N discrete units (6 cartons) and a multipack of 4
     doesn't satisfy "need 6 cartons" even if the fl oz total matches.
     Needs a user decision before this step is built, not an assumption.
- **Instacart already computes price-per-unit server-side -- confirmed
  live, not yet captured.** Checked 2026-10-08 against Hy-Vee's "milk"
  search: `price.viewSection.itemDetails.pricePerUnitString` (e.g.
  "$0.05/fl oz") is in the GraphQL `Items` response even though search
  cards only render total price + size. The sidecar's field extraction
  (`price.viewSection.priceValueString`, `size`) doesn't capture it yet.
  Pull it directly rather than re-deriving from `size_text`, which is free
  text and includes multipacks (e.g. "6 x 8 fl oz") where naive division
  is wrong.
- **Planner priority is availability, then usual-store/minimums, then
  price as a guardrail only** (household's call, 2026-10-08): price is
  never used to rank or pick the cheapest store across the board --
  that's what the "usual store, re-route only when needed" objective
  above already means. Price's only job is to catch a genuinely bad
  outlier: if the assigned store's price is more than 2x the cheapest
  available, quantity-sufficient option, flag or reroute (config value,
  not hardcoded -- e.g. `grocery_planner.planner.price_override_ratio`).
  Plain ratio, not ratio+floor, so a cheap item can trip it on a trivial
  dollar amount (a $0.50 item at $1.25 is 2.5x) -- noted, not designed
  around; revisit the threshold if it's noisy in real use. Not yet
  implemented in `Planner.php` -- today price is only used as a
  minimum-repair cost tie-break, never as an override on the initial
  assignment.

## Open questions before handoff (2026-10-08)

Not ready to hand the quantity-aware availability work (see "Decisions and
why" above) to a coding agent until these are answered -- both are
household judgement calls, not implementation details, and a wrong
assumption here would quietly produce bad shopping lists rather than fail
loudly.

1. **Unit family per canonical item: volume/weight total, or discrete
   units?** `size_text` mixes "0.5 gal" (volume) with multipacks like
   "6 x 8 fl oz" (count of units). For a volume/weight item (milk, flour),
   normalizing to a total (48 fl oz) is probably right. For a
   discrete-unit item (yogurt cups, eggs, diapers), the household may
   actually be asking for N *units*, not a volume total -- a 4-pack plus
   a 4-pack might overshoot both the fl oz total and the "6 cups" the
   recipe wanted, and still be the wrong buy. Needs: does this get decided
   per canonical item (a flag: "compare by total quantity" vs "compare by
   unit count"), and if so, who sets that flag when a canonical item is
   created on `/matching`?
2. **Does a combination ever fall short of target on purpose?** Current
   assumption (not yet confirmed): a pack combination is only valid if it
   meets or exceeds the target quantity -- under-target is never chosen
   even if it would otherwise be numerically closer (e.g. target 1 gal:
   0.75 gal is closer to 1 gal than 1.5 gal is, but 0.75 gal doesn't cover
   the ask, so 1.5 gal should still win). Confirm this is right before an
   agent builds the comparison logic, since "closest absolute match"
   and "closest match that still meets the target" are different
   algorithms.

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
3. **Make it run unattended.** Done 2026-10-04 with
   `scripts/install-launchd.sh` (see "Running the sidecar"). Verified:
   both jobs start, the sidecar polls the server, the LAN URL answers,
   and a `kill -9` of either job is restarted. The sidecar used to run
   under `caffeinate -s`; removed 2026-10-07 at the user's request, so
   checks run only while the Mac is awake and catch up after a wake or
   login. Not yet verified across a reboot or a full day of use. Logs
   aren't rotated (low volume).
4. **Phone access on the home network.** Set up 2026-10-04; verified
   over the LAN address from the Mac, not yet from a phone.
   - Serve with `php artisan serve --host=0.0.0.0` and built assets
     (`npm run build`, no `public/hot`). A running `npm run dev` writes
     `public/hot` pointing at 127.0.0.1:5173, so a phone gets a blank page.
   - URL: `http://Tylers-MacBook-Pro-2.local:8000` (use the `.local` name;
     the LAN IP can change).
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
network access instead. That was run by hand and `web/` is a complete
Laravel install; both `overlay/` and the script were removed on
2026-10-08 (still in git history).
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
