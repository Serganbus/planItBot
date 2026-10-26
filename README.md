# Planner bot

Telegram-бот для планирования дня: ведёт расписание (пункты со временем) и список гибких задач (оценка в минутах + приоритет), хранит главную цель дня, показывает статистику и поддерживает теги.

Функционал календаря — на пакете [Serganbus/simple-planner](https://github.com/Serganbus/simple-planner), взаимодействие с Telegram — на пакете [TelegramBot/Api](https://github.com/TelegramBot/Api).

## Требования

- PHP >= 8.4
- Composer 2

## Установка

```bash
composer install
```

> **Если packagist.org недоступен из вашей сети**: в `composer.json` уже прописано рабочее зеркало (`mirrors.aliyun.com/composer`). Если сеть имеет прямой доступ к Packagist, зеркало можно убрать: `composer config --unset repos.packagist.org`, затем `composer update`.

## Настройка

1. Создайте бота через [@BotFather](https://t.me/BotFather), получите токен.
2. Установите переменные окружения:

| Переменная | Обязательна | По умолчанию | Назначение |
|---|---|---|---|
| `BOT_TOKEN` | да | — | Токен бота от @BotFather |
| `BOT_DATA_DIR` | нет | `data/` в корне проекта | Каталог хранения данных чатов |
| `BOT_TIMEZONE` | нет | `Europe/Moscow` | Таймзона для «сегодня/завтра» |
| `POLL_TIMEOUT` | нет | `30` | Таймаут long polling (сек) |

## Запуск

```bash
composer bot
# или
BOT_TOKEN="123:abc" php bin/bot.php
```

Бот работает через long polling — публичный сервер и HTTPS не нужны. Остановка: `Ctrl+C`. При запуске бэклог апдейтов, накопившийся за время простоя, сбрасывается.

## Запуск в Docker

Образ на базе `php:8.4-cli-alpine` (~119 МБ), сборка многоступенчатая, бот работает от непривилегированного пользователя `planner`. Данные чатов сохраняются в volume `planner-bot-data` (`/app/data` внутри контейнера) и переживают пересоздание контейнера.

### Через docker compose (рекомендуется)

```bash
cp .env.example .env   # вписать BOT_TOKEN
docker compose up -d --build

docker compose logs -f bot   # логи polling'а
docker compose down          # остановка (данные в volume сохраняются)
docker compose down -v       # остановка с удалением данных
```

Compose-переменные берутся из `.env` или окружения: `BOT_TOKEN` (обязателен — без него compose падает с явной ошибкой), `BOT_TIMEZONE` и `POLL_TIMEOUT` (опциональны, есть значения по умолчанию). Задана ротация логов (json-file, 10 МБ × 3 файла) и политика `restart: unless-stopped`.

### Напрямую через docker run

```bash
docker build -t planner-bot .

docker run -d --name planner-bot --restart unless-stopped \
  -e BOT_TOKEN="123:abc" \
  [-e BOT_TIMEZONE="Europe/Moscow"] \
  -v planner-bot-data:/app/data \
  planner-bot

docker logs -f planner-bot   # логи polling'а
docker stop planner-bot      # остановка
```

Дополнительные env-переменные — см. таблицу «Настройка».

## Команды

| Команда | Описание |
|---|---|
| `/start` | Приветствие и краткая шпаргалка |
| `/help` | Полная справка |
| `/today` | План на сегодня |
| `/day [дата]` | План на дату: `YYYY-MM-DD`, `ДД.ММ.ГГГГ`, `сегодня`, `завтра`, `вчера`, `+N` |
| `/add` | Пункт расписания: пошаговый диалог или сразу `/add 09:00 10:30 Название #тег` |
| `/task` | Задача: диалог или сразу `/task Название [минуты] [low\|medium\|high] #тег` |
| `/goal [текст]` | Показать/установить главную цель текущего дня |
| `/done` | Отметить выполненное / вернуть в работу (кнопки) |
| `/edit` | Изменить запись (выбор кнопкой → поле → значение) |
| `/remove` | Удалить запись (с подтверждением) |
| `/tags` | Теги текущего дня |
| `/tag #имя` | Записи дня с указанным тегом |
| `/stats` | Статистика дня + навигация по датам |
| `/cancel` | Прервать активный диалог |

«Текущий день» — последняя открытая дата (`/today`, `/day`, кнопки ◀ ▶, добавление записей); сохраняется в состоянии чата между сообщениями.

## Хранение данных

Данные каждого чата изолированы: `data/chats/{chatId}/{YYYY-MM-DD}.json` (формат — как в `serganbus/planner-cli`). Состояние диалога и «текущий день» — в `data/chats/{chatId}/_state.json`.

## Тесты

```bash
composer test
```

Unit-тесты (парсеры, форматтеры, клавиатуры, машина состояний, диалоги) + интеграционный smoke-тест против реального `serganbus/planner-cli`.

## Структура кода

```
bin/bot.php                    — точка входа (long polling)
config/config.php              — конфигурация из переменных окружения
src/BotKernel.php              — регистрация обработчиков, цикл polling, маршрутизация команд/кнопок
src/Callback/                  — парсинг callback_data кнопок
src/Flow/                      — многошаговые диалоги (добавление/редактирование/цель)
src/Infrastructure/            — фабрика сервисов на чат (репозиторий, состояние)
src/Keyboard/                  — построение inline-клавиатур
src/Service/                   — GoalService, EditService (редактирование записей)
src/State/                     — хранилище состояния диалога на чат
src/Util/                      — парсеры дат/времени/приоритетов/тегов, резолв id по префиксу
src/View/                      — рендер дня и статистики (HTML)
```

## Известные нюансы

- До `serganbus/planner-cli` v1.0.1 методы `updateScheduleItem()`/`updateFlexibleTask()` меняли readonly-свойства через reflection и **падали на PHP >= 8.4**; в v1.0.1 это исправлено (readonly остался только у `id`). Редактирование по-прежнему выполняет `PlannerBot\Service\EditService` (пересоздание элемента с тем же id).
- `callback_data` кнопок ограничен 64 байтами — UUID записей в кнопках сокращаются до 8-символьного префикса и резолвятся поиском по префиксу (`IdResolver`).
- Библиотека `telegram-bot/api` выдаёт `E_DEPRECATED` на PHP 8.4 (неявные nullable-параметры); в `bin/bot.php` deprecation-нотисы отключены.
