<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use DateTimeZone;
use PlannerBot\View\DayFormatter;
use PlannerBot\View\StatsFormatter;
use PHPUnit\Framework\TestCase;

final class StatsFormatterTest extends TestCase
{
    public function testFormatsStats(): void
    {
        $formatter = new StatsFormatter(new DayFormatter(new DateTimeZone('Europe/Moscow')));

        $text = $formatter->format('2026-10-24', [
            'schedule_total' => 4,
            'schedule_done' => 2,
            'tasks_total' => 2,
            'tasks_done' => 1,
            'total_estimated_minutes' => 90,
        ]);

        self::assertStringContainsString('2026-10-24', $text);
        self::assertStringContainsString('Расписание: 2/4', $text);
        self::assertStringContainsString('Задачи: 1/2', $text);
        self::assertStringContainsString('1 ч 30 мин', $text);
        self::assertStringContainsString('50%', $text);
    }

    public function testFormatsEmptyStats(): void
    {
        $formatter = new StatsFormatter(new DayFormatter(new DateTimeZone('Europe/Moscow')));

        $text = $formatter->format('2026-10-24', [
            'schedule_total' => 0,
            'schedule_done' => 0,
            'tasks_total' => 0,
            'tasks_done' => 0,
            'total_estimated_minutes' => 0,
        ]);

        self::assertStringContainsString('0%', $text);
    }
}
