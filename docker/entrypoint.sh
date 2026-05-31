#!/bin/sh
set -e

if [ ! -f .env ]; then
    cp .env.example .env
fi

if ! grep -q '^APP_KEY=base64:' .env; then
    php artisan key:generate --force
fi

mkdir -p database
touch database/database.sqlite
php artisan migrate --force
php artisan db:seed --class=Database\\Seeders\\SandboxCardSeeder --force

exec "$@"
