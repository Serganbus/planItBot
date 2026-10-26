<?php

declare(strict_types=1);

namespace PlannerBot\Tests;

use Planner\Domain\Service\PlannerService;
use Planner\Infrastructure\Repository\JsonDayRepository;
use PlannerBot\Infrastructure\ChatContextFactory;
use PlannerBot\Service\EditService;
use PHPUnit\Framework\TestCase;

/**
 * Smoke-тест: прогон основного сценария через реальный пакет serganbus/planner-cli
 * на временном каталоге. Фиксирует совместимость с установленной версией.
 */
final class PlannerServiceIntegrationTest extends TestCase
{
    private string $dir;
    private PlannerService $planner;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/planner-bot-it-' . uniqid();
        $this->planner = new PlannerService(new JsonDayRepository($this->dir));
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->dir);
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

    public function testFullLifecycle(): void
    {
        $date = '2026-10-24';

        // Хранилище создаёт файл на дату
        $item = $this->planner->addScheduleItem($date, '09:00', '10:00', 'Стендап', ['работа']);
        $task = $this->planner->addFlexibleTask($date, 'Отчёт', 60, 'high', ['работа']);

        self::assertFileExists($this->dir . '/' . $date . '.json');

        // Данные сохраняются и читаются заново (новый экземпляр сервиса)
        $reloaded = new PlannerService(new JsonDayRepository($this->dir));
        $day = $reloaded->getDay($date);

        self::assertNotNull($day);
        self::assertCount(1, $day->getSchedule());
        self::assertCount(1, $day->getFlexibleTasks());
        self::assertSame(['работа'], $day->getSchedule()[0]->tags);

        // Отметки выполнения
        self::assertTrue($reloaded->markScheduleItemDone($date, $item->id));
        self::assertTrue($reloaded->markFlexibleTaskDone($date, $task->id));

        $stats = $reloaded->getStats($date);
        self::assertSame(1, $stats['schedule_done']);
        self::assertSame(1, $stats['tasks_done']);
        self::assertSame(60, $stats['total_estimated_minutes']);

        // Редактирование — через наш EditService (пересоздание элемента
        // с тем же id; не зависит от vendor-ных update-методов).
        $chat = (new ChatContextFactory($this->dir . '/app'))->forChat(777);
        $edit = new EditService();

        // Переносим данные в каталог чата, чтобы EditService видел тот же репозиторий
        @mkdir($this->dir . '/app/chats/777', 0755, true);
        copy($this->dir . '/' . $date . '.json', $this->dir . '/app/chats/777/' . $date . '.json');

        self::assertTrue($edit->updateScheduleItem($chat, $date, $item->id, '09:15', null, 'Дейли'));
        self::assertTrue($edit->updateFlexibleTask($chat, $date, $task->id, null, 45, 'medium'));

        $chatDay = $chat->planner->getDay($date);
        self::assertSame('09:15', $chatDay->getSchedule()[0]->startTime);
        self::assertSame('Дейли', $chatDay->getSchedule()[0]->title);
        self::assertSame(45, $chatDay->getFlexibleTasks()[0]->estimatedMinutes);
        self::assertSame('medium', $chatDay->getFlexibleTasks()[0]->priority);

        // id сохраняются при редактировании
        self::assertSame($item->id, $chatDay->getSchedule()[0]->id);
        self::assertSame($task->id, $chatDay->getFlexibleTasks()[0]->id);

        // Удаление
        self::assertTrue($reloaded->removeScheduleItem($date, $item->id));
        self::assertTrue($reloaded->removeFlexibleTask($date, $task->id));

        $stats = $reloaded->getStats($date);
        self::assertSame(0, $stats['schedule_total']);
        self::assertSame(0, $stats['tasks_total']);
    }

    public function testGetStatsForMissingDay(): void
    {
        $stats = $this->planner->getStats('2030-01-01');

        self::assertSame(0, $stats['schedule_total']);
        self::assertSame(0, $stats['tasks_total']);
    }
}
