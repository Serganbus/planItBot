<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Доменная задача — immutable-снимок строки таблицы tasks.
 *
 * Изменение задачи = создание НОВОГО объекта с тем же id
 * (персистит PostgresTaskRepository::replace()), никаких мутаций.
 */
final readonly class Task
{
    /**
     * @param string[] $tags
     */
    public function __construct(
        public string $id,
        public int $chatId,
        public string $date,
        public TaskType $type,
        public string $title,
        public ?string $scheduledTime,
        public ?int $estimatedMinutes,
        public int $priority,
        public array $tags,
        public TaskStatus $status,
        public ?int $telegramMessageId,
        public ?DateTimeImmutable $completedAt,
        public ?DateTimeImmutable $createdAt,
        public ?DateTimeImmutable $updatedAt,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === TaskStatus::Pending;
    }
}
