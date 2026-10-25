<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use PlannerBot\Util\TimeParser;
use PHPUnit\Framework\TestCase;

final class TimeParserTest extends TestCase
{
    public function testParsesValidTime(): void
    {
        self::assertSame('09:00', TimeParser::parse('9:00'));
        self::assertSame('09:05', TimeParser::parse('09:05'));
        self::assertSame('23:59', TimeParser::parse('23:59'));
        self::assertSame('12:30', TimeParser::parse('12.30'));
    }

    public function testRejectsInvalidTime(): void
    {
        self::assertNull(TimeParser::parse('24:00'));
        self::assertNull(TimeParser::parse('09:60'));
        self::assertNull(TimeParser::parse('abc'));
        self::assertNull(TimeParser::parse('9'));
        self::assertNull(TimeParser::parse(''));
    }

    public function testIsValidRange(): void
    {
        self::assertTrue(TimeParser::isValidRange('09:00', '10:00'));
        self::assertFalse(TimeParser::isValidRange('10:00', '09:00'));
        self::assertFalse(TimeParser::isValidRange('09:00', '09:00'));
    }
}
