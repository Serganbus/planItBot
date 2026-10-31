<?php

declare(strict_types=1);

use App\Application\Telegram\DayViewRenderer;
use App\Application\Telegram\KeyboardFactory;
use App\Domain\DayPlan;
use App\Domain\Task;
use App\Domain\TaskStatus;
use App\Domain\TaskType;

// Unit-уровень: ни Laravel, ни БД, ни HTTP.

function dtoTask(string $title, array $overrides = []): Task
{
    return new Task(
        id: $overrides['id'] ?? '12345678-1234-1234-1234-123456789012',
        chatId: 1,
        date: '2026-10-26',
        type: $overrides['type'] ?? TaskType::Scheduled,
        title: $title,
        scheduledTime: $overrides['time'] ?? null,
        estimatedMinutes: $overrides['minutes'] ?? null,
        priority: $overrides['priority'] ?? 0,
        tags: $overrides['tags'] ?? [],
        status: $overrides['status'] ?? TaskStatus::Pending,
        telegramMessageId: null,
        completedAt: null,
        createdAt: null,
        updatedAt: null,
    );
}

function dayPlan(array $scheduled = [], array $flexible = [], ?string $goal = null, array $stats = []): DayPlan
{
    return new DayPlan(
        date: '2026-10-26',
        mainGoal: $goal,
        scheduled: $scheduled,
        flexible: $flexible,
        stats: $stats ?: ['schedule_total' => count($scheduled), 'schedule_done' => 0, 'tasks_total' => count($flexible), 'tasks_done' => 0, 'total_estimated_minutes' => 0],
    );
}

it('escapes user input in rendered HTML', function (): void {
    $plan = dayPlan([dtoTask('<b>Порезка "тегов"</b>', ['time' => '10:00', 'tags' => ['<script>']])]);

    $html = DayViewRenderer::formatPlan($plan);

    expect($html)->not->toContain('<b>Порезка "тегов"</b>')
        ->and($html)->toContain('&lt;b&gt;Порезка')
        ->and($html)->toContain('📅')
        ->and($html)->toContain('── Расписание ──');
});

it('renders empty day', function (): void {
    expect(DayViewRenderer::formatDayEmpty('2026-10-26'))->toContain('ничего не запланировано')
        ->and(DayViewRenderer::dateHeader('2026-10-26'))->toBe('2026-10-26 (пн)');
});

it('builds keyboards with byte-limited callback_data', function (): void {
    $plan = dayPlan(
        [dtoTask('Стендап', ['time' => '09:30'])],
        [dtoTask('Длинная задача с очень-очень длинным названием', ['minutes' => 20, 'priority' => 3])],
    );

    $keyboard = KeyboardFactory::doneList($plan, '2026-10-26')->toArray();
    $labels = [];
    foreach ($keyboard as $row) {
        foreach ($row as $button) {
            expect(strlen((string) $button['callback_data']))->toBeLessThanOrEqual(64);
            $labels[] = (string) $button['callback_data'];
        }
    }

    // «action:done;id:12345678» — нативная форма telegraph с 8-hex префиксом
    expect($labels)->toContain('action:done;id:12345678');
});

it('rejects oversized callback_data in the data() helper', function (): void {
    KeyboardFactory::data('act', ['id' => str_repeat('a', 60)]);
})->throws(InvalidArgumentException::class);

it('accepts canonical callback payloads', function (): void {
    expect(KeyboardFactory::data('done', ['id' => '3f2a91c0']))->toBe('action:done;id:3f2a91c0')
        ->and(KeyboardFactory::data('day', ['date' => '2026-10-27']))->toBe('action:day;date:2026-10-27');
});
