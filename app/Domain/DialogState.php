<?php

declare(strict_types=1);

namespace App\Domain;

use DateTimeImmutable;

/**
 * Immutable-снимок состояния диалога (таблица dialog_states).
 */
final readonly class DialogState
{
    public function __construct(
        public int $chatId,
        public string $flow,
        public string $step,
        /** @var array<string, mixed> */
        public array $payload,
        public ?DateTimeImmutable $expiresAt,
        public ?DateTimeImmutable $updatedAt,
    ) {
    }

    public function isExpired(DateTimeImmutable $now): bool
    {
        return $this->expiresAt !== null && $this->expiresAt <= $now;
    }
}
