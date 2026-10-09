# grocery-planner

A personal household grocery planner. It turns recipes and recurring
staples into one shopping list, splits it across stores (Hy-Vee, Costco,
Sam's Club, Fareway pickup, and others) using live stock and price from
Instacart, and re-routes items when something is out of stock or a store's
minimum isn't met.

## Layout

```
web/        Laravel + Inertia/React app: CSV import, item matching (/matching),
            the store planner and shopping list (/list), and the sidecar API.
sidecar/    Node/TypeScript + Playwright. Reads live Instacart stock and price
            from a logged-in session and posts it to web/.
scripts/    install-launchd.sh runs both of the above unattended.
data/       Your real Instacart CSV exports (gitignored) and small fixtures.
```

## Setup

Requires PHP 8.3+, Composer, and Node 22.9+. The database is SQLite
(`web/database/database.sqlite`).

```
cd web
composer install
npm install --legacy-peer-deps
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan instacart:import /path/to/Order_History.csv /path/to/Purchased_Items.csv
npm run build
```

Set `SIDECAR_TOKEN` to the same value in `web/.env` and `sidecar/.env`.

```
cd sidecar
npm install
npx playwright install chromium
npm run login   # sign in to Instacart, check the address and Fareway store, press Enter
```

## Running

```
scripts/install-launchd.sh            # install and start (also: status, uninstall)
scripts/install-launchd.sh sidecar    # only the sidecar, when the web app is hosted elsewhere
```

This runs the web server and the sidecar loop as launchd agents. They start
at login and restart if they crash. Stock is only checked while the Mac is
awake; a check that came due during sleep runs after it wakes. Logs are in `~/Library/Logs/grocery-planner/`. Re-run the script after
changing PHP or Node versions, or after pulling changes to `web/`.

The app is then at `http://Tylers-MacBook-Pro-2.local:8000` from any device
on the home network. Stock is checked when "Check stock now" is pressed on
`/list`, when an item is added, and automatically every 6 hours.

To run things by hand instead, uninstall the agents first, then use
`php artisan serve` in `web/` and `npm run loop` in `sidecar/`.
`npm start -- product <store> <productId>` in `sidecar/` reads one product
page without posting anything, which is the quickest check that stock reading
still works.

## Accounts

`/register` is closed by default. To add an account, set
`REGISTRATION_OPEN=true` in `web/.env`, register, then set it back to `false`.

## Tests

```
cd web && php artisan test
cd sidecar && npm test
```

See `CLAUDE.md` for what's been verified, what hasn't, the decisions behind
the design, and the remaining next steps.
