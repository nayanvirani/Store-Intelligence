#!/bin/sh
# Railway start: a worker service can run its own command (WORKER_COMMAND, e.g. "php artisan queue:work");
# otherwise the web service migrates, caches, starts the queue worker and scheduler in the background
# (unless RUN_WORKERS=false) and serves the app with FrankenPHP.
set -e
cd /app

if [ -n "$WORKER_COMMAND" ]; then
  exec sh -c "$WORKER_COMMAND"
fi

php artisan migrate --force
php artisan storage:link || true
php artisan optimize:clear
php artisan optimize

# First super admin from Railway variables (only when no admin exists yet).
if [ -n "$SI_ADMIN_EMAIL" ] && [ -n "$SI_ADMIN_PASSWORD" ]; then
  php artisan si:admin-user "$SI_ADMIN_EMAIL" --password-from-env --only-if-none || true
fi

if [ "${RUN_WORKERS:-true}" = "true" ]; then
  echo "Starting queue worker and scheduler ..."
  ( while true; do php artisan queue:work --sleep=2 --tries=3 --max-time=3600 --memory=256 || sleep 5; done ) &
  ( while true; do php artisan schedule:work || sleep 5; done ) &
fi

echo "Starting Laravel server ..."
exec docker-php-entrypoint frankenphp run --config /Caddyfile --adapter caddyfile
