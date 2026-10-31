<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Проверка WebApp initData (Telegram Mini App).
 *
 * Алгоритм (зафиксирован в плане и совпадает с документацией Telegram):
 *  1. initDataRaw — заголовок X-Telegram-Init-Data или поле initData тела.
 *  2. query string → пары (url-decode ключей И значений), ключ `hash` убираем.
 *  3. data_check_string = "key=value", ключи отсортированы лексикографически, "\n".
 *  4. secret_key = hash_hmac('sha256', BOT_TOKEN, 'WebAppData', true)
 *     ('WebAppData' — КЛЮЧ hmac, токен — данные; НЕ hash('sha256', token)!)
 *  5. calculated = hash_hmac('sha256', data_check_string, secret_key).
 *  6. hash_equals(calculated, hash), иначе 403.
 *  7. auth_date: возраст ≤ services.telegram.webapp_auth_max_age (по умолч. 1 ч), иначе 401.
 *  8. user JSON → в request attributes ТОЛЬКО проверенные id/username.
 *     chat_id НЕ берётся из тела запроса: для личного чата он равен
 *     проверенному user.id (привязка «чат = владелец мини-аппа»).
 */
class ValidateTelegramWebApp
{
    public const ATTR_USER_ID = 'telegram_user_id';

    public const ATTR_USERNAME = 'telegram_username';

    public const ATTR_CHAT_ID = 'telegram_chat_id';

    private const FUTURE_SKEW_SECONDS = 300;

    public function handle(Request $request, Closure $next): Response
    {
        $initDataRaw = (string) ($request->header('X-Telegram-Init-Data') ?? $request->input('initData', ''));

        if ($initDataRaw === '') {
            abort(401, 'Отсутствуют данные авторизации Telegram Mini App.');
        }

        $token = (string) config('telegraph.bots.main.token');
        if ($token === '') {
            abort(500, 'TELEGRAM_BOT_TOKEN не настроен.');
        }

        [$pairs, $receivedHash] = $this->parsePairs($initDataRaw);

        if ($receivedHash === '' || !$this->isSignatureValid($pairs, $receivedHash, $token)) {
            abort(403, 'Недействительная подпись initData.');
        }

        if (!$this->isAuthDateFresh((int) ($pairs['auth_date'] ?? 0))) {
            abort(401, 'initData просрочены.');
        }

        try {
            $user = json_decode((string) ($pairs['user'] ?? ''), true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            abort(401, 'В initData нет валидного пользователя.');
        }
        $userId = (int) ($user['id'] ?? 0);

        if ($userId <= 0) {
            abort(401, 'В initData нет валидного пользователя.');
        }

        // Только проверенные данные — в атрибуты запроса.
        $request->attributes->set(self::ATTR_USER_ID, $userId);
        $request->attributes->set(self::ATTR_USERNAME, is_string($user['username'] ?? null) ? $user['username'] : null);
        $request->attributes->set(self::ATTR_CHAT_ID, $userId);

        return $next($request);
    }

    /**
     * @return array{0: array<string,string>, 1: string}
     */
    private function parsePairs(string $initDataRaw): array
    {
        $pairs = [];
        $hash = '';

        foreach (explode('&', $initDataRaw) as $pair) {
            if ($pair === '') {
                continue;
            }

            $eqPos = strpos($pair, '=');
            $key = rawurldecode($eqPos === false ? $pair : substr($pair, 0, $eqPos));
            $value = rawurldecode($eqPos === false ? '' : substr($pair, $eqPos + 1));

            if ($key === 'hash') {
                $hash = $value;
                continue;
            }

            $pairs[$key] = $value;
        }

        return [$pairs, $hash];
    }

    /**
     * @param array<string,string> $pairs
     */
    private function isSignatureValid(array $pairs, string $receivedHash, string $token): bool
    {
        ksort($pairs, SORT_STRING);

        $dataCheckString = implode("\n", array_map(
            static fn (string $key, string $value): string => $key . '=' . $value,
            array_keys($pairs),
            $pairs,
        ));

        $secretKey = hash_hmac('sha256', $token, 'WebAppData', true);
        $calculated = hash_hmac('sha256', $dataCheckString, $secretKey);

        return hash_equals($calculated, $receivedHash);
    }

    private function isAuthDateFresh(int $authDate): bool
    {
        if ($authDate <= 0) {
            return false;
        }

        $now = time();

        if ($authDate > $now + self::FUTURE_SKEW_SECONDS) {
            return false; // слишком «будущая» дата — подозрительно
        }

        $maxAge = (int) config('services.telegram.webapp_auth_max_age', 3600);

        return ($now - $authDate) <= $maxAge;
    }
}
