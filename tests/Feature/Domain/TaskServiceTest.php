<?php

declare(strict_types=1);

use App\Domain\Exceptions\AmbiguousTaskIdException;
use App\Domain\Exceptions\TaskNotFoundException;
use App\Domain\Services\TaskService;
use App\Domain\TaskStatus;
use App\Domain\TaskType;
use App\Models\Chat;
use App\Support\IdResolver;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->service = app(TaskService::class);
});

it('adds scheduled and flexible tasks (chat is auto-created)', function (): void {
    $task = $this->service->addScheduledTask(700_001, '2026-10-27', '9:30', 'Планёрка', ['работа']);
    $flex = $this->service->addFlexibleTask(700_001, '2026-10-27', 'Чтение', 30, 2);

    expect(Str::isUuid($task->id))->toBeTrue()
        ->and($task->type)->toBe(TaskType::Scheduled)
        ->and($task->scheduledTime)->toBe('09:30')
        ->and($task->status)->toBe(TaskStatus::Pending)
        ->and($flex->estimatedMinutes)->toBe(30)
        ->and($flex->priority)->toBe(2)
        ->and(Chat::query()->where('chat_id', 700_001)->exists())->toBeTrue()
        ->and($this->service->forDate(700_001, '2026-10-27'))->toHaveCount(2)
        ->and($this->service->forDate(700_001, '2026-10-28'))->toHaveCount(0);
});

it('rejects invalid input on creation', function (): void {
    $this->service->addScheduledTask(700_002, '2026-13-01', '09:00', 'X');
})->throws(InvalidArgumentException::class);

it('completes a task exactly once (idempotent)', function (): void {
    $task = $this->service->addFlexibleTask(700_003, '2026-10-27', 'Сделать отчёт');

    $first = $this->service->completeTask($task->id, 700_003);
    $second = $this->service->completeTask($task->id, 700_003);

    expect($first->status)->toBe(TaskStatus::Completed)
        ->and($first->completedAt)->not->toBeNull()
        ->and($second->status)->toBe(TaskStatus::Completed)
        // completed_at первого завершения не перезаписывается
        ->and($second->completedAt?->format('Y-m-d H:i:s'))->toBe($first->completedAt?->format('Y-m-d H:i:s'));
});

it('uses SELECT ... FOR UPDATE when completing', function (): void {
    $task = $this->service->addFlexibleTask(700_004, '2026-10-27', 'Задача');

    $captured = [];
    DB::listen(static function (QueryExecuted $query) use (&$captured): void {
        $captured[] = $query->sql;
    });

    $this->service->completeTask($task->id, 700_004);

    expect(implode("\n", $captured))->toContain('for update');
});

it('resolves tasks by 8-char id prefix', function (): void {
    $task = $this->service->addFlexibleTask(700_005, '2026-10-27', 'По префиксу');
    $prefix = IdResolver::shorten($task->id);

    $completed = $this->service->completeTask($prefix, 700_005);

    expect($completed->id)->toBe($task->id)
        ->and($completed->status)->toBe(TaskStatus::Completed);
});

it('throws on ambiguous prefix instead of taking the first match', function (): void {
    $chatId = 700_006;
    Chat::query()->create(['chat_id' => $chatId]);

    foreach (['aaaaaaaa-1111-1111-1111-111111111111', 'aaaaaaaa-2222-2222-2222-222222222222'] as $id) {
        DB::table('tasks')->insert([
            'id' => $id,
            'chat_id' => $chatId,
            'task_date' => '2026-10-27',
            'type' => 'flexible',
            'title' => 'X',
        ]);
    }

    $this->service->completeTask('aaaaaaaa', $chatId);
})->throws(AmbiguousTaskIdException::class);

it('guards against IDOR: task of another chat is not found', function (): void {
    $task = $this->service->addFlexibleTask(700_007, '2026-10-27', 'Чужая задача');

    $this->service->completeTask($task->id, 999_000_000);
})->throws(TaskNotFoundException::class);

it('throws on malformed id input', function (): void {
    $this->service->completeTask('not-a-uuid', 700_008);
})->throws(TaskNotFoundException::class);

it('edits by replacing the object: id is preserved, fields changed', function (): void {
    $task = $this->service->addScheduledTask(700_009, '2026-10-27', '10:00', 'Старый тайтл', ['старое']);

    $edited = $this->service->editTask($task->id, 700_009, [
        'title' => 'Новый тайтл',
        'time' => '11:30',
        'tags' => ['новое'],
    ]);

    expect($edited->id)->toBe($task->id)
        ->and($edited->title)->toBe('Новый тайтл')
        ->and($edited->scheduledTime)->toBe('11:30')
        ->and($edited->tags)->toBe(['новое'])
        ->and($edited->date)->toBe($task->date)
        ->and($edited->status)->toBe(TaskStatus::Pending);
});

it('cancels and deletes tasks', function (): void {
    $task = $this->service->addFlexibleTask(700_010, '2026-10-27', 'Удаляемая');
    $cancelled = $this->service->cancelTask($task->id, 700_010);
    expect($cancelled->status)->toBe(TaskStatus::Cancelled);

    $this->service->deleteTask($task->id, 700_010);
    expect($this->service->forDate(700_010, '2026-10-27'))->toHaveCount(0);

    $this->service->deleteTask($task->id, 700_010);
})->throws(TaskNotFoundException::class);
