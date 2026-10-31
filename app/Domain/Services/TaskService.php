<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Contracts\ChatRepositoryInterface;
use App\Domain\Contracts\TaskRepositoryInterface;
use App\Domain\Exceptions\TaskNotFoundException;
use App\Domain\Task;
use App\Domain\TaskStatus;
use App\Domain\TaskType;
use App\Support\IdResolver;
use App\Support\TimeParser;
use DateTimeImmutable;
use InvalidArgumentException;
use Illuminate\Support\Str;

/**
 * Операции над задачами дня. Не знает ни о Telegram, ни о HTTP.
 * Все методы требуют chatId — принадлежность проверяется в репозитории.
 */
final class TaskService
{
    public function __construct(
        private readonly TaskRepositoryInterface $tasks,
        private readonly ChatRepositoryInterface $chats,
        private readonly IdResolver $ids,
    ) {
    }

    /**
     * @param string[] $tags
     */
    public function addScheduledTask(int $chatId, string $date, string $time, string $title, array $tags = []): Task
    {
        $this->assertDate($date);
        $time = TimeParser::parse($time)
            ?? throw new InvalidArgumentException("Некорректное время: «{$time}». Ожидается ЧЧ:ММ.");
        $this->assertTitle($title);

        $this->chats->ensure($chatId);
        $now = new DateTimeImmutable();

        return $this->tasks->insert(new Task(
            id: (string) Str::uuid(),
            chatId: $chatId,
            date: $date,
            type: TaskType::Scheduled,
            title: $title,
            scheduledTime: $time,
            estimatedMinutes: null,
            priority: 0,
            tags: array_values($tags),
            status: TaskStatus::Pending,
            telegramMessageId: null,
            completedAt: null,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    /**
     * @param string[] $tags
     */
    public function addFlexibleTask(
        int $chatId,
        string $date,
        string $title,
        ?int $estimatedMinutes = null,
        int $priority = 0,
        array $tags = [],
    ): Task {
        $this->assertDate($date);
        $this->assertTitle($title);
        if ($estimatedMinutes !== null && $estimatedMinutes < 0) {
            throw new InvalidArgumentException('Оценка времени не может быть отрицательной.');
        }

        $this->chats->ensure($chatId);
        $now = new DateTimeImmutable();

        return $this->tasks->insert(new Task(
            id: (string) Str::uuid(),
            chatId: $chatId,
            date: $date,
            type: TaskType::Flexible,
            title: $title,
            scheduledTime: null,
            estimatedMinutes: $estimatedMinutes,
            priority: $priority,
            tags: array_values($tags),
            status: TaskStatus::Pending,
            telegramMessageId: null,
            completedAt: null,
            createdAt: $now,
            updatedAt: $now,
        ));
    }

    /**
     * @return Task[]
     */
    public function forDate(int $chatId, string $date): array
    {
        return $this->tasks->forChatAndDate($chatId, $date);
    }

    /**
     * Идемпотентное завершение: транзакция + SELECT ... FOR UPDATE внутри
     * репозитория; повторный вызов возвращает задачу с первым completed_at.
     */
    public function completeTask(string $idOrPrefix, int $chatId): Task
    {
        $id = $this->ids->resolve($idOrPrefix, $chatId);

        return $this->tasks->completeForChat($id, $chatId);
    }

    public function cancelTask(string $idOrPrefix, int $chatId): Task
    {
        $id = $this->ids->resolve($idOrPrefix, $chatId);

        return $this->tasks->setStatusForChat($id, $chatId, TaskStatus::Cancelled);
    }

    /**
     * Вернуть завершённую/отменённую задачу в работу.
     */
    public function reopenTask(string $idOrPrefix, int $chatId): Task
    {
        $id = $this->ids->resolve($idOrPrefix, $chatId);

        return $this->tasks->setStatusForChat($id, $chatId, TaskStatus::Pending);
    }

    /**
     * Редактирование = замена объекта: читаем старый, собираем НОВЫЙ
     * immutable Task с тем же id и персистим целиком.
     *
     * @param array{title?: string, date?: string, time?: ?string, estimated_minutes?: ?int, priority?: int, tags?: string[]} $changes
     *   присутствующий ключ заменяет поле; отсутствующий — оставляет как было
     *   ('time' => null очищает время)
     */
    public function editTask(string $idOrPrefix, int $chatId, array $changes): Task
    {
        $id = $this->ids->resolve($idOrPrefix, $chatId);
        $old = $this->tasks->findForChat($id, $chatId) ?? throw TaskNotFoundException::byId($id, $chatId);

        $date = array_key_exists('date', $changes) ? (string) $changes['date'] : $old->date;
        $this->assertDate($date);

        $time = array_key_exists('time', $changes) ? $changes['time'] : $old->scheduledTime;
        if ($time !== null) {
            $time = TimeParser::parse($time)
                ?? throw new InvalidArgumentException("Некорректное время: «{$time}».");
        }

        $title = array_key_exists('title', $changes) ? (string) $changes['title'] : $old->title;
        $this->assertTitle($title);

        $estimatedMinutes = array_key_exists('estimated_minutes', $changes) ? $changes['estimated_minutes'] : $old->estimatedMinutes;
        $priority = array_key_exists('priority', $changes) ? (int) $changes['priority'] : $old->priority;
        $tags = array_key_exists('tags', $changes) ? array_values((array) $changes['tags']) : $old->tags;

        return $this->tasks->replace(new Task(
            id: $old->id,
            chatId: $old->chatId,
            date: $date,
            type: $old->type,
            title: $title,
            scheduledTime: $time,
            estimatedMinutes: $estimatedMinutes,
            priority: $priority,
            tags: $tags,
            status: $old->status,
            telegramMessageId: $old->telegramMessageId,
            completedAt: $old->completedAt,
            createdAt: $old->createdAt,
            updatedAt: new DateTimeImmutable(),
        ));
    }

    public function deleteTask(string $idOrPrefix, int $chatId): void
    {
        $id = $this->ids->resolve($idOrPrefix, $chatId);

        if (!$this->tasks->deleteForChat($id, $chatId)) {
            throw TaskNotFoundException::byId($id, $chatId);
        }
    }

    private function assertDate(string $date): void
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m)
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            throw new InvalidArgumentException("Некорректная дата: «{$date}». Ожидается YYYY-MM-DD.");
        }
    }

    private function assertTitle(string $title): void
    {
        if (trim($title) === '') {
            throw new InvalidArgumentException('Название задачи не может быть пустым.');
        }
    }
}
