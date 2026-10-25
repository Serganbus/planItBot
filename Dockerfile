# syntax=docker/dockerfile:1

# ─────────────────────────────────────────────────────────────────────────────
# Базовый образ: PHP 8.4 CLI на Alpine + нужные расширения.
#   curl     — HTTP-запросы telegram-bot/api
#   mbstring — работа со строками (бот + symfony/console из planner/cli)
# ─────────────────────────────────────────────────────────────────────────────
FROM php:8.4-cli-alpine AS base

RUN apk add --no-cache --virtual .build-deps $PHPIZE_DEPS curl-dev oniguruma-dev \
    && docker-php-ext-install curl mbstring \
    && apk del .build-deps \
    && apk add --no-cache libcurl oniguruma

# ─────────────────────────────────────────────────────────────────────────────
# Сборка production-зависимостей (composer.lock → vendor/)
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS vendor

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

WORKDIR /app
COPY composer.json composer.lock ./

RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --prefer-dist \
        --optimize-autoloader

# ─────────────────────────────────────────────────────────────────────────────
# Рантайм: только код и vendor из стадии сборки, запуск от непривилегированного
# пользователя. Токен и прочие настройки передаются через env при запуске.
# ─────────────────────────────────────────────────────────────────────────────
FROM base AS runtime

WORKDIR /app

COPY --from=vendor /app/vendor ./vendor
COPY composer.json ./
COPY bin ./bin
COPY config ./config
COPY src ./src

RUN adduser -D -H planner \
    && mkdir -p /app/data \
    && chown -R planner:planner /app/data

USER planner

# Каталог с данными чатов — монтируйте volume, чтобы не потерять при пересоздании
ENV BOT_DATA_DIR=/app/data
VOLUME ["/app/data"]

CMD ["php", "bin/bot.php"]
