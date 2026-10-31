<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * План дня чата: цель + расписание + гибкие задачи со статистикой.
 */
final readonly class DayPlan
{
    public function __construct(
        public string $date,
        public ?string $mainGoal,
        /** @var Task[] отсортированы по scheduledTime */
        public array $scheduled,
        /** @var Task[] отсортированы по priority desc, затем по времени создания */
        public array $flexible,
        /** @var array{schedule_total:int, schedule_done:int, tasks_total:int, tasks_done:int, total_estimated_minutes:int} */
        public array $stats,
    ) {
    }
}
