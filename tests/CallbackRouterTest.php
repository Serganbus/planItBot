<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use PlannerBot\Callback\CallbackRouter;
use PHPUnit\Framework\TestCase;

final class CallbackRouterTest extends TestCase
{
    public function testParsesAllPrefixes(): void
    {
        self::assertSame(['done', 'abcd1234'], CallbackRouter::parse('dn:abcd1234'));
        self::assertSame(['undone', 'abcd1234'], CallbackRouter::parse('un:abcd1234'));
        self::assertSame(['del', 'abcd1234'], CallbackRouter::parse('rm:abcd1234'));
        self::assertSame(['delok', 'abcd1234'], CallbackRouter::parse('rok:abcd1234'));
        self::assertSame(['edit', 'abcd1234'], CallbackRouter::parse('ed:abcd1234'));
        self::assertSame(['itemmenu', '2026-10-24'], CallbackRouter::parse('im:2026-10-24'));
        self::assertSame(['taskmenu', '2026-10-24'], CallbackRouter::parse('tm:2026-10-24'));
        self::assertSame(['day', '2026-10-24'], CallbackRouter::parse('day:2026-10-24'));
        self::assertSame(['statday', '2026-10-24'], CallbackRouter::parse('sd:2026-10-24'));
        self::assertSame(['stats', ''], CallbackRouter::parse('stats'));
    }

    public function testAmbiguousPrefixesResolvedToLongestFirst(): void
    {
        // 'rok:' должно распознаваться как delok, а не как что-то с префиксом 'r'
        self::assertSame(['delok', 'zzz'], CallbackRouter::parse('rok:zzz'));
    }

    public function testRejectsUnknown(): void
    {
        self::assertNull(CallbackRouter::parse('unknown:123'));
        self::assertNull(CallbackRouter::parse(''));
    }

    public function testIsValidChecksLength(): void
    {
        self::assertTrue(CallbackRouter::isValid('day:2026-10-24'));
        self::assertFalse(CallbackRouter::isValid('day:' . str_repeat('1', 100)));
        self::assertFalse(CallbackRouter::isValid(str_repeat('x', 10))); // неизвестный префикс
    }
}
