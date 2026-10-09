# Development setup

How to work on `web/` and `sidecar/` locally. This is separate from the
unattended launchd install described in the README.

## 1. Stop the always-on install first

The launchd agents hold port 8000 and use the Instacart session. If you skip
this, `php artisan serve` and `npm run loop` will clash with them.

```
scripts/install-launchd.sh uninstall
```

Re-run `scripts/install-launchd.sh` when you're done (see step 4).

## 2. Web app (`web/`)

You need PHP 8.3+, Composer and Node 22.9+.

```
cd web
composer install
npm install --legacy-peer-deps
cp .env.example .env && php artisan key:generate
php artisan migrate
```

- **Don't use `composer setup`.** It runs `npm install` without
  `--legacy-peer-deps`, and that fails on the vite@8 / plugin-react
  peer-range mismatch.
- **Apple Silicon:** if the build fails with a rolldown error, run
  `npm install --no-save --legacy-peer-deps @rolldown/binding-darwin-arm64@1.2.12`.
  On this Mac that package installed empty.
- **Data:** either import the real CSVs in `data/`:
  ```
  php artisan instacart:import ../data/INSTACART_Order_History-2026_Order_Report_.csv ../data/INSTACART_Purchased_Items-2026_Order_Report.csv
  ```
  or run `php artisan db:seed` for a `test@example.com` / `password` user.
- **Work on a copy of the database.** `database/database.sqlite` is the
  household's real data and it isn't backed up. Copy it and point
  `DB_DATABASE` in `.env` at the copy. Setting it as a shell variable
  doesn't work, because `php artisan serve` doesn't pass shell variables
  through. If you need that, start `php -S` from `web/public` instead.

To run it:

```
composer dev        # starts the server, queue, log tail and Vite together
```

**Watch out:** Vite leaves a `public/hot` file pointing at 127.0.0.1:5173.
While that file is there, a phone (or any other device) gets a blank page.
Afterwards, delete `public/hot` and run `npm run build` before reinstalling
launchd.

Tests: `php artisan test`

## 3. Sidecar (`sidecar/`)

```
cd sidecar
npm install
npx playwright install chromium
cp .env.example .env
```

In `sidecar/.env`, set `SIDECAR_TOKEN` to the same value as in `web/.env`.
Leave `GROCERY_PLANNER_API_URL=http://localhost:8000/api` as it is.

Then:

```
npm run login                        # sign in, check home address + Fareway Riverside store, press Enter
npm start -- product hy-vee 19036914 # reads one product page and posts nothing: the safe first check
npm run once                         # one real run against your local web app
npm run loop                         # polls every 60s, like production
npm test
```

- If `storage-state.json` already exists, you can skip `login` until the
  session expires.
- The `login`, `once` and `loop` scripts rebuild `dist/` themselves.
  `npm start` doesn't, so run `npm run build` first after editing any
  TypeScript.
- The sidecar has to run on a home machine. Instacart blocks cloud IPs with
  a 403.

## 4. When you're done

```
rm -f web/public/hot && (cd web && npm run build)
scripts/install-launchd.sh
```

The script rebuilds both apps and restarts the agents.
