<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

/**
 * Приоритет задачи: low / medium / high (+ русские синонимы).
 *
 * В новой схеме (Шаг 2) priority — smallint: 0 = не указан,
 * low = 1, medium = 2, high = 3. Строковое parse() оставлено
 * для совместимости с семантикой пользовательского ввода.
 */
final class PriorityParser
{
    private const MAP = [
        'low' => 'low', 'l' => 'low', 'низкий' => 'low', 'низ' => 'low',
        'medium' => 'medium', 'med' => 'medium', 'm' => 'medium', 'средний' => 'medium', 'сред' => 'medium',
        'high' => 'high', 'h' => 'high', 'высокий' => 'high', 'выс' => 'high',
    ];

    private const INT_MAP = ['low' => 1, 'medium' => 2, 'high' => 3];

    public static function parse(string $input): ?string
    {
        return self::MAP[mb_strtolower(trim($input))] ?? null;
    }

    /**
     * Строка пользователя → smallint для схемы (0, если ввода нет).
     */
    public static function toInt(?string $input): int
    {
        if ($input === null || trim($input) === '') {
            return 0;
        }

        $parsed = self::parse($input);

        return $parsed === null
            ? throw new InvalidArgumentException("Неизвестный приоритет: «{$input}».")
            : self::INT_MAP[$parsed];
    }

    /**
     * smallint схемы → машинное имя приоритета.
     */
    public static function name(int $priority): ?string
    {
        return array_search($priority, self::INT_MAP, true) ?: null;
    }

    public static function label(int $priority): string
    {
        return match ($priority) {
            1 => 'низкий',
            2 => 'средний',
            3 => 'высокий',
            default => 'не указан',
        };
    }
}
