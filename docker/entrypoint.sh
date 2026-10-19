#!/bin/sh
set -e

echo "[entrypoint] starting pitstop"

# Railway assigns PORT at runtime; bake it into the nginx config.
export PORT="${PORT:-8080}"
envsubst '${PORT}' < /etc/nginx/nginx.conf > /tmp/nginx.conf
mv /tmp/nginx.conf /etc/nginx/nginx.conf
echo "[entrypoint] nginx will listen on ${PORT}"

# A mounted volume shadows whatever the image had at this path, so the Laravel
# storage skeleton must be recreated on every boot or the first request 500s.
mkdir -p \
  /var/www/html/storage/app/private \
  /var/www/html/storage/app/public \
  /var/www/html/storage/framework/cache/data \
  /var/www/html/storage/framework/sessions \
  /var/www/html/storage/framework/views \
  /var/www/html/storage/logs \
  /var/www/html/bootstrap/cache

chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache
chmod -R ug+rwX /var/www/html/storage /var/www/html/bootstrap/cache

if [ -z "${APP_KEY}" ]; then
  echo "[entrypoint] FATAL: APP_KEY is not set. Generate one with:"
  echo "            php artisan key:generate --show"
  echo "            and set it in the Railway service variables."
  exit 1
fi

# Fail fast and loudly rather than silently accepting unauthenticated webhooks.
if [ -z "${HELPDESK_INBOUND_SECRET}" ]; then
  echo "[entrypoint] WARNING: HELPDESK_INBOUND_SECRET is unset."
  echo "             The inbound webhook will reject every request with 503."
fi

echo "[entrypoint] running migrations"
php artisan migrate --force --no-interaction

php artisan storage:link --force 2>/dev/null || true

echo "[entrypoint] caching config, routes and views"
php artisan config:cache
php artisan route:cache
php artisan view:cache

# Creates the first admin when HELPDESK_ADMIN_EMAIL/PASSWORD are set.
# Non-fatal: a failure here must not stop the app from serving.
php artisan helpdesk:create-admin || true

echo "[entrypoint] ready"
exec "$@"
