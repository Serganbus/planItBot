<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Contracts\ChatRepositoryInterface;
use App\Domain\Contracts\TaskRepositoryInterface;
use App\Domain\DayPlan;
use App\Domain\Task;
use App\Domain\TaskType;

/**
 * Собирает DayPlan чата на дату: расписание по времени, гибкие задачи
 * по приоритету, статистика (schedule_/tasks_*, total_estimated_minutes).
 */
final class DayPlanService
{
    public function __construct(
        private readonly TaskRepositoryInterface $tasks,
        private readonly ChatRepositoryInterface $chats,
    ) {
    }

    public function planForDate(int $chatId, string $date): DayPlan
    {
        $all = $this->tasks->forChatAndDate($chatId, $date);

        $scheduled = array_values(array_filter($all, static fn (Task $t): bool => $t->type === TaskType::Scheduled));
        usort($scheduled, static function (Task $a, Task $b): int {
            // Задачи без времени — в конец расписания.
            return [$a->scheduledTime ?? '99:99', $a->createdAt?->format('Y-m-d H:i:s') ?? '']
                <=> [$b->scheduledTime ?? '99:99', $b->createdAt?->format('Y-m-d H:i:s') ?? ''];
        });

        $flexible = array_values(array_filter($all, static fn (Task $t): bool => $t->type === TaskType::Flexible));
        usort($flexible, static function (Task $a, Task $b): int {
            return [$b->priority, $a->createdAt?->format('Y-m-d H:i:s') ?? '']
                <=> [$a->priority, $b->createdAt?->format('Y-m-d H:i:s') ?? ''];
        });

        // Задачи типа goal зарезервированы под «цель как задачу»; текущая цель
        // дня хранится в chats.main_goal и в списки не включается.
        $scheduleDone = count(array_filter($scheduled, static fn (Task $t): bool => $t->status->value === 'completed'));
        $tasksDone = count(array_filter($flexible, static fn (Task $t): bool => $t->status->value === 'completed'));
        $estimated = array_sum(array_map(
            static fn (Task $t): int => $t->status->value === 'cancelled' ? 0 : ($t->estimatedMinutes ?? 0),
            $flexible,
        ));

        return new DayPlan(
            date: $date,
            mainGoal: $this->chats->getGoal($chatId, $date),
            scheduled: $scheduled,
            flexible: $flexible,
            stats: [
                'schedule_total' => count($scheduled),
                'schedule_done' => $scheduleDone,
                'tasks_total' => count($flexible),
                'tasks_done' => $tasksDone,
                'total_estimated_minutes' => $estimated,
            ],
        );
    }
}
