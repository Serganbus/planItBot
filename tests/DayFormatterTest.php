<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use DateTimeZone;
use Planner\Domain\Model\Day;
use Planner\Domain\Model\FlexibleTask;
use Planner\Domain\Model\ScheduleItem;
use PlannerBot\View\DayFormatter;
use PHPUnit\Framework\TestCase;

final class DayFormatterTest extends TestCase
{
    private DayFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new DayFormatter(new DateTimeZone('Europe/Moscow'));
    }

    public function testFormatsDayWithContent(): void
    {
        $day = new Day('2026-10-24', 'выкатить MVP');
        $day->addScheduleItem(new ScheduleItem(
            '11111111-2222-3333-4444-555555555555',
            '09:00', '10:00', 'Стендап <скрипты>', ['работа'], 'done',
        ));
        $day->addScheduleItem(new ScheduleItem(
            '22222222-2222-3333-4444-555555555555',
            '12:00', '13:00', 'Обед', [], 'pending',
        ));
        $day->addFlexibleTask(new FlexibleTask(
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'Отчёт', 60, 'high', ['работа'], 'pending',
        ));

        $text = $this->formatter->format($day);

        self::assertStringContainsString('2026-10-24', $text);
        self::assertStringContainsString('сб', $text); // 2026-10-24 — суббота
        self::assertStringContainsString('выкатить MVP', $text);
        self::assertStringContainsString('Стендап &lt;скрипты&gt;', $text); // HTML-экранирование
        self::assertStringContainsString('#работа', $text);
        self::assertStringContainsString('Прогресс: 1/3', $text);
        self::assertStringContainsString('60 мин', $text);
    }

    public function testEmptyDay(): void
    {
        $day = new Day('2026-10-24');
        $text = $this->formatter->format($day);

        self::assertStringContainsString('пусто (/add)', $text);
        self::assertStringContainsString('пусто (/task)', $text);
        self::assertStringContainsString('Прогресс: 0/0', $text);
        self::assertStringContainsString('не задана', $text);
    }

    public function testFormatEmptyText(): void
    {
        $text = $this->formatter->formatEmpty('2026-10-24');
        self::assertStringContainsString('2026-10-24', $text);
        self::assertStringContainsString('ничего не запланировано', $text);
    }

    public function testTruncatesOverlyLongContent(): void
    {
        $day = new Day('2026-10-24');
        for ($i = 0; $i < 40; $i++) {
            $day->addScheduleItem(new ScheduleItem(
                str_pad((string) $i, 36, 'a'),
                sprintf('%02d:00', $i % 24),
                sprintf('%02d:30', $i % 24),
                str_repeat('Очень длинное название задачи ', 5) . $i,
                ['тег' . $i],
                'pending',
            ));
        }

        $text = $this->formatter->format($day);
        self::assertLessThanOrEqual(4096, mb_strlen($text));
    }
}
