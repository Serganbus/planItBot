<?php

declare(strict_types=1);

namespace App\Application\Dialogs\Handlers;

use App\Application\Dialogs\Contracts\DialogHandlerInterface;
use App\Application\Dialogs\DialogFlow;
use App\Application\Dialogs\DialogStep;
use App\Support\DateParser;
use DateTimeZone;

/**
 * Общие хелперы обработчиков: skip-слова и парсинг даты в таймзоне чата
 * (машина подкладывает её в payload под ключом 'timezone').
 */
abstract class AbstractDialogHandler implements DialogHandlerInterface
{
    protected const SKIP_WORDS = ['-', 'пропустить', 'skip', 'нет', 'no'];

    protected function step(DialogFlow $flow, string $name): DialogStep
    {
        return new DialogStep($flow, $name);
    }

    protected static function isSkip(string $text): bool
    {
        return in_array(mb_strtolower(trim($text)), self::SKIP_WORDS, true);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function parseDate(array $payload, string $input): ?string
    {
        return (new DateParser(self::timezoneOf($payload)))->parse($input);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function today(array $payload): string
    {
        return (new DateParser(self::timezoneOf($payload)))->today();
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected static function timezoneOf(array $payload): DateTimeZone
    {
        return new DateTimeZone((string) ($payload['timezone'] ?? 'UTC'));
    }
}
