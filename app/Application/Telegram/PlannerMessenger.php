<?php

declare(strict_types=1);

namespace App\Application\Telegram;

use DefStudio\Telegraph\Facades\Telegraph as TelegraphFacade;
use DefStudio\Telegraph\Keyboard\Keyboard;

/**
 * Тонкий клиент отправки для бота `main`: вся работа с telegraph API
 * сосредоточена здесь (единственное место, которое «знает» токен и endpoint'ы).
 *
 * В тестах перехватывается Telegraph::fake().
 */
final class PlannerMessenger
{
    /**
     * @return int|null id отправленного сообщения (result.message_id)
     */
    public function sendHtml(int $chatId, string $html, ?Keyboard $keyboard = null): ?int
    {
        $api = TelegraphFacade::bot($this->token())->chat((string) $chatId)->html($html);

        if ($keyboard !== null && $keyboard->isFilled()) {
            $api = $api->keyboard($keyboard);
        }

        $response = $api->withoutPreview()->send();

        $messageId = $response->json('result.message_id');

        return $messageId === null ? null : (int) $messageId;
    }

    public function editHtml(int $chatId, int $messageId, string $html, ?Keyboard $keyboard = null): void
    {
        $api = TelegraphFacade::bot($this->token())->chat((string) $chatId)->html($html)->edit($messageId);

        if ($keyboard !== null && $keyboard->isFilled()) {
            $api = $api->keyboard($keyboard);
        }

        $api->withoutPreview()->send();
    }

    public function replaceKeyboard(int $chatId, int $messageId, Keyboard $keyboard): void
    {
        TelegraphFacade::bot($this->token())->chat((string) $chatId)->replaceKeyboard($messageId, $keyboard)->send();
    }

    public function answerCallback(string $queryId, string $text = '', bool $alert = false): void
    {
        // В telegraph сигнатура replyWebhook(int $callbackQueryId) — приводим.
        TelegraphFacade::bot($this->token())->replyWebhook((int) $queryId, $text, $alert)->send();
    }

    private function token(): string
    {
        return (string) config('telegraph.bots.main.token');
    }
}
