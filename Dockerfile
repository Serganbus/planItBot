# ── Сборка зависимостей (prod, без dev) ──────────────────────────────────────
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --no-interaction --prefer-dist
COPY . .
RUN composer dump-autoload --optimize

# ── Рантайм: один образ для php-fpm / воркера очереди / планировщика ────────
FROM php:8.4-fpm-alpine

# pdo_pgsql + redis (pecl) + opcache; mbstring/intl/zip/bcmath — стандарт для Laravel
RUN apk add --no-cache postgresql-dev icu-dev libzip-dev oniguruma-dev $PHPIZE_DEPS \
 && docker-php-ext-install pdo_pgsql mbstring intl zip bcmath opcache \
 && pecl install redis \
 && docker-php-ext-enable redis \
 && apk del $PHPIZE_DEPS

WORKDIR /var/www/html

COPY --from=vendor /app/vendor ./vendor
COPY . .

# Манифест автообнаружения пакетов собирается лениво на первом запуске
# (entrypoint делает config:cache); на этапе сборки env ещё нет.

# storage для www-data + скелет каталогов фреймворка
RUN mkdir -p storage/framework/{sessions,views,cache} storage/logs \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod +x docker/php/entrypoint.sh

EXPOSE 9000
ENTRYPOINT ["docker/php/entrypoint.sh"]
CMD ["php-fpm"]
