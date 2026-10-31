<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use DomainException;

final class TaskNotFoundException extends DomainException
{
    public static function byId(string $idOrPrefix, int $chatId): self
    {
        return new self("Задача «{$idOrPrefix}» не найдена в чате {$chatId}.");
    }
}
