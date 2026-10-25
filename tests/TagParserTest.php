<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use PlannerBot\Util\TagParser;
use PHPUnit\Framework\TestCase;

final class TagParserTest extends TestCase
{
    public function testParseMany(): void
    {
        self::assertSame(['работа', 'дом'], TagParser::parseMany('#работа #дом'));
        self::assertSame(['работа'], TagParser::parseMany('#работа #Работа'));
        self::assertSame([], TagParser::parseMany('без тегов'));
        self::assertSame(['спорт-зал'], TagParser::parseMany('текст #спорт-зал хвост'));
    }

    public function testNormalize(): void
    {
        self::assertSame('работа', TagParser::normalize('#Работа'));
        self::assertSame('дом', TagParser::normalize(' дом '));
    }

    public function testFormat(): void
    {
        self::assertSame('#работа #дом', TagParser::format(['работа', 'дом']));
    }
}
