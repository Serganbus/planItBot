<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Парсит хвост «быстрой» команды: позиционные поля + теги + свободный текст.
 *
 * Схема задач (Шаг 2): пункт расписания имеет ОДНО время (нет end_time),
 * поэтому «/add 09:00 Название #тег» вместо «/add 09:00 10:30 Название #тег».
 */
final class QuickCommandParser
{
    /**
     * /add 09:00 Название #тег1 #тег2
     *
     * @return array{time:?string,title:string,tags:string[]}
     */
    public static function parseScheduled(string $args): array
    {
        $time = null;
        $titleTokens = [];

        foreach (self::tokenize($args) as $token) {
            if (str_starts_with($token, '#') && mb_strlen($token) > 1) {
                continue;
            }

            if ($time === null && ($parsedTime = TimeParser::parse($token)) !== null) {
                $time = $parsedTime;
                continue;
            }

            $titleTokens[] = $token;
        }

        return [
            'time' => $time,
            'title' => trim(implode(' ', $titleTokens)),
            'tags' => TagParser::parseMany($args),
        ];
    }

    /**
     * /task Название 60 high #тег
     *
     * @return array{title:string,minutes:?int,priority:int,tags:string[]}
     */
    public static function parseFlexible(string $args): array
    {
        $minutes = null;
        $priority = 0;
        $titleTokens = [];

        foreach (self::tokenize($args) as $token) {
            if (str_starts_with($token, '#') && mb_strlen($token) > 1) {
                continue;
            }

            if ($minutes === null && preg_match('/^\d{1,4}$/', $token)) {
                $minutes = (int) $token;
                continue;
            }

            if ($priority === 0 && PriorityParser::parse($token) !== null) {
                $priority = PriorityParser::toInt($token);
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
    private static function tokenize(string $args): array
    {
        $args = trim($args);
        if ($args === '') {
            return [];
        }

        return preg_split('/\s+/u', $args) ?: [];
    }
}
