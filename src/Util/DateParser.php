<?php

declare(strict_types=1);

namespace PlannerBot\Util;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Парсит дату из пользовательского ввода.
 * Поддерживает: YYYY-MM-DD, DD.MM.YYYY, "сегодня", "завтра", "вчера", "+N".
 */
final class DateParser
{
    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    public function parse(string $input): ?string
    {
        $input = mb_strtolower(trim($input));
        if ($input === '') {
            return null;
        }

        $today = new DateTimeImmutable('today', $this->timezone);

        $relative = match ($input) {
            'сегодня', 'today' => $today,
            'завтра', 'tomorrow' => $today->modify('+1 day'),
            'вчера', 'yesterday' => $today->modify('-1 day'),
            default => null,
        };
        if ($relative !== null) {
            return $relative->format('Y-m-d');
        }

        if (preg_match('/^\+(\d{1,3})$/', $input, $m)) {
            return $today->modify('+' . (int) $m[1] . ' days')->format('Y-m-d');
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $input, $m)) {
            return $this->normalize((int) $m[1], (int) $m[2], (int) $m[3]);
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $input, $m)) {
            return $this->normalize((int) $m[3], (int) $m[2], (int) $m[1]);
        }

        return null;
    }

    public function today(): string
    {
        return (new DateTimeImmutable('today', $this->timezone))->format('Y-m-d');
    }

    public function shift(string $date, int $days): string
    {
        return (new DateTimeImmutable($date, $this->timezone))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format('Y-m-d');
    }

    private function normalize(int $year, int $month, int $day): ?string
    {
        if (!checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
}
