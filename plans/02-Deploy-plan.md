# Пошаговый план развёртывания Planner Bot на удалённом сервере (из GitHub)

Целевая среда: Ubuntu 24.04 LTS, PHP 8.4-FPM, nginx + HTTPS, PostgreSQL 17, Redis 7, supervisor.
Стек приложения: Laravel 12 + defstudio/telegraph, webhook + очередь `telegram`, Mini App (статика из `public/webapp/`).
Репозиторий: `git@github.com:<USER>/planner-bot.git`, ветка `master`.

> Перед началом: у домена (например, `bot.example.com`) настроен DNS A-запись на IP сервера.
> Все команды выполняются под пользователем `deploy` с sudo (кроме указанных иначе).

---

## Шаг 0. Базовые пакеты

```bash
sudo apt update && sudo apt -y upgrade
sudo apt -y install git curl unzip ufw ca-certificates supervisor

# файрвол
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable
```

## Шаг 1. PHP 8.4 (PPA ondrej)

```bash
sudo apt -y install software-properties-common
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt -y install php8.4-fpm php8.4-cli php8.4-pgsql php8.4-redis \
    php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip php8.4-bcmath php8.4-intl
php -v   # ожидание: 8.4.x
```

## Шаг 2. Composer

```bash
curl -sS https://getcomposer.org/installer | php
sudo mv composer.phar /usr/local/bin/composer
composer --version
```

## Шаг 3. PostgreSQL 17 (PGDG)

```bash
sudo install -d /usr/share/postgresql-common/pgdg
curl -fsSL https://www.postgresql.org/media/keys/ACCC4CF8.asc \
  | sudo gpg --dearmor -o /usr/share/postgresql-common/pgdg/apt.postgresql.org.gpg
echo "deb [signed-by=/usr/share/postgresql-common/pgdg/apt.postgresql.org.gpg] http://apt.postgresql.org/pub/repos/apt noble-pgdg main" \
  | sudo tee /etc/apt/sources.list.d/pgdg.list
sudo apt update && sudo apt -y install postgresql-17
sudo systemctl enable --now postgresql

# БД и пользователь приложения (пароль сгенерируйте и сохраните!)
sudo -u postgres psql -c "CREATE USER planner WITH PASSWORD 'СГЕНЕРИРОВАННЫЙ_ПАРОЛЬ';"
sudo -u postgres psql -c "CREATE DATABASE planner_bot OWNER planner;"
```

> Альтернатива: поднять инфраструктуру из репозитория: `docker compose up -d postgres redis`
> (тогда `DB_HOST=127.0.0.1`, `DB_PORT=5433`, Redis на 6379). Дальше предполагается нативная установка
> (PostgreSQL на стандартном 5432 — порт 5433 нужен был только на локальной машине).

## Шаг 4. Redis

```bash
sudo apt -y install redis-server
sudo systemctl enable --now redis-server
redis-cli ping   # PONG
```

## Шаг 5. Клонирование из GitHub (deploy key)

```bash
# на сервере: ключ для чтения репозитория
ssh-keygen -t ed25519 -C "deploy@$(hostname)" -f ~/.ssh/github_planner_bot -N ''
cat ~/.ssh/github_planner_bot.pub
# → вставьте ключ в GitHub: репозиторий → Settings → Deploy keys → Add (read-only достаточно)

# known_hosts, чтобы clone не спрашивал подтверждение
ssh-keyscan github.com >> ~/.ssh/known_hosts

sudo mkdir -p /var/www && sudo chown $USER:www-data /var/www
GIT_SSH_COMMAND="ssh -i ~/.ssh/github_planner_bot" \
  git clone -b master git@github.com:<USER>/planner-bot.git /var/www/planner-bot
cd /var/www/planner-bot
```

## Шаг 6. Зависимости и .env

```bash
composer install --no-dev --optimize-autoloader --no-interaction
cp .env.example .env
nano .env
```

Минимальный production-набор `.env`:

```ini
APP_NAME="Planner Bot"
APP_ENV=production
APP_DEBUG=false
APP_URL=https://bot.example.com

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=planner_bot
DB_USERNAME=planner
DB_PASSWORD=СГЕНЕРИРОВАННЫЙ_ПАРОЛЬ

QUEUE_CONNECTION=redis
REDIS_CLIENT=phpredis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

TELEGRAM_BOT_TOKEN=<токен из @BotFather>
TELEGRAM_WEBHOOK_URL=https://bot.example.com/api/telegram/webhook
TELEGRAPH_WEBHOOK_SECRET=<openssl rand -hex 32>
TELEGRAM_WEBAPP_URL=https://bot.example.com/webapp/index.html
```

Генерация секрета вебхука: `openssl rand -hex 32`.

```bash
php artisan key:generate
php artisan migrate --force
php artisan storage:link

# права
sudo chown -R $USER:www-data storage bootstrap/cache
chmod -R ug+rwx storage bootstrap/cache
chmod 600 .env

# кэши конфигурации
php artisan config:cache && php artisan route:cache && php artisan event:cache
```

## Шаг 7. nginx + TLS (Let's Encrypt)

```bash
sudo apt -y install nginx certbot python3-certbot-nginx
```

`/etc/nginx/sites-available/planner-bot`:

