<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use PlannerBot\Util\QuickCommandParser;
use PHPUnit\Framework\TestCase;

final class QuickCommandParserTest extends TestCase
{
    public function testParseScheduleItemFull(): void
    {
        $parsed = QuickCommandParser::parseScheduleItem('09:00 10:30 Стендап с командой #работа #ежедневник');

        self::assertSame('09:00', $parsed['start']);
        self::assertSame('10:30', $parsed['end']);
        self::assertSame('Стендап с командой', $parsed['title']);
        self::assertSame(['работа', 'ежедневник'], $parsed['tags']);
    }

    public function testParseScheduleItemWithoutTags(): void
    {
        $parsed = QuickCommandParser::parseScheduleItem('18:00 19:00 Спортзал');

        self::assertSame('18:00', $parsed['start']);
        self::assertSame('19:00', $parsed['end']);
        self::assertSame('Спортзал', $parsed['title']);
        self::assertSame([], $parsed['tags']);
    }

    public function testParseScheduleItemMissingTimes(): void
    {
        $parsed = QuickCommandParser::parseScheduleItem('Просто название');

        self::assertNull($parsed['start']);
        self::assertNull($parsed['end']);
        self::assertSame('Просто название', $parsed['title']);
    }

    public function testParseFlexibleTaskFull(): void
    {
        $parsed = QuickCommandParser::parseFlexibleTask('Написать отчёт по проекту 90 high #работа');

        self::assertSame('Написать отчёт по проекту', $parsed['title']);
        self::assertSame(90, $parsed['minutes']);
        self::assertSame('high', $parsed['priority']);
        self::assertSame(['работа'], $parsed['tags']);
    }

    public function testParseFlexibleTaskDefaults(): void
    {
        $parsed = QuickCommandParser::parseFlexibleTask('Купить молоко');

        self::assertSame('Купить молоко', $parsed['title']);
        self::assertNull($parsed['minutes']);
        self::assertNull($parsed['priority']);
        self::assertSame([], $parsed['tags']);
    }

    public function testParseFlexibleTaskFirstNumberIsMinutes(): void
    {
        // Правило: первое число (1-4 цифры) трактуется как оценка в минутах.
        $parsed = QuickCommandParser::parseFlexibleTask('Ревью 45 medium');

        self::assertSame('Ревью', $parsed['title']);
        self::assertSame(45, $parsed['minutes']);
        self::assertSame('medium', $parsed['priority']);
    }
}
