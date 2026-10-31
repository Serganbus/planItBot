<?php

declare(strict_types=1);

use App\Domain\Services\DayPlanService;
use App\Domain\Services\GoalService;
use App\Domain\Services\TaskService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tasks = app(TaskService::class);
    $this->goals = app(GoalService::class);
    $this->plans = app(DayPlanService::class);
});

it('keeps the main goal bound to its date', function (): void {
    $this->goals->setGoal(800_001, '2026-10-27', 'Выпустить релиз');

    expect($this->goals->getGoal(800_001, '2026-10-27'))->toBe('Выпустить релиз')
        ->and($this->goals->getGoal(800_001, '2026-10-28'))->toBeNull()
        ->and($this->goals->getGoal(800_002, '2026-10-27'))->toBeNull();
});

it('assembles day plan: sorted lists, goal and stats', function (): void {
    $chatId = 800_003;
    $date = '2026-10-27';

    $this->goals->setGoal($chatId, $date, 'Фокус дня');

    // специально добавляем в «неправильном» порядке
    $evening = $this->tasks->addScheduledTask($chatId, $date, '18:00', 'Спорт');
    $morning = $this->tasks->addScheduledTask($chatId, $date, '09:00', 'Почта');
    $lowPrio = $this->tasks->addFlexibleTask($chatId, $date, 'Дочитать статью', 15, 1);
    $highPrio = $this->tasks->addFlexibleTask($chatId, $date, 'Срочный фикс', 45, 3);
    $this->tasks->completeTask($morning->id, $chatId);

    $plan = $this->plans->planForDate($chatId, $date);

    expect($plan->mainGoal)->toBe('Фокус дня')
        ->and(array_column($plan->scheduled, 'id'))->toBe([$morning->id, $evening->id])
        ->and(array_column($plan->flexible, 'id'))->toBe([$highPrio->id, $lowPrio->id])
        ->and($plan->stats)->toMatchArray([
            'schedule_total' => 2,
            'schedule_done' => 1,
            'tasks_total' => 2,
            'tasks_done' => 0,
            'total_estimated_minutes' => 60,
        ]);
});

it('plan for unknown chat is empty (no error)', function (): void {
    $plan = $this->plans->planForDate(800_404, '2026-10-27');

    expect($plan->mainGoal)->toBeNull()
        ->and($plan->scheduled)->toBe([])
        ->and($plan->flexible)->toBe([])
        ->and($plan->stats['schedule_total'])->toBe(0);
});
