# Planner Bot

Telegram-бот планировщика дня: расписание, гибкие задачи с оценкой и приоритетом, главная цель дня, мини-приложение (Mini App).

**Стек:** Laravel 12 · PHP 8.4 · PostgreSQL · Redis · [defstudio/telegraph](https://docs.defstudio.it/telegraph) · webhook + очередь (без long polling).

## Архитектура одной строкой

```
Telegram → POST /api/telegram/webhook → telegram_updates (идемпотентность)
         → Redis (queue:work) → доменные сервисы (app/Domain) → Telegraph API
Mini App → /api/webapp/* (HMAC-подпись initData) → те же доменные сервисы
```

Единый домен не зависит от транспорта: и бот, и REST API работают через `TaskService`/`DayPlanService`/`GoalService`.

## Быстрый старт (разработка)

```bash
composer install
cp .env.example .env
php artisan key:generate

# инфраструктура (PostgreSQL :5433, Redis :6379)
docker compose up -d --wait

php artisan migrate --seed        # демо-чат 42 с примерами задач
php artisan serve                 # http://localhost:8000
```

Тесты: `composer test`. Статанализ (PHPStan/Larastan, уровень 6): `composer analyse`.

## Конфигурация (.env)

Обязательные: `TELEGRAM_BOT_TOKEN`. Для продакшена дополнительно:

| Переменная | Назначение |
|---|---|
| `DB_*` | PostgreSQL (в docker-compose уже согласованы: `planner/planner_secret`, хост `5433`) |
| `REDIS_*` | Redis (`QUEUE_CONNECTION=redis`) |
| `APP_URL` | публичный HTTPS-URL приложения |
| `TELEGRAM_WEBHOOK_URL` | `https://domain/api/telegram/webhook` |
| `TELEGRAPH_WEBHOOK_SECRET` | секрет проверки заголовка webhook (задайте!) |
| `TELEGRAM_WEBAPP_URL` | `https://domain/webapp/index.html` — кнопка Mini App |
| `WEBAPP_AUTH_MAX_AGE` | TTL initData Mini App, сек (по умолчанию 3600) |
| `DIALOG_TTL_MINUTES` | TTL FSM-состояний, мин (по умолчанию 30) |

## Развёртывание (production)

Два пути (оба задокументированы в `plans/`):
- **`plans/03-Deploy-docker-plan.md`** — всё в контейнерах (app/worker/scheduler/redis), на хосте только nginx+certbot+PostgreSQL (Dockerfile.prod-образ проверен сборкой).
- Ниже — нативный вариант (PHP-FPM на хосте): `plans/02-Deploy-plan.md` расширяет его пошагово.

### 1. Nginx + PHP-FPM

```nginx
server {
    listen 443 ssl;
    server_name bot.example.com;
    root /var/www/planner-bot/public;

    ssl_certificate     /etc/letsencrypt/live/bot.example.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/bot.example.com/privkey.pem;

    index index.php;
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    }
}
```

HTTPS обязателен: Telegram принимает webhook только по TLS.

### 2. Приложение

```bash
composer install --no-dev --optimize-autoloader
php artisan key:generate   # один раз
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

### 3. Вебхук

```bash
php artisan telegram:webhook set    # читает TELEGRAM_WEBHOOK_URL + TELEGRAPH_WEBHOOK_SECRET
php artisan telegram:webhook info   # проверка
```

### 4. Воркер очереди (supervisor)

`deploy/planner-bot-worker.conf` → `/etc/supervisor/conf.d/`, затем `supervisorctl reread && update`. Внутри файла — готовый эквивалент systemd unit.

### 5. Планировщик (очистка просроченных диалогов и пр.)

Cron: `* * * * * cd /var/www/planner-bot && php artisan schedule:run >> /dev/null 2>&1`

### 6. Бэкапы PostgreSQL

```bash
# дамп
pg_dump -h 127.0.0.1 -p 5433 -U planner planner_bot | gzip > backup-$(date +%F).sql.gz
# восстановление
gunzip -c backup-2026-10-26.sql.gz | psql -h 127.0.0.1 -p 5433 -U planner planner_bot
```
В Docker: `docker compose exec -T postgres pg_dump -U planner planner_bot | gzip > backup-$(date +%F).sql.gz`.

### 7. Мониторинг failed_jobs

```bash
php artisan queue:failed            # список упавших задач
php artisan queue:retry <uuid>      # повторить конкретную
php artisan queue:retry all         # повторить все после инцидента
```
Джоба телеграм-апдейтов: 3 попытки, backoff 5/30/120 c; повторная доставка update_id дубли не создаёт (идемпотентность на уровне БД).

## Команды artisan (собственные)

| Команда | Назначение |
|---|---|
| `telegram:webhook set|delete|info` | управление webhook |
| `dialogs:purge-stale` | очистка просроченных FSM-состояний (в планировщике hourly) |

## Структура

```
app/
  Application/Dialogs/      FSM: DialogStateMachine + обработчики (чистые переходы)
  Application/Telegram/     роутинг апдейтов, клавиатуры, рендер дня, messenger
  Console/Commands/         telegram:webhook, dialogs:purge-stale
  Domain/                   Task/DayPlan/DialogState DTO, enum'ы, сервисы, контракты
  Http/Controllers|Middleware/  webhook-приёмник, WebApp API, подпись initData
  Infrastructure/Persistence/  Postgres/Eloquent реализации репозиториев
  Jobs/ProcessTelegramUpdateJob
  Models/, Providers/, Support/ (парсеры ввода)
database/migrations|seeders  доменная схема + демо-данные
deploy/planner-bot-worker.conf supervisor/systemd для queue:work
docker-compose.yml           postgres:17 + redis:7 (для разработки)
public/webapp/               статика Mini App (vanilla JS, только вызовы API)
```
