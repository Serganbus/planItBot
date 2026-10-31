<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Парсит время HH:MM (24-часовой формат; разделитель «:» или «.»).
 */
final class TimeParser
{
    public static function parse(string $input): ?string
    {
        $input = trim($input);

        if (!preg_match('/^(\d{1,2})[:.](\d{2})$/', $input, $m)) {
            return null;
        }

        $hours = (int) $m[1];
        $minutes = (int) $m[2];

        if ($hours > 23 || $minutes > 59) {
            return null;
        }

        return sprintf('%02d:%02d', $hours, $minutes);
    }

    public static function isValidRange(string $start, string $end): bool
    {
        return $start < $end;
    }
}
