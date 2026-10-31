<?php

declare(strict_types=1);

namespace App\Application\Dialogs;

use Stringable;

/**
 * Шаг диалога внутри конкретного сценария (значение персистится в dialog_states.step).
 */
final readonly class DialogStep implements Stringable
{
    public function __construct(
        public DialogFlow $flow,
        public string $name,
    ) {
    }

    public function is(string $name): bool
    {
        return $this->name === $name;
    }

    public function __toString(): string
    {
        return $this->flow->value . ':' . $this->name;
    }
}
