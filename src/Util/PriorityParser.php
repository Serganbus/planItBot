<?php

declare(strict_types=1);

namespace PlannerBot\Util;

/**
 * Парсит приоритет задачи: low / medium / high (+ русские синонимы).
 */
final class PriorityParser
{
    private const MAP = [
        'low' => 'low', 'l' => 'low', 'низкий' => 'low', 'низ' => 'low',
        'medium' => 'medium', 'med' => 'medium', 'm' => 'medium', 'средний' => 'medium', 'сред' => 'medium',
        'high' => 'high', 'h' => 'high', 'высокий' => 'high', 'выс' => 'high',
    ];

    public static function parse(string $input): ?string
    {
        return self::MAP[mb_strtolower(trim($input))] ?? null;
    }

    public static function label(string $priority): string
    {
        return match ($priority) {
            'low' => 'низкий',
            'high' => 'высокий',
            default => 'средний',
        };
    }
}
