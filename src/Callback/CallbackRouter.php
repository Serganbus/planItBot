<?php

declare(strict_types=1);

namespace PlannerBot\Callback;

/**
 * Парсит и собирает callback_data для inline-кнопок.
 *
 * Формат: "{type}:{arg}" (лимит Telegram — 64 байта; UUID сокращаем до 8 символов).
 */
final class CallbackRouter
{
    public const PREFIX_ITEMMENU = 'im:';
    public const PREFIX_TASKMENU = 'tm:';
    public const PREFIX_EDIT = 'ed:';
    public const PREFIX_DONE = 'dn:';
    public const PREFIX_UNDONE = 'un:';
    public const PREFIX_DEL = 'rm:';
    public const PREFIX_DELOK = 'rok:';
    public const PREFIX_DAY = 'day:';
    public const PREFIX_STATDAY = 'sd:';
    public const PREFIX_STATS = 'stats';

    /**
     * @return array{0:string,1:string}|null [тип, аргумент]
     */
    public static function parse(string $data): ?array
    {
        foreach (self::prefixes() as $prefix => $type) {
            if (str_starts_with($data, $prefix)) {
                return [$type, substr($data, strlen($prefix))];
            }
        }

        return null;
    }

    public static function isValid(string $data): bool
    {
        return self::parse($data) !== null && strlen($data) <= 64;
    }

    /**
     * @return array<string,string>
     */
    private static function prefixes(): array
    {
        return [
            self::PREFIX_DELOK => 'delok',
            self::PREFIX_ITEMMENU => 'itemmenu',
            self::PREFIX_TASKMENU => 'taskmenu',
            self::PREFIX_EDIT => 'edit',
            self::PREFIX_DONE => 'done',
            self::PREFIX_UNDONE => 'undone',
            self::PREFIX_DEL => 'del',
            self::PREFIX_DAY => 'day',
            self::PREFIX_STATDAY => 'statday',
            self::PREFIX_STATS => 'stats',
        ];
    }
}
