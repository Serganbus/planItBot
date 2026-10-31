<?php

declare(strict_types=1);

namespace App\Application\Dialogs;

use App\Application\Dialogs\Contracts\DialogHandlerInterface;
use InvalidArgumentException;

/**
 * Реестр обработчиков сценариев: flow -> handler.
 */
final class DialogHandlerRegistry
{
    /** @var array<string, DialogHandlerInterface> */
    private array $handlers = [];

    /**
     * @param DialogHandlerInterface[] $handlers
     */
    public function __construct(array $handlers)
    {
        foreach ($handlers as $handler) {
            $this->handlers[$handler->flow()->value] = $handler;
        }
    }

    public function get(DialogFlow $flow): DialogHandlerInterface
    {
        return $this->handlers[$flow->value]
            ?? throw new InvalidArgumentException("Нет обработчика для диалога {$flow->value}.");
    }
}
