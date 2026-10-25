<?php

declare(strict_types=1);

namespace PlannerBot\Util;

/**
 * Парсит хвост "быстрой" команды: позиционные аргументы + теги + свободный текст.
 *
 * Логика: токены разделяются пробелами; известные позиционные поля
 * распознаются по формату (время, число, приоритет) независимо от позиции,
 * токены "#тег" уходят в теги, остальное склеивается в название.
 */
final class QuickCommandParser
{
    /**
     * /add 09:00 10:30 Название #тег1 #тег2
     *
     * @return array{start:?string,end:?string,title:string,tags:string[]}
     */
    public static function parseScheduleItem(string $args): array
    {
        $start = null;
        $end = null;
        $titleTokens = [];

        foreach (self::tokenize($args) as $token) {
            if (str_starts_with($token, '#') && mb_strlen($token) > 1) {
                continue; // теги соберём отдельно из всей строки
            }

            $time = TimeParser::parse($token);
            if ($time !== null) {
                if ($start === null) {
                    $start = $time;
                    continue;
                }
                if ($end === null) {
                    $end = $time;
                    continue;
                }
            }

            $titleTokens[] = $token;
        }

        return [
            'start' => $start,
            'end' => $end,
            'title' => trim(implode(' ', $titleTokens)),
            'tags' => TagParser::parseMany($args),
        ];
    }

    /**
     * /task Название 60 high #тег
     *
     * @return array{title:string,minutes:?int,priority:?string,tags:string[]}
     */
    public static function parseFlexibleTask(string $args): array
    {
        $minutes = null;
        $priority = null;
        $titleTokens = [];

        foreach (self::tokenize($args) as $token) {
            if (str_starts_with($token, '#') && mb_strlen($token) > 1) {
                continue;
            }

            if ($minutes === null && preg_match('/^\d{1,4}$/', $token)) {
                $minutes = (int) $token;
                continue;
            }

            if ($priority === null && ($p = PriorityParser::parse($token)) !== null) {
                $priority = $p;
                continue;
            }

            $titleTokens[] = $token;
        }

        return [
            'title' => trim(implode(' ', $titleTokens)),
            'minutes' => $minutes,
            'priority' => $priority,
            'tags' => TagParser::parseMany($args),
        ];
    }

    /**
     * @return string[]
     */
    public static function tokenize(string $args): array
    {
        $args = trim($args);
        if ($args === '') {
            return [];
        }

        return preg_split('/\s+/u', $args) ?: [];
    }
}
