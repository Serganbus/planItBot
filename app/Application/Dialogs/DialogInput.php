<?php

declare(strict_types=1);

namespace App\Application\Dialogs;

/**
 * Ввод пользователя в диалог: текст ИЛИ сигнал отмены (кнопка отмены).
 */
final readonly class DialogInput
{
    private function __construct(
        public ?string $text,
        public bool $cancelRequested,
    ) {
    }

    public static function text(string $text): self
    {
        return new self(trim($text), false);
    }

    public static function cancel(): self
    {
        return new self(null, true);
    }

    public function isCancel(): bool
    {
        return $this->cancelRequested;
    }
}
