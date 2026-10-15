#!/usr/bin/env bash
set -euo pipefail

APP_DIR="/var/www/ads-analitika"
PHP="${PHP_BIN:-/usr/bin/php}"
COMPOSER="${COMPOSER_BIN:-/usr/local/bin/composer}"

cd "$APP_DIR"

echo "==> Maintenance mode"
$PHP artisan down --retry=30 --secret="deploy-bypass" || true

echo "==> Composer install"
$COMPOSER install --no-dev --prefer-dist --optimize-autoloader --no-interaction

echo "==> Frontend build"
if command -v npm >/dev/null 2>&1; then
  npm ci --ignore-scripts
  npm run build
fi

echo "==> Permissions"
chown -R www-data:www-data "$APP_DIR"
chmod -R ug+rwx storage bootstrap/cache
# SQLite needs write access on the file and its directory
chmod 775 database
if [[ -f database/database.sqlite ]]; then
  chown www-data:www-data database/database.sqlite
  chmod 664 database/database.sqlite
fi

echo "==> Migrate & optimize"
$PHP artisan migrate --force --no-interaction
$PHP artisan storage:link --force --no-interaction || true
$PHP artisan config:cache
$PHP artisan route:cache
$PHP artisan view:cache
$PHP artisan event:cache || true

echo "==> Restart workers"
systemctl restart ads-analitika-queue.service || true
systemctl restart php8.4-fpm || true

echo "==> Up"
$PHP artisan up

echo "==> Deploy complete"
