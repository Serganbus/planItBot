<?php

declare(strict_types=1);

namespace App\Application\Telegram;

/**
 * Обработчик message.web_app_data (данные Telegram Mini App).
 *
 * Полноценная реализация — на Шаге 6 (REST API + проверка initData).
 * Ветка существует уже сейчас, чтобы роутинг апдейтов был закончен.
 */
final class WebAppDataHandler
{
    public function __construct(private readonly PlannerMessenger $telegram)
    {
    }

    public function handle(int $chatId, string $data): void
    {
        // TODO Шаг 6: разобрать payload Mini App (initData проверяется в API-части,
        // здесь только действия типа «day_opened»/ping).
        $this->telegram->sendHtml($chatId, '🧩 Mini App подключается (Шаг 6). Данные получены.');
    }
}
