<?php

declare(strict_types=1);

namespace App\Application\Dialogs;

/**
 * Результат ЧИСТОЙ функции перехода обработчика:
 * (текущий шаг, payload, ввод) -> переход.
 * Никаких побочных эффектов здесь быть не должно.
 */
final readonly class DialogTransition
{
    /**
     * @param array<string, mixed> $payload новый payload состояния диалога
     */
    private function __construct(
        public DialogOutcome $outcome,
        public array $payload,
        public ?DialogStep $step = null,
        public ?string $message = null,
    ) {
    }

    /**
     * Перейти на следующий шаг и задать вопрос пользователю
     * (тот же шаг = повторный вопрос после невалидного ввода).
     *
     * @param array<string, mixed> $payload
     */
    public static function prompt(DialogStep $step, array $payload, string $message): self
    {
        return new self(DialogOutcome::Prompt, $payload, $step, $message);
    }

    /**
     * Ввод завершающий: машина очистит состояние и вызовет
     * DialogHandlerInterface::complete() за финальным сообщением.
     *
     * @param array<string, mixed> $payload
     */
    public static function complete(array $payload): self
    {
        return new self(DialogOutcome::Completed, $payload);
    }

    /**
     * Сценарий решает прерваться сам (например, битый контекст begin()).
     */
    public static function cancel(string $message): self
    {
        return new self(DialogOutcome::Cancelled, [], null, $message);
    }
}
