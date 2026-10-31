<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

/**
 * Регистрация/снятие вебхука Telegram напрямую через Bot API.
 *
 * Пакетная `telegraph:set-webhook` НЕ используется: она привязана к маршруту
 * пакета (route('telegraph.webhook')), который мы отключили в пользу своего
 * тонкого приёмника POST /telegram/webhook.
 */
class TelegramWebhookCommand extends Command
{
    protected $signature = 'telegram:webhook
                            {action=info : set | delete | info}
                            {--secret= : secret для X-Telegram-Bot-Api-Secret-Token (иначе из TELEGRAPH_WEBHOOK_SECRET)}
                            {--drop-pending-updates : сбросить необработанные апдейты}';

    protected $description = 'Управление webhook Telegram (set|delete|info)';

    public function handle(): int
    {
        $token = (string) config('telegraph.bots.main.token');

        if ($token === '') {
            $this->error('Токен не настроен: заполните TELEGRAM_BOT_TOKEN в .env.');

            return self::FAILURE;
        }

        $baseUrl = "https://api.telegram.org/bot{$token}";

        return match ((string) $this->argument('action')) {
            'set' => $this->setWebhook($baseUrl),
            'delete' => $this->deleteWebhook($baseUrl),
            'info' => $this->webhookInfo($baseUrl),
            default => $this->unknownAction(),
        };
    }

    private function setWebhook(string $baseUrl): int
    {
        $url = (string) config('telegraph.bots.main.webhook_url');

        if ($url === '') {
            $this->error('Заполните TELEGRAM_WEBHOOK_URL в .env (например, https://ваш-домен/telegram/webhook).');

            return self::FAILURE;
        }

        $secret = (string) ($this->option('secret') ?? config('telegraph.webhook.secret', ''));

        if (mb_strlen($url) > 0 && !str_starts_with($url, 'https://')) {
            $this->error('Telegram принимает webhook только по HTTPS.');
            $this->line("Сейчас: {$url}. Для локальной разработки поднимите туннель (например, ngrok).");

            return self::FAILURE;
        }

        if ($secret === '') {
            $this->warn('TELEGRAPH_WEBHOOK_SECRET пуст: запросы приёмника не проверяются. Рекомендуется задать.');
        }

        $response = Http::timeout(15)->post("{$baseUrl}/setWebhook", array_filter([
            'url' => $url,
            'secret_token' => $secret !== '' ? $secret : null,
            'drop_pending_updates' => (bool) $this->option('drop-pending-updates'),
            'max_connections' => 40,
        ], static fn ($value) => $value !== null));

        if (!$response->json('ok')) {
            $this->error('setWebhook отклонён Telegram: ' . $response->body());

            return self::FAILURE;
        }

        $this->info("✅ Webhook зарегистрирован: {$url}");
        $this->line('Проверка: php artisan telegram:webhook info');

        return self::SUCCESS;
    }

    private function deleteWebhook(string $baseUrl): int
    {
        // deleteWebhook полностью снимает URL у бота (Bot API после этого
        // снова разрешает getUpdates в polling-режиме).
        $response = Http::timeout(15)->post("{$baseUrl}/deleteWebhook", [
            'drop_pending_updates' => (bool) $this->option('drop-pending-updates'),
        ]);

        if (!$response->json('ok')) {
            $this->error('deleteWebhook отклонён Telegram: ' . $response->body());

            return self::FAILURE;
        }

        $this->info('✅ Webhook снят.');

        return self::SUCCESS;
    }

    private function webhookInfo(string $baseUrl): int
    {
        $response = Http::timeout(15)->get("{$baseUrl}/getWebhookInfo");

        if (!$response->json('ok')) {
            $this->error('getWebhookInfo отклонён Telegram: ' . $response->body());

            return self::FAILURE;
        }

        $info = $response->json('result');

        $this->line('URL: ' . (($info['url'] ?? '') !== '' ? $info['url'] : '<не задан>'));
        $this->line('Ожидают доставки: ' . (int) ($info['pending_update_count'] ?? 0));
        if (isset($info['last_error_message'])) {
            $this->warn('Последняя ошибка: ' . $info['last_error_message'] . ' (' . ($info['last_error_date'] ?? '') . ')');
        }

        return self::SUCCESS;
    }

    private function unknownAction(): int
    {
        $this->error('Неизвестное действие. Используйте: set | delete | info');

        return self::FAILURE;
    }
}
