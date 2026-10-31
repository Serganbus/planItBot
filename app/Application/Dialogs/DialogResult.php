<?php

declare(strict_types=1);

namespace App\Application\Dialogs;

use App\Domain\DialogState;

/**
 * То, что машина возвращает вызывающему коду (Шаг 5: роутер ответов Telegram).
 */
final readonly class DialogResult
{
    /**
     * @param string[] $messages
     */
    private function __construct(
        public DialogOutcome $outcome,
        public array $messages,
        public ?DialogState $state,
    ) {
    }

    public static function prompt(string $message, DialogState $state): self
    {
        return new self(DialogOutcome::Prompt, [$message], $state);
    }

    /**
     * @param string[] $messages
     */
    public static function completed(array $messages): self
    {
        return new self(DialogOutcome::Completed, $messages, null);
    }

    /** @param string[] $messages */
    public static function cancelled(array $messages): self
    {
        return new self(DialogOutcome::Cancelled, $messages, null);
    }

    public static function idle(string $message): self
    {
        return new self(DialogOutcome::Idle, [$message], null);
    }

    /**
     * Все сообщения одним текстом (удобно и для отправки, и для ассертов).
     */
    public function text(): string
    {
        return implode("\n", array_filter($this->messages));
    }
}
