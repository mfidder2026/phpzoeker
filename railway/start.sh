#!/usr/bin/env bash
# Start PHP Zoeker op Railway: migraties, caches, dan web + wachtrij-worker (+ planner) in één service,
# zodat ze dezelfde volume (storage/app) delen. Zie docs/railway.md.
set -euo pipefail

cd /var/www/html

mkdir -p storage/app/private storage/app/public storage/framework/{cache,sessions,views} storage/logs bootstrap/cache

php artisan migrate --force
php artisan db:seed --force --class=RoleSeeder
php artisan db:seed --force --class=SourceSeeder
php artisan db:seed --force --class=AdminUserSeeder
php artisan storage:link || true
php artisan optimize

# Wachtrij-worker: bronnen ophalen gaat voor AI-taken. Herstart vanzelf als hij stopt.
(
    while true; do
        php artisan queue:work --queue=ingest,ai,documents,mail,default --tries=1 --timeout=900 --sleep=1 --max-time=3600 || true
        sleep 2
    done
) &

# Planner (reacties zoeken, herinneringen, digest, bewaartermijnen). Bronnen worden alleen
# automatisch opgehaald als SOURCES_AUTO_FETCH=true; anders alleen via "Nu zoeken".
if [ "${RUN_SCHEDULER:-true}" = "true" ]; then
    (
        while true; do
            php artisan schedule:work || true
            sleep 2
        done
    ) &
fi

exec php artisan serve --host=0.0.0.0 --port="${PORT:-8000}" --no-reload
