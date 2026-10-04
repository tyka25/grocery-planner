# grocery-planner

A personal household grocery planner: merges recipes and recurring staples
into a shopping list, splits it across stores (Hy-Vee, Costco, Sam's Club,
Fareway pickup) based on live availability and price, and reroutes items
when a store's minimum isn't met.

## Layout

```
web/        Laravel + Inertia/React app (created by scripts/bootstrap-web.sh)
overlay/    Framework-dependent files built and validated before web/ existed
            (composer had no network access in the build environment) --
            merged into web/ by the bootstrap script; edit in place after that.
sidecar/    Node/TypeScript Playwright service that reads live Instacart
            availability and price from a logged-in browser session.
data/       Your real Instacart CSV exports (gitignored) and small test
            fixtures (committed, under web/tests/Fixtures after bootstrap).
```

## One-time setup

```
./scripts/bootstrap-web.sh
```

This creates `web/` via `composer create-project laravel/laravel`, merges in
`overlay/` (migrations, models, the Instacart CSV importer and its tests),
installs Breeze with the Inertia/React stack, and runs `npm install`.

Then:

```
cd web
# point .env at your Postgres database
php artisan migrate
vendor/bin/phpunit tests/Unit/InstacartImportTest.php
php artisan instacart:import /path/to/Order_History.csv /path/to/Purchased_Items.csv --dry-run
```

## Sidecar

```
cd sidecar
npm install
npm run typecheck
```

Not runnable end-to-end yet -- see `sidecar/src/index.ts` for the TODOs
(a persistent logged-in browser profile, session-expiry detection). The
field-extraction logic in `sidecar/src/instacart.ts` is verified against
Hy-Vee, Costco, Sam's Club, and Fareway (pickup) storefronts.

See `CLAUDE.md` for what's been verified, what hasn't, and the decisions
behind the schema.
