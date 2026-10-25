# Planner bot

Бот, реализованный в ТГ, который позволяет управлять календарем пользователя.
Язык разработки: PHP.
Пакет, с помощью которого выполняется взаимодействие с Telegram: https://github.com/TelegramBot/Api
Пакет, с помощью которого реализован функционал календаря: https://github.com/Serganbus/simple-planner

## Архитектура

- Вход: `bin/bot.php` + `config/config.php` (конфигурация из env: `BOT_TOKEN`, `BOT_DATA_DIR`, `BOT_TIMEZONE`, `POLL_TIMEOUT`).
- Ядро: `src/BotKernel.php` — регистрация обработчиков telegram-bot/api (`command()`, `callbackQuery()`, `on()`) и цикл long polling (`getUpdates` + `handle`; метод `Client::run()` — это webhook-обработчик и не используется).
- Календарь: доменный пакет `planner/cli` (`PlannerService`, модели `Day`/`ScheduleItem`/`FlexibleTask`, хранилище `JsonDayRepository` — JSON-файл на дату).
- Мультипользовательность: данные изолированы по чатам, каталог `data/chats/{chatId}/`; сборка сервисов на чат — `src/Infrastructure/ChatContextFactory.php` (+ `ChatContext`).
- Диалоговые сценарии (FSM): `src/Flow/FlowManager.php`, состояние диалога и «текущий день» — `src/State/StateStore.php` (`data/chats/{chatId}/_state.json`).
- Интерфейс: команды + inline-кнопки (`src/Keyboard/KeyboardFactory.php`, парсинг `callback_data` — `src/Callback/CallbackRouter.php`). UUID записей в кнопках сокращаются до 8-символьного префикса из-за лимита 64 байта и резолвятся через `src/Util/IdResolver.php`.
- Отображение (parse_mode=HTML): `src/View/`.

## Соглашения

- PHP >= 8.1, `declare(strict_types=1)`, PSR-4: `PlannerBot\` → `src/`, `PlannerBot\Tests\` → `tests/`.
- Парсинг пользовательского ввода — только в `src/Util/` (дата/время/приоритет/теги/«быстрые» команды).
- Весь пользовательский текст идёт через `DayFormatter::e()` (экранирование HTML).
- Сообщения об ошибках для пользователя начинаются с ❌ и не прерывают диалог (кроме сброса по /cancel).

## Известные нюансы зависимостей

- `planner/cli` не опубликован на Packagist — подключается как VCS-репозиторий (`planner/cli: dev-master`); тянет за собой `symfony/console` и `ramsey/uuid`.
- В `planner/cli` методы `updateScheduleItem()`/`updateFlexibleTask()` используют reflection для записи в readonly-свойства и падают на PHP >= 8.4. Редактирование реализовано в `src/Service/EditService.php` (пересоздание элемента с тем же id, без reflection). Не вызывать эти vendor-методы напрямую.
- В `planner/cli` нет метода смены главной цели дня — `src/Service/GoalService.php` сохраняет `Day->mainGoal` через репозиторий чата.
- При недоступности packagist.org в `composer.json` прописано зеркало (`mirrors.aliyun.com/composer`); удалить: `composer config --unset repos.packagist.org`.
- `telegram-bot/api` на PHP 8.4 выдаёт E_DEPRECATED (implicit nullable) — в `bin/bot.php` deprecation отключены.

## Сборка, запуск, тесты

- Установка: `composer install`
- Запуск: `BOT_TOKEN=... composer bot` (long polling; Ctrl+C — остановка)
- Тесты: `composer test` (PHPUnit; unit + интеграционный smoke-тест по `planner/cli` на временном каталоге)
- Docker: `docker compose up -d --build` (переменные из `.env`/окружения: `BOT_TOKEN` обязателен, `BOT_TIMEZONE`, `POLL_TIMEOUT`; ротация логов; restart unless-stopped). Либо вручную: `docker build -t planner-bot .` + `docker run -d -e BOT_TOKEN=... -v planner-bot-data:/app/data planner-bot`. Образ: многоступенчатый Dockerfile на `php:8.4-cli-alpine`, расширения curl+mbstring, пользователь `planner`, данные — volume `/app/data` = `BOT_DATA_DIR`.