```nginx
server {
    listen 80;
    server_name bot.example.com;
    root /var/www/planner-bot/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_read_timeout 30;
    }

    # статика Mini App и служебные файлы отдаются напрямую, без PHP
    location ~* \.(css|js|png|jpg|jpeg|svg|ico)$ {
        expires 1h;
    }
}
```

```bash
sudo ln -s /etc/nginx/sites-available/planner-bot /etc/nginx/sites-enabled/
sudo nginx -t && sudo systemctl reload nginx

# TLS + автоматический редирект 80 → 443 (certbot сам допишет конфиг)
sudo certbot --nginx -d bot.example.com
curl -I https://bot.example.com/up   # ожидание: 200
```

> HTTPS обязателен до регистрации webhook: Telegram отклоняет http:// URL.

## Шаг 8. Воркер очереди (supervisor)

```bash
sudo cp deploy/planner-bot-worker.conf /etc/supervisor/conf.d/
sudo sed -i 's#/var/www/planner-bot#/var/www/planner-bot#g' /etc/supervisor/conf.d/planner-bot-worker.conf
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start planner-bot-worker:*
sudo supervisorctl status   # RUNNING ×2
```

## Шаг 9. Планировщик (очистка FSM-состояний и т.п.)

```bash
( crontab -l 2>/dev/null; echo "* * * * * cd /var/www/planner-bot && php artisan schedule:run >> /dev/null 2>&1" ) | crontab -
```

## Шаг 10. Регистрация webhook в Telegram

```bash
cd /var/www/planner-bot
php artisan telegram:webhook set
php artisan telegram:webhook info
# ожидание: url=https://bot.example.com/api/telegram/webhook, pending_update_count=0, без last_error
```

## Шаг 11. Регистрация Mini App у @BotFather

1. `@BotFather` → `/newapp` (или `/editapp` → Mini App URL).
2. URL: `https://bot.example.com/webapp/index.html` (то же, что `TELEGRAM_WEBAPP_URL`).
3. Название/иконка произвольные.

## Шаг 12. Приёмочная проверка

```bash
# приёмник жив, секрет обязателен:
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://bot.example.com/api/telegram/webhook \
  -H 'Content-Type: application/json' -d '{"update_id":1}'              # 403 (нет секрета)
# Mini App API без подписи:
curl -s -o /dev/null -w '%{http_code}\n' https://bot.example.com/api/webapp/day   # 401
# статика:
curl -s -o /dev/null -w '%{http_code}\n' https://bot.example.com/webapp/index.html # 200
```

- В Telegram: `/start` → бот отвечает приветствием (смотреть `supervisorctl tail planner-bot-worker-00`).
- `/today`, `/add`, кнопки, Mini App через `/app`.
- `php artisan queue:failed` — пусто.

---

## Повторные деплои (обновления из GitHub)

```bash
cd /var/www/planner-bot
GIT_SSH_COMMAND="ssh -i ~/.ssh/github_planner_bot" git pull --ff-only origin master
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan event:cache
php artisan queue:restart        # воркеры завершат текущую джобу и перезапустятся supervisor'ом
```

Откат: `git checkout <предыдущий тег/коммит>` + повторить команды; миграции откатывать только осознанно
(`migrate:rollback` затрагивает данные).

## Бэкапы (cron от root/deploy)

```bash
# ежедневный дамп БД в ~/backups, хранить 14 дней
mkdir -p ~/backups
( crontab -l 2>/dev/null; echo "15 3 * * * pg_dump -h 127.0.0.1 -U planner planner_bot | gzip > ~/backups/planner_bot-\$(date +\%F).sql.gz && find ~/backups -mtime +14 -delete" ) | crontab -

# восстановление:
gunzip -c ~/backups/planner_bot-2026-10-26.sql.gz | psql -h 127.0.0.1 -U planner planner_bot
```

## Мониторинг и эксплуатация

- `php artisan queue:failed` / `queue:retry all` — инциденты доставки Telegram.
- `php artisan telegram:webhook info` — pending/last_error со стороны Telegram.
- Логи: `storage/logs/laravel.log`, `supervisorctl tail -f planner-bot-worker-00`, `journalctl -u nginx -f`.
- Метрики Redis очереди: `redis-cli llen queues:telegram`.
- Не забывайте ротировать: `logrotate` для `storage/logs` (Laravel default channel `daily`/`single` настраивается в `config/logging.php`).

## Частые проблемы

| Симптом | Причина и решение |
|---|---|
| Telegram шлёт 403 в `webhook info` | не задан/не совпал `TELEGRAPH_WEBHOOK_SECRET` (в .env и у Telegram при `telegram:webhook set`) |
| Молчание бота | воркер не запущен (`supervisorctl status`), либо `QUEUE_CONNECTION` не redis, либо `php artisan queue:restart` не выполнен после деплоя |
| Mini App 401 | пользователь открыл не из Telegram, либо initData просрочена (> `WEBAPP_AUTH_MAX_AGE`) |
| Mini App 403 | токен бота в `.env` не совпадает с токеном, выдавшим initData (подпись HMAC) |
| `composer install` таймаутит packagist | в `composer.json` прописано зеркало aliyun — из сети сервера, где packagist доступен, удалите блок `repositories.packagist.org` и выполните `composer update` один раз (или оставьте зеркало, если недоступен и там) |
| 502 от nginx | не работает php8.4-fpm: `systemctl status php8.4-fpm` |
