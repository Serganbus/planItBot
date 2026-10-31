#!/bin/sh
set -e

# Конфиг-кэши нельзя собирать на этапе build (env ещё нет) — на старте контейнера.
php artisan config:cache
php artisan route:cache
php artisan event:cache

exec "$@"
