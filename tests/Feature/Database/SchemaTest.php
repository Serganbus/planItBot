<?php

declare(strict_types=1);

use App\Domain\TaskStatus;
use App\Domain\TaskType;
use App\Models\Chat;
use App\Models\DialogState;
use App\Models\Task;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('persists a chat with schema defaults and jsonb settings', function (): void {
    $chat = Chat::query()->create(['chat_id' => 100_000_001])->fresh();

    expect($chat->timezone)->toBe('UTC')
        ->and($chat->language)->toBe('ru')
        ->and($chat->settings)->toBe([]);

    $chat->update(['settings' => ['quiet_hours' => ['from' => '23:00', 'to' => '07:00']]]);

    // jsonb не гарантирует порядок ключей — сравниваем по содержимому
    expect($chat->fresh()->settings)->toMatchArray(['quiet_hours' => ['from' => '23:00', 'to' => '07:00']]);
});

it('persists a task with generated uuid, enum and array casts', function (): void {
    Chat::query()->create(['chat_id' => 100_000_002]);

    $task = Task::query()->create([
        'chat_id' => 100_000_002,
        'task_date' => '2026-10-26',
        'type' => TaskType::Scheduled,
        'title' => 'Созвон в 10',
        'scheduled_time' => '10:00',
        'tags' => ['работа'],
    ]);

    $fresh = $task->fresh();

    expect(Str::isUuid($fresh->id))->toBeTrue()
        ->and($fresh->type)->toBe(TaskType::Scheduled)
        ->and($fresh->status)->toBe(TaskStatus::Pending)
        ->and($fresh->priority)->toBe(0)
        ->and($fresh->tags)->toBe(['работа'])
        ->and($fresh->chat->chat_id)->toBe(100_000_002);
});

it('rejects a task with unknown type via CHECK constraint', function (): void {
    Chat::query()->create(['chat_id' => 100_000_003]);

    DB::table('tasks')->insert([
        'id' => (string) Str::uuid(),
        'chat_id' => 100_000_003,
        'task_date' => '2026-10-26',
        'type' => 'bogus',
        'title' => 'any',
        'status' => 'pending',
    ]);
})->throws(QueryException::class);

it('rejects a task referencing a missing chat via FK', function (): void {
    DB::table('tasks')->insert([
        'id' => (string) Str::uuid(),
        'chat_id' => 999_999_999,
        'task_date' => '2026-10-26',
        'type' => 'flexible',
        'title' => 'any',
        'status' => 'pending',
    ]);
})->throws(QueryException::class);

it('cascades chat deletion to tasks and dialog states', function (): void {
    Chat::query()->create(['chat_id' => 100_000_004]);
    Task::query()->create([
        'chat_id' => 100_000_004,
        'task_date' => '2026-10-26',
        'type' => TaskType::Flexible,
        'title' => 'Почитать 30 минут',
        'estimated_minutes' => 30,
    ]);
    DialogState::query()->create(['chat_id' => 100_000_004, 'flow' => 'add_scheduled', 'step' => 'title']);

    Chat::query()->where('chat_id', 100_000_004)->delete();

    expect(Task::query()->where('chat_id', 100_000_004)->count())->toBe(0)
        ->and(DialogState::query()->where('chat_id', 100_000_004)->count())->toBe(0);
});

it('rejects duplicated (chat_id, update_id) pair in telegram_updates', function (): void {
    $row = ['chat_id' => 100_000_005, 'update_id' => 777_001];

    DB::table('telegram_updates')->insert($row);
    DB::table('telegram_updates')->insert($row);
})->throws(QueryException::class);

it('upserts dialog state per chat (single row per chat)', function (): void {
    Chat::query()->create(['chat_id' => 100_000_006]);

    DialogState::query()->updateOrCreate(
        ['chat_id' => 100_000_006],
        ['flow' => 'add_scheduled', 'step' => 'date', 'payload' => ['title' => 'X']],
    );
    DialogState::query()->updateOrCreate(
        ['chat_id' => 100_000_006],
        ['flow' => 'add_scheduled', 'step' => 'time', 'payload' => ['title' => 'X', 'date' => '2026-10-26']],
    );

    $states = DialogState::query()->where('chat_id', 100_000_006)->get();

    expect($states)->toHaveCount(1)
        ->and($states->first()->step)->toBe('time')
        ->and($states->first()->payload['date'])->toBe('2026-10-26');
});

it('provides gin and partial indexes on tasks', function (): void {
    $indexes = array_column(
        DB::select("SELECT indexname FROM pg_indexes WHERE tablename = 'tasks'"),
        'indexname',
    );

    expect($indexes)
        ->toContain('tasks_tags_gin')
        ->toContain('tasks_pending_idx')
        ->toContain('tasks_chat_id_task_date_status_index')
        ->toContain('tasks_chat_id_type_index');
});
