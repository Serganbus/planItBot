<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use DateTimeImmutable;
use DateTimeZone;
use PlannerBot\Flow\FlowManager;
use PlannerBot\Infrastructure\ChatContext;
use PlannerBot\Infrastructure\ChatContextFactory;
use PlannerBot\Service\GoalService;
use PlannerBot\Util\DateParser;
use PHPUnit\Framework\TestCase;

final class FlowManagerTest extends TestCase
{
    private string $dir;
    private ChatContext $chat;
    private FlowManager $flows;
    private DateParser $dates;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/planner-bot-flow-' . uniqid();
        $this->chat = (new ChatContextFactory($this->dir))->forChat(42);
        $this->dates = new DateParser(new DateTimeZone('UTC'));
        $this->flows = new FlowManager($this->dates, new GoalService());

        $this->chat->state->setDay('2026-10-24');
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
    }

    public function testAddItemFlow(): void
    {
        $this->chat->state->startFlow(FlowManager::FLOW_ADD_ITEM, FlowManager::STEP_START, ['date' => '2026-10-24']);

        $r = $this->step('не время');
        self::assertStringContainsString('❌', $r['text']);
        self::assertSame(FlowManager::STEP_START, $this->chat->state->get()['step']);

        $r = $this->step('09:00');
        self::assertStringContainsString('время окончания', $r['text']);

        $r = $this->step('08:00');
        self::assertStringContainsString('❌', $r['text']); // конец раньше начала

        $r = $this->step('10:30');
        $r = $this->step('Стендап');
        $r = $this->step('#работа #ежедневник');

        self::assertStringContainsString('✅', $r['text']);
        self::assertNull($this->chat->state->get()['flow']);

        $day = $this->chat->planner->getDay('2026-10-24');
        self::assertNotNull($day);
        self::assertCount(1, $day->getSchedule());
        self::assertSame('09:00', $day->getSchedule()[0]->startTime);
        self::assertSame('10:30', $day->getSchedule()[0]->endTime);
        self::assertSame('Стендап', $day->getSchedule()[0]->title);
        self::assertSame(['работа', 'ежедневник'], $day->getSchedule()[0]->tags);
    }

    public function testAddTaskFlow(): void
    {
        $this->chat->state->startFlow(FlowManager::FLOW_ADD_TASK, FlowManager::STEP_TITLE, ['date' => '2026-10-24']);

        $this->step('Написать отчёт');
        $r = $this->step('abc');
        self::assertStringContainsString('❌', $r['text']);
        $this->step('45');
        $r = $this->step('суперважный');
        self::assertStringContainsString('❌', $r['text']);
        $this->step('высокий');
        $r = $this->step('-');

        self::assertStringContainsString('✅', $r['text']);

        $day = $this->chat->planner->getDay('2026-10-24');
        self::assertCount(1, $day->getFlexibleTasks());
        self::assertSame('Написать отчёт', $day->getFlexibleTasks()[0]->title);
        self::assertSame(45, $day->getFlexibleTasks()[0]->estimatedMinutes);
        self::assertSame('high', $day->getFlexibleTasks()[0]->priority);
        self::assertSame([], $day->getFlexibleTasks()[0]->tags);
    }

    public function testSetGoalFlow(): void
    {
        $this->chat->state->startFlow(FlowManager::FLOW_SET_GOAL, FlowManager::STEP_GOAL, ['date' => '2026-10-24']);

        $r = $this->step('выкатить MVP');

        self::assertStringContainsString('🎯', $r['text']);
        self::assertNull($this->chat->state->get()['flow']);
        self::assertSame('выкатить MVP', $this->chat->planner->getDay('2026-10-24')->mainGoal);
    }

    public function testEditItemFlow(): void
    {
        $item = $this->chat->planner->addScheduleItem('2026-10-24', '09:00', '10:00', 'Стендап', []);
        $prefix = substr($item->id, 0, 8);

        $this->chat->state->startFlow(FlowManager::FLOW_EDIT_ITEM, FlowManager::STEP_FIELD, [
            'date' => '2026-10-24', 'id' => $prefix,
        ]);

        $r = $this->step('непонятное поле');
        self::assertStringContainsString('❌', $r['text']);

        $this->step('начало');
        $r = $this->step('09:15');

        self::assertStringContainsString('✏️', $r['text']);
        self::assertNull($this->chat->state->get()['flow']);

        $day = $this->chat->planner->getDay('2026-10-24');
        self::assertSame('09:15', $day->getSchedule()[0]->startTime);
        self::assertSame($item->id, $day->getSchedule()[0]->id, 'id должен сохраниться при редактировании');
    }

    public function testEditTaskFlow(): void
    {
        $task = $this->chat->planner->addFlexibleTask('2026-10-24', 'Отчёт', 60, 'high', []);
        $prefix = substr($task->id, 0, 8);

        $this->chat->state->startFlow(FlowManager::FLOW_EDIT_TASK, FlowManager::STEP_FIELD, [
            'date' => '2026-10-24', 'id' => $prefix,
        ]);

        $this->step('приоритет');
        $r = $this->step('низкий');

        self::assertStringContainsString('✏️', $r['text']);

        $day = $this->chat->planner->getDay('2026-10-24');
        self::assertSame('low', $day->getFlexibleTasks()[0]->priority);
        self::assertSame($task->id, $day->getFlexibleTasks()[0]->id);
    }

    public function testEditFlowHandlesDeletedEntity(): void
    {
        $this->chat->state->startFlow(FlowManager::FLOW_EDIT_ITEM, FlowManager::STEP_FIELD, [
            'date' => '2026-10-24', 'id' => 'ffffffff',
        ]);

        $r = $this->step('начало');
        self::assertStringContainsString('не найден', $r['text']);
        self::assertNull($this->chat->state->get()['flow']);
    }

    /**
     * @return array{text:string}
     */
    private function step(string $text): array
    {
        $state = $this->chat->state->get();
        $result = $this->flows->handle($this->chat, (string) $state['flow'], (string) $state['step'], $state['payload'], $text);
        self::assertNotNull($result, 'Flow должен вернуть ответ');

        return $result;
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        foreach (scandir($dir) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . '/' . $entry;
            is_dir($path) ? $this->removeDir($path) : unlink($path);
        }
        rmdir($dir);
    }
}
