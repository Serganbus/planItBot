<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Contracts\TaskRepositoryInterface;
use App\Domain\Exceptions\TaskNotFoundException;
use App\Domain\Task;
use App\Domain\TaskStatus;
use App\Domain\TaskType;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Хранилище задач на PostgreSQL (query builder, без Eloquent —
 * точный контроль над транзакциями и блокировками).
 */
final class PostgresTaskRepository implements TaskRepositoryInterface
{
    public function findForChat(string $id, int $chatId): ?Task
    {
        $row = DB::table('tasks')->where('id', $id)->where('chat_id', $chatId)->first();

        return $row === null ? null : $this->mapRow($row);
    }

    public function findIdsByPrefix(int $chatId, string $prefix): array
    {
        return DB::table('tasks')
            ->where('chat_id', $chatId)
            ->whereRaw("id::text LIKE ?", [$prefix . '%'])
            ->orderBy('id')
            ->pluck('id')
            ->all();
    }

    public function forChatAndDate(int $chatId, string $date): array
    {
        $rows = DB::table('tasks')
            ->where('chat_id', $chatId)
            ->where('task_date', $date)
            ->orderBy('created_at')
            ->get();

        return $rows->map(fn (stdClass $row): Task => $this->mapRow($row))->all();
    }

    public function insert(Task $task): Task
    {
        $now = new DateTimeImmutable();

        DB::table('tasks')->insert([
            'id' => $task->id,
            'chat_id' => $task->chatId,
            'task_date' => $task->date,
            'type' => $task->type->value,
            'title' => $task->title,
            'scheduled_time' => $task->scheduledTime,
            'estimated_minutes' => $task->estimatedMinutes,
            'priority' => $task->priority,
            'tags' => json_encode($task->tags, JSON_UNESCAPED_UNICODE),
            'status' => $task->status->value,
            'telegram_message_id' => $task->telegramMessageId,
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        // Читаем обратно — чтобы DTO отражал ровно то, что легло в БД.
        return $this->mustFind($task->id, $task->chatId);
    }

    public function replace(Task $task): Task
    {
        $affected = DB::table('tasks')
            ->where('id', $task->id)
            ->where('chat_id', $task->chatId)
            ->update([
                'task_date' => $task->date,
                'type' => $task->type->value,
                'title' => $task->title,
                'scheduled_time' => $task->scheduledTime,
                'estimated_minutes' => $task->estimatedMinutes,
                'priority' => $task->priority,
                'tags' => json_encode($task->tags, JSON_UNESCAPED_UNICODE),
                'status' => $task->status->value,
                'telegram_message_id' => $task->telegramMessageId,
                'completed_at' => $task->completedAt?->format('Y-m-d H:i:s'),
                'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);

        if ($affected === 0) {
            throw TaskNotFoundException::byId($task->id, $task->chatId);
        }

        return $this->mustFind($task->id, $task->chatId);
    }

    public function completeForChat(string $id, int $chatId): Task
    {
        return DB::transaction(function () use ($id, $chatId): Task {
            $row = DB::table('tasks')
                ->where('id', $id)
                ->where('chat_id', $chatId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw TaskNotFoundException::byId($id, $chatId);
            }

            // Идемпотентность: повторное завершение НЕ перезатирает completed_at.
            if ($row->status === TaskStatus::Completed->value) {
                return $this->mapRow($row);
            }

            $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

            DB::table('tasks')
                ->where('id', $id)
                ->where('chat_id', $chatId)
                ->update([
                    'status' => TaskStatus::Completed->value,
                    'completed_at' => $now,
                    'updated_at' => $now,
                ]);

            return $this->mustFind($id, $chatId);
        });
    }

    public function setStatusForChat(string $id, int $chatId, TaskStatus $status): Task
    {
        return DB::transaction(function () use ($id, $chatId, $status): Task {
            $row = DB::table('tasks')
                ->where('id', $id)
                ->where('chat_id', $chatId)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                throw TaskNotFoundException::byId($id, $chatId);
            }

            DB::table('tasks')
                ->where('id', $id)
                ->where('chat_id', $chatId)
                ->update([
                    'status' => $status->value,
                    'completed_at' => $status === TaskStatus::Completed ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
                    'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
                ]);

            return $this->mustFind($id, $chatId);
        });
    }

    public function deleteForChat(string $id, int $chatId): bool
    {
        return DB::table('tasks')->where('id', $id)->where('chat_id', $chatId)->delete() > 0;
    }

    private function mustFind(string $id, int $chatId): Task
    {
        return $this->findForChat($id, $chatId) ?? throw TaskNotFoundException::byId($id, $chatId);
    }

    private function mapRow(stdClass $row): Task
    {
        return new Task(
            id: (string) $row->id,
            chatId: (int) $row->chat_id,
            date: substr((string) $row->task_date, 0, 10),
            type: TaskType::from((string) $row->type),
            title: (string) $row->title,
            scheduledTime: $row->scheduled_time === null ? null : substr((string) $row->scheduled_time, 0, 5),
            estimatedMinutes: $row->estimated_minutes === null ? null : (int) $row->estimated_minutes,
            priority: (int) $row->priority,
            tags: array_values(json_decode((string) $row->tags, true, flags: JSON_THROW_ON_ERROR) ?: []),
            status: TaskStatus::from((string) $row->status),
            telegramMessageId: $row->telegram_message_id === null ? null : (int) $row->telegram_message_id,
            completedAt: $row->completed_at === null ? null : new DateTimeImmutable((string) $row->completed_at),
            createdAt: $row->created_at === null ? null : new DateTimeImmutable((string) $row->created_at),
            updatedAt: $row->updated_at === null ? null : new DateTimeImmutable((string) $row->updated_at),
        );
    }
}
