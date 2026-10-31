<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use DomainException;

/**
 * Префикс id совпал с несколькими задачами: «первый попавшийся» недопустим,
 * нужен более длинный префикс.
 */
final class AmbiguousTaskIdException extends DomainException
{
    /**
     * @param string[] $candidates
     */
    public function __construct(
        public readonly string $prefix,
        public readonly array $candidates,
    ) {
        parent::__construct(
            "Префикс «{$prefix}» неоднозначен: " . count($candidates) . ' задач. Уточните префикс.'
        );
    }
}
