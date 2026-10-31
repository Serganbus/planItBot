# Пошаговый план развёртывания Planner Bot на сервере через Docker (из GitHub)

**Дано:** на сервере уже установлены PostgreSQL, nginx (с certbot/TLS). Ничего нового на хост не ставим.
**В Docker поднимаем:** `app` (php-fpm), `worker` (очередь `telegram` ×2), `scheduler`, `redis`.
**Снаружи контейнеров:** PostgreSQL (существующий), nginx на хосте (проксирует php-fpm контейнера app, статика — из git-чекаута), certbot (существующий).

Единственные требования к серверу: **Docker Engine + compose plugin + git**.

> Домен ниже — `bot.example.com`, репозиторий `git@github.com:<USER>/planner-bot.git`, ветка `master`.
> Работаем под пользователем с доступом к `docker`.

---

## Шаг 0. DNS и (если нужно) Docker

- DNS A-запись `bot.example.com` → IP сервера.
- Проверка: `docker version` и `docker compose version` работают без sudo (группа `docker`).

## Шаг 1. Клонирование из GitHub (deploy key)

```bash
ssh-keygen -t ed25519 -C "deploy@$(hostname)" -f ~/.ssh/github_planner_bot -N ''
cat ~/.ssh/github_planner_bot.pub
# → GitHub → репозиторий → Settings → Deploy keys → Add (read-only)

ssh-keyscan github.com >> ~/.ssh/known_hosts
git clone -b master git@github.com:<USER>/planner-bot.git ~/planner-bot
cd ~/planner-bot
```

Каталог чекаута одновременно используется хостовым nginx как источник статики (`~/planner-bot/public`).

## Шаг 2. .env для production

```bash
cp .env.example .env
nano .env
```

```ini
APP_NAME="Planner Bot"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://bot.example.com
APP_KEY=base64:<сгенерировать: openssl rand -base64 32>
LOG_CHANNEL=stderr          # логи в stdout/stderr контейнера → docker logs

# Существующий PostgreSQL.
# Вариант А (БД на этом же хосте):  DB_HOST=host.docker.internal
# Вариант Б (БД на другом хосте):   DB_HOST=<ip/домен БД>
DB_CONNECTION=pgsql
DB_HOST=host.docker.internal
DB_PORT=5432
DB_DATABASE=planner_bot
DB_USERNAME=planner
DB_PASSWORD=<существующий пароль>

# Redis — внутренний контейнер стека (порт наружу не публикуется)
QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=redis
REDIS_PORT=6379

# Telegram
TELEGRAM_BOT_TOKEN=<токен @BotFather>
TELEGRAM_WEBHOOK_URL=https://bot.example.com/api/telegram/webhook
TELEGRAPH_WEBHOOK_SECRET=<openssl rand -hex 32>
TELEGRAM_WEBAPP_URL=https://bot.example.com/webapp/index.html
```

> `APP_KEY` генерируем без PHP на хосте: `openssl rand -base64 32` → `APP_KEY=base64:<вывод>`.

## Шаг 3. Сборка и запуск стека

```bash
cd ~/planner-bot
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d
docker compose -f docker-compose.prod.yml ps
# ожидание: app/worker×2/scheduler/redis — running (redis healthy)
```

## Шаг 4. Миграции

```bash
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
```

## Шаг 5. nginx на хосте (уже установлен) → контейнер app

Добавить server-блок (TLS — как у вас уже настроено через certbot):

```nginx
server {
    listen 443 ssl;
    server_name bot.example.com;
    # ssl_certificate / ssl_certificate_key — существующие пути certbot

    root /home/<USER>/planner-bot/public;   # статика из git-чекаута
    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass 127.0.0.1:9000;        # php-fpm контейнера app
        fastcgi_param HTTPS on;
        fastcgi_read_timeout 30;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx
curl -I https://bot.example.com/up        # 200
```

> Права: nginx (пользователь www-data) должен читать `~/planner-bot/public` — достаточно `chmod o+x /home/<USER>` и `chmod -R o+r public` (или положить чекаут в /var/www).

## Шаг 6. Регистрация webhook в Telegram

```bash
docker compose -f docker-compose.prod.yml exec app php artisan telegram:webhook set
docker compose -f docker-compose.prod.yml exec app php artisan telegram:webhook info
# ожидание: url=https://bot.example.com/api/telegram/webhook, pending_update_count=0
```

## Шаг 7. Mini App у @BotFather

`@BotFather` → `/newapp` (или `/editapp`) → URL `https://bot.example.com/webapp/index.html`
(совпадает с `TELEGRAM_WEBAPP_URL`).

## Шаг 8. Приёмочная проверка

```bash
# webhook требует секрет:
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://bot.example.com/api/telegram/webhook \
  -H 'Content-Type: application/json' -d '{"update_id":1}'            # 403
# Mini App API без подписи:
curl -s -o /dev/null -w '%{http_code}\n' https://bot.example.com/api/webapp/day   # 401
# статика:
curl -s -o /dev/null -w '%{http_code}\n' https://bot.example.com/webapp/index.html # 200
```

- Telegram: `/start` → приветствие; `/add` → диалог; кнопки; `/app` → Mini App.
- `docker compose -f docker-compose.prod.yml logs -f worker` — джобы обрабатываются;
  `docker compose -f docker-compose.prod.yml exec app php artisan queue:failed` — пусто.

---

## Обновление (деплой новой версии)

```bash
cd ~/planner-bot
git pull --ff-only origin master
docker compose -f docker-compose.prod.yml build
docker compose -f docker-compose.prod.yml up -d   # пересоздаст изменённые контейнеры
docker compose -f docker-compose.prod.yml exec app php artisan migrate --force
sudo systemctl reload nginx                        # только если менялся public/
```

Откат: `git checkout <тег/коммит>` → build → up → (миграции назад — только осознанно).

## Эксплуатация

- Логи: `docker compose -f docker-compose.prod.yml logs -f app|worker|scheduler` (`LOG_CHANNEL=stderr`).
- Воркеров перезапустить: `docker compose -f docker-compose.prod.yml restart worker`.
- Упавшие джобы: `docker compose -f docker-compose.prod.yml exec app php artisan queue:failed` / `queue:retry all`.
- Очередь: `docker compose -f docker-compose.prod.yml exec redis redis-cli llen queues:telegram`.
- Бэкапы PostgreSQL — существующим процессом (БД вне стека).
- Redis-данные — named volume `planner-bot-prod-redis-data` (очередь переживает рестарт; AOF включён).

## Частые проблемы

| Симптом | Причина и решение |
|---|---|
| `app` падает на старте | `APP_KEY` пуст → сгенерируйте через `openssl rand -base64 32` в .env |
| `could not connect to DB` в контейнере | БД на хосте, а `DB_HOST=127.0.0.1` в контейнере = сам контейнер → используйте `host.docker.internal` (маппинг уже прописан в compose) |
| 502 на nginx | app не поднят / не слушает 9000: `docker compose -f docker-compose.prod.yml ps`, `logs app` |
| Telegram молчит | worker не запущен; `telegram:webhook info` покажет last_error; секрет в .env ≠ секрету при регистрации |
| Mini App 401/403 | initData от другого бота (токен в .env не тот) или просрочена |
| Образы не собираются на сервере | packagist: в composer.json стоит зеркало aliyun; при его недоступности из сети сервера — удалить блок `repositories` и один раз `composer update` локально, обновив composer.lock |
