<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use Planner\Domain\Model\Day;
use Planner\Domain\Model\FlexibleTask;
use Planner\Domain\Model\ScheduleItem;
use PlannerBot\Keyboard\KeyboardFactory;
use PHPUnit\Framework\TestCase;

final class KeyboardFactoryTest extends TestCase
{
    private Day $day;

    protected function setUp(): void
    {
        $this->day = new Day('2026-10-24');
        $this->day->addScheduleItem(new ScheduleItem(
            '11111111-2222-3333-4444-555555555555', '09:00', '10:00', 'Стендап', ['работа'], 'pending',
        ));
        $this->day->addScheduleItem(new ScheduleItem(
            '22222222-2222-3333-4444-555555555555', '12:00', '13:00', 'Обед', [], 'done',
        ));
        $this->day->addFlexibleTask(new FlexibleTask(
            'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee', 'Отчёт', 60, 'high', [], 'pending',
        ));
    }

    public function testAllCallbackDataFitTelegramLimit(): void
    {
        $keyboards = [
            KeyboardFactory::dayNav('2026-10-24', '2026-10-24'),
            KeyboardFactory::entries($this->day, '2026-10-24', 'del', 'day:2026-10-24'),
            KeyboardFactory::entries($this->day, '2026-10-24', 'edit', 'day:2026-10-24'),
            KeyboardFactory::doneList($this->day, '2026-10-24'),
            KeyboardFactory::confirmDelete('11111111', 'day:2026-10-24'),
            KeyboardFactory::statsNav('2026-10-24', '2026-10-24'),
            KeyboardFactory::backToDay('2026-10-24'),
        ];

        foreach ($keyboards as $keyboard) {
            foreach ($keyboard->getInlineKeyboard() as $row) {
                foreach ($row as $button) {
                    self::assertLessThanOrEqual(
                        64,
                        strlen($button['callback_data']),
                        'callback_data превышает 64 байта: ' . $button['callback_data']
                    );
                }
            }
        }
    }

    public function testDoneListUsesCorrectActions(): void
    {
        $keyboard = KeyboardFactory::doneList($this->day, '2026-10-24');
        $buttons = $keyboard->getInlineKeyboard();

        $callbackData = [];
        foreach ($buttons as $row) {
            foreach ($row as $button) {
                $callbackData[] = $button['callback_data'];
            }
        }

        self::assertContains('dn:11111111', $callbackData); // pending item → done
        self::assertContains('dn:aaaaaaaa', $callbackData); // pending task → done
        self::assertContains('un:22222222', $callbackData); // done item → undone
    }

    public function testEntriesEmptyDayStillValid(): void
    {
        $empty = new Day('2026-10-24');
        $keyboard = KeyboardFactory::entries($empty, '2026-10-24', 'del', 'day:2026-10-24');
        $rows = $keyboard->getInlineKeyboard();

        self::assertNotEmpty($rows);
    }

    public function testEntriesThrowsOnUnknownAction(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        KeyboardFactory::entries($this->day, '2026-10-24', 'unknown', 'day:2026-10-24');
    }
}
