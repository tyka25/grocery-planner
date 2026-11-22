# Deploying to production

Since 2026-11-22 the web app runs on a CloudPanel server behind Cloudflare.
Only `web/` is deployed. The sidecar stays on a home machine (see CLAUDE.md:
Instacart blocks cloud IPs). To feed production, its `GROCERY_PLANNER_API_URL`
is `https://grocery-planner.abbo2t.com/api`.

| | |
|---|---|
| Site | `https://grocery-planner.abbo2t.com` |
| SSH | `grocery@143.198.178.159` (the CloudPanel site user) |
| Checkout | `~/htdocs/grocery-planner.abbo2t.com` (git, tracks `origin/main`) |
| Web root | `~/htdocs/grocery-planner.abbo2t.com/web/public` |
| PHP | 8.4 |
| Database | SQLite, `web/database/database.sqlite` (no `DB_DATABASE` in `.env`) |

The server pulls from GitHub with a read-only deploy key
(`~/.ssh/github_grocery_planner`, SSH alias `github-grocery-planner`).
There's no Node on the server and no sudo, so the frontend is built on your
Mac and uploaded.

No cron job or queue worker is needed: nothing is scheduled in
`routes/console.php` and nothing is queued.

## Deploy

On the server:

```
ssh grocery@143.198.178.159
cd ~/htdocs/grocery-planner.abbo2t.com && git pull origin main
cd web
sqlite3 database/database.sqlite ".backup 'database/pre-deploy.sqlite'"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader
php artisan migrate --force
php artisan optimize
```

On your Mac, from a checkout of the same commit:

```
cd web && npm run build
rsync -rltz --delete public/build/ grocery@143.198.178.159:/home/grocery/htdocs/grocery-planner.abbo2t.com/web/public/build/
```

PHP-FPM doesn't need a reload. `php artisan optimize` rebuilds the config,
route, event and view caches; `php artisan optimize:clear` undoes them.

## Gotchas

- **`storage/framework/` isn't in git.** A fresh checkout needs
  `mkdir -p storage/framework/{cache/data,sessions,views,testing}` in `web/`,
  or sessions and compiled views fail.
- **Run `optimize:clear` before `optimize` if a path was missing.**
  `config/view.php` resolves the compiled-views path with `realpath()`, so a
  config cached while `storage/framework/views` was missing stores an empty
  path, and `optimize` then fails with "View path not found".
- **Keep `APP_KEY` and `SIDECAR_TOKEN` as they are.** Changing `APP_KEY`
  logs everyone out; changing `SIDECAR_TOKEN` breaks the sidecar until
  `sidecar/.env` on the home machine matches.
- **Log in as `grocery`, not another SSH user,** so new files belong to the
  site user.
- **The CloudPanel Root Directory setting counts from `~/htdocs/`,** so it's
  `grocery-planner.abbo2t.com/web/public`, not `web/public`.
