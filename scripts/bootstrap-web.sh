#!/usr/bin/env bash
# One-time setup: creates the Laravel app in web/ (needs real internet access
# for composer/npm, which this repo's own cloud-workspace build environment
# did not have) and merges in the overlay/ files that were already built and
# validated there (migrations, models, the Instacart importer + its tests).
#
# Safe to re-run: composer create-project is skipped if web/ already exists.
set -euo pipefail
cd "$(dirname "$0")/.."

if [ ! -d web/artisan ] && [ ! -f web/artisan ]; then
  echo "==> Creating Laravel app in web/"
  composer create-project laravel/laravel web --no-interaction
fi

echo "==> Merging overlay/ into web/ (migrations, models, importer, config, tests)"
cp -R overlay/app/.      web/app/
cp -R overlay/database/. web/database/
cp -R overlay/config/.   web/config/
cp -R overlay/tests/.    web/tests/

cd web

echo "==> Registering the import command"
grep -q 'ImportInstacartExports' routes/console.php 2>/dev/null || true
# Laravel 11 auto-discovers commands in app/Console/Commands -- no manual
# registration needed.

if [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate
fi

echo "==> Installing Inertia + React (Breeze)"
composer require laravel/breeze --dev --no-interaction
php artisan breeze:install react --no-interaction

echo "==> Installing JS deps"
npm install

echo ""
echo "Next steps:"
echo "  1. Point web/.env at your Postgres database, then: php artisan migrate"
echo "  2. vendor/bin/phpunit tests/Unit/InstacartImportTest.php"
echo "  3. php artisan instacart:import /path/to/Order_History.csv /path/to/Purchased_Items.csv --dry-run"
