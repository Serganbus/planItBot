<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\DialogState;

/**
 * Хранилище состояний многошаговых диалогов (одна запись на чат).
 */
interface StateRepositoryInterface
{
    public function find(int $chatId): ?DialogState;

    public function save(DialogState $state): void;

    public function clear(int $chatId): void;
}
