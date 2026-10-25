<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use DateTimeZone;
use PlannerBot\Util\DateParser;
use PHPUnit\Framework\TestCase;

final class DateParserTest extends TestCase
{
    private DateParser $parser;

    protected function setUp(): void
    {
        $this->parser = new DateParser(new DateTimeZone('Europe/Moscow'));
    }

    public function testParsesIsoDate(): void
    {
        self::assertSame('2026-10-24', $this->parser->parse('2026-10-24'));
    }

    public function testParsesDotDate(): void
    {
        self::assertSame('2026-10-24', $this->parser->parse('24.10.2026'));
    }

    public function testRejectsInvalidDate(): void
    {
        self::assertNull($this->parser->parse('2026-02-30'));
        self::assertNull($this->parser->parse('32.01.2026'));
        self::assertNull($this->parser->parse('когда-нибудь'));
        self::assertNull($this->parser->parse(''));
    }

    public function testParsesRelativeKeywords(): void
    {
        $today = $this->parser->today();

        self::assertSame($today, $this->parser->parse('сегодня'));
        self::assertSame($today, $this->parser->parse('today'));
        self::assertSame($this->parser->shift($today, 1), $this->parser->parse('завтра'));
        self::assertSame($this->parser->shift($today, -1), $this->parser->parse('вчера'));
    }

    public function testParsesPlusDays(): void
    {
        self::assertSame($this->parser->shift($this->parser->today(), 3), $this->parser->parse('+3'));
    }

    public function testShift(): void
    {
        self::assertSame('2026-10-25', $this->parser->shift('2026-10-24', 1));
        self::assertSame('2026-10-23', $this->parser->shift('2026-10-24', -1));
        self::assertSame('2026-11-01', $this->parser->shift('2026-10-31', 1));
    }
}
