<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверка секрета вебхука Telegram: заголовок X-Telegram-Bot-Api-Secret-Token
 * сверяется с TELEGRAPH_WEBHOOK_SECRET (constant-time). Токен бота в URL
 * вообще не используется.
 *
 * Если секрет не настроен (локальная разработка) — пропускаем,
 * но это должно быть явным решением окружения.
 */
class VerifyTelegramWebhookSecret
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = (string) config('telegraph.webhook.secret', '');

        if ($secret === '') {
            return $next($request);
        }

        $given = $request->header('X-Telegram-Bot-Api-Secret-Token');

        if (!is_string($given) || $given === '' || !hash_equals($secret, $given)) {
            abort(403);
        }

        return $next($request);
    }
}
