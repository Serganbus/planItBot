<?php

declare(strict_types=1);

namespace PlannerBot\Util;

/**
 * Работа с тегами вида #работа.
 */
final class TagParser
{
    /**
     * Извлекает теги из строки (через пробел или запятую), нормализует.
     *
     * @return string[]
     */
    public static function parseMany(string $input): array
    {
        preg_match_all('/#([\p{L}\p{N}_-]+)/u', $input, $m);

        $tags = array_map(
            fn(string $tag): string => mb_strtolower($tag),
            $m[1]
        );

        return array_values(array_unique($tags));
    }

    public static function normalize(string $input): string
    {
        return mb_strtolower(ltrim(trim($input), '#'));
    }

    /**
     * @param string[] $tags
     */
    public static function format(array $tags): string
    {
        return implode(' ', array_map(fn(string $tag): string => '#' . $tag, $tags));
    }
}
