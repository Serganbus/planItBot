<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use PlannerBot\Util\PriorityParser;
use PHPUnit\Framework\TestCase;

final class PriorityParserTest extends TestCase
{
    public function testParsesKnownAliases(): void
    {
        self::assertSame('low', PriorityParser::parse('low'));
        self::assertSame('low', PriorityParser::parse('низкий'));
        self::assertSame('medium', PriorityParser::parse('medium'));
        self::assertSame('medium', PriorityParser::parse('средний'));
        self::assertSame('high', PriorityParser::parse('HIGH'));
        self::assertSame('high', PriorityParser::parse('высокий'));
    }

    public function testRejectsUnknown(): void
    {
        self::assertNull(PriorityParser::parse('супер'));
        self::assertNull(PriorityParser::parse(''));
    }

    public function testLabel(): void
    {
        self::assertSame('низкий', PriorityParser::label('low'));
        self::assertSame('средний', PriorityParser::label('medium'));
        self::assertSame('высокий', PriorityParser::label('high'));
    }
}
