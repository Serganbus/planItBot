<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use Planner\Domain\Model\Day;
use Planner\Domain\Model\FlexibleTask;
use Planner\Domain\Model\ScheduleItem;
use PlannerBot\Util\IdResolver;
use PHPUnit\Framework\TestCase;

final class IdResolverTest extends TestCase
{
    private Day $day;
    private ScheduleItem $item;
    private FlexibleTask $task;

    protected function setUp(): void
    {
        $this->day = new Day('2026-10-24');
        $this->item = new ScheduleItem(
            '11111111-2222-3333-4444-555555555555',
            '09:00',
            '10:00',
            'Стендап',
            [],
            'pending',
        );
        $this->task = new FlexibleTask(
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee',
            'Отчёт',
            60,
            'high',
            [],
            'pending',
        );
        $this->day->addScheduleItem($this->item);
        $this->day->addFlexibleTask($this->task);
    }

    public function testShorten(): void
    {
        self::assertSame('11111111', IdResolver::shorten($this->item->id));
    }

    public function testResolveFindsItemAndTask(): void
    {
        [$kindItem, $entityItem] = IdResolver::resolve($this->day, '11111111');
        self::assertSame('item', $kindItem);
        self::assertSame($this->item, $entityItem);

        [$kindTask, $entityTask] = IdResolver::resolve($this->day, 'aaaaaaaa');
        self::assertSame('task', $kindTask);
        self::assertSame($this->task, $entityTask);
    }

    public function testResolveReturnsNullForUnknown(): void
    {
        self::assertNull(IdResolver::resolve($this->day, 'ffffffff'));
        self::assertNull(IdResolver::resolveScheduleItem($this->day, 'aaaaaaaa')); // это id задачи, не пункта
    }
}
