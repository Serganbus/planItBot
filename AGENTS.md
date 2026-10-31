# Planner bot

Telegram-бот планировщика дня (расписание, гибкие задачи, главная цель) + Mini App.
Стек: **Laravel 12, PHP 8.4, PostgreSQL, Redis, defstudio/telegraph** (webhook + очередь, без long polling). Развёртывание: README.md.

## Архитектура

- **Вебхук-приёмник**: `POST /api/telegram/webhook` (`app/Http/Controllers/TelegramWebhookController.php`) → `insertOrIgnore` в `telegram_updates` (идемпотентность, UNIQUE(chat_id, update_id)) → `App\Jobs\ProcessTelegramUpdateJob` → HTTP 200. Секрет: middleware `VerifyTelegramWebhookSecret` (`X-Telegram-Bot-Api-Secret-Token` ↔ `TELEGRAPH_WEBHOOK_SECRET`). api-группа — CSRF отсутствует структурно.
- **Воркер**: `queue:work redis --queue=telegram` (supervisor `deploy/planner-bot-worker.conf`). Джоб: `tries=3`, `backoff=[5,30,120]`, `ShouldBeUnique`; второй уровень идемпотентности — TX-захват `processed_at IS NULL`, при ошибке право возвращается.
- **Роутинг апдейтов**: `app/Application/Telegram/PlannerUpdateRouter.php` (команды `/start /help /today /day /add /task /goal /done /edit /remove /stats /tags /tag /app /cancel` + plain text в FSM), `PrefixCallbackDispatcher` (callback `action:done;id:8hex`, ≤ 64 байт), `KeyboardFactory`, `DayViewRenderer`, `PlannerMessenger` (единственная точка токена: `Telegraph::bot(token)->chat(id)`).
- **Домен** (без Telegram/HTTP): `app/Domain/` — immutable-DTO `Task/DayPlan/DialogState`, enum'ы `TaskType/TaskStatus`, сервисы `Domain/Services/{TaskService,GoalService,DayPlanService}`, контракты `Domain/Contracts/`. Реализации: `app/Infrastructure/Persistence/{PostgresTaskRepository,EloquentStateRepository,PostgresChatRepository}` (биндинги в `AppServiceProvider`).
- **FSM**: `app/Application/Dialogs/` — `DialogStateMachine` + обработчики с чистыми переходами; состояние только в `dialog_states` (TTL `DIALOG_TTL_MINUTES`=30 мин; очистка `php artisan dialogs:purge-stale`, hourly). `/cancel`/«отмена»/кнопка сбрасывают.
- **Mini App**: middleware `ValidateTelegramWebApp` (alias `telegram.webapp`) — HMAC initData (`secret=HMAC_SHA256(token,key='WebAppData')`), TTL `WEBAPP_AUTH_MAX_AGE`=3600; user.id = chat_id. REST `GET /api/webapp/day`, `POST /api/webapp/tasks`, `PATCH/DELETE /api/webapp/tasks/{uuid}`, `POST /api/webapp/goal` (контроллер `app/Http/Controllers/Api/WebApp/TaskController.php`, throttle 60/мин). Статика: `public/webapp/`.
- **Парсеры ввода**: `app/Support/` (дата/время/приоритет/теги/быстрые команды/IdResolver с `AmbiguousTaskIdException`).
- **telegraph**: пакетный webhook-роут отключён (`telegraph.webhook.url=null`); бот — собственная секция `telegraph.bots.main` в `config/telegraph.php` (сам пакет её не читает); пакетные `telegraph_bots/telegraph_chats` таблицы существуют, но домен их не использует (доменный `chats` — отдельная сущность, связь только по chat_id). Клиент: `app/Telegraph/Bots/PlannerBot.php`. Управление вебхуком: `php artisan telegram:webhook {set|delete|info}` (пакетная команда не подходит — жёстко биндит роут пакета).

## Соглашения

- PHP >= 8.4, `declare(strict_types=1)`, readonly DTO, enums. PSR-4: `App\` → `app/`, `Tests\` → `tests/`.
- Бизнес-логика — только в `app/Domain`; контроллеры/роутеры — валидация + трансформация.
- В очередь — только scalar/массив payload (никаких Request/Closure/Eloquent/Telegraph).
- Всё, что принимает chatId, обязано проверять принадлежность (IDOR).
- Текст пользователя в ответах — через `DayViewRenderer::e()` (HTML escape); ошибки пользователю — с ❌.

## Инфраструктура и запуск

- Docker: `docker compose up -d --wait` → postgres:17-alpine (хостовый порт **5433**, не 5432!) + redis:7-alpine (6379). Локальные креды: `planner/planner_secret`.
- Production в контейнерах: корневой `Dockerfile` (php8.4-fpm-alpine multi-stage, pgsql+redis ext), `docker-compose.prod.yml` (app=php-fpm на 127.0.0.1:9000 / worker ×2 / scheduler / redis), entrypoint `docker/php/entrypoint.sh` (config/route/event cache на старте). План: `plans/03-Deploy-docker-plan.md`; хостовый nginx → fastcgi_pass 127.0.0.1:9000, статика Mini App из git-чекаута.
- Установка/Миграции: `composer install && php artisan key:generate && php artisan migrate --seed` (демо-чат 42).
- Тестовая БД: `planner_bot_test` (в том же контейнере; `CREATE DATABASE planner_bot_test OWNER planner`), креды в `phpunit.xml`.
- Тесты: `composer test` (`php artisan test`, Pest 3 — ~80 тестов Feature+Unit на реальном PG). Конкурентность `completeTask` — двумя PHP-процессами (`tests/Feature/Domain/ConcurrencyTest.php`).
- Статанализ: `composer analyse` — PHPStan/Larastan **уровень 6** (`phpstan.neon`, пути app/config/routes/database; `app/Models` исключены).

## Известные нюансы

- Зеркало Composer в `composer.json` (`mirrors.aliyun.com/composer`) — packagist.org из офисной сети недоступен. Из-за зеркала часть зависимостей зафиксирована на dev-ветках (при доступности packagist — `composer update`).
- `defstudio/telegraph` v1.66 — максимум, видимый через зеркало (на packagist новее). Сигнатуры сверяй по `vendor/defstudio/telegraph`, а не по свежему README.
- composer в PowerShell: `^` в версиях съедается — пиши `composer require vendor/pkg` без версии.
- phpunit-шим от Pest: прямой вызов `vendor/bin/phpunit` отклоняется — используй `vendor/bin/pest`/`composer test`.
- В `CallbackDispatcher`/тестах `callback_query_id` telegraph требует int (Bot API отдаёт строку — приводим).
