<?php

declare(strict_types=1);

namespace App\Application\Dialogs;

use App\Application\Dialogs\Contracts\DialogHandlerInterface;
use App\Domain\Contracts\ChatRepositoryInterface;
use App\Domain\Contracts\StateRepositoryInterface;
use App\Domain\DialogState;
use DateTimeImmutable;
use DomainException;

/**
 * Конечный автомат многошаговых диалогов.
 *
 * Состояние — ТОЛЬКО в dialog_states (StateRepositoryInterface), ничего
 * в памяти процесса: диалог переживает рестарт воркера/процесса.
 *
 * Правила (план Шага 4):
 * - переходы — чистые функции обработчиков, машина лишь персистит итог;
 * - таймаут состояния через expires_at (очистка — команда dialogs:purge-stale);
 * - отмена (кнопка/«/cancel») сбрасывает диалог;
 * - невалидный ввод НЕ ломает состояние: обработчик возвращает Prompt
 *   на тот же шаг с сообщением об ошибке.
 */
final class DialogStateMachine
{
    public const CANCEL_WORDS = ['/cancel', 'отмена', 'cancel', 'стоп'];

    public function __construct(
        private readonly StateRepositoryInterface $states,
        private readonly ChatRepositoryInterface $chats,
        private readonly DialogHandlerRegistry $handlers,
        private readonly int $ttlMinutes = 30,
    ) {
    }

    /**
     * Старт диалога. Если другой диалог уже был активен — он перезапускается.
     *
     * @param array<string, mixed> $context стартовый payload (дата из кнопки,
     *                                      task_id для edit_task и т.п.)
     */
    public function begin(int $chatId, DialogFlow $flow, array $context = []): DialogResult
    {
        $this->chats->ensure($chatId);
        $context += ['timezone' => $this->chats->timezoneOf($chatId)];

        $handler = $this->handlers->get($flow);
        $transition = $handler->begin($context);

        if ($transition->outcome !== DialogOutcome::Prompt || $transition->step === null) {
            $this->states->clear($chatId);

            return DialogResult::cancelled([$transition->message ?? '❌ Диалог не может быть начат.']);
        }

        $state = $this->saveState($chatId, $flow, $transition->step->name, $transition->payload);

        return DialogResult::prompt($transition->message ?? '', $state);
    }

    public function handleText(int $chatId, string $text): DialogResult
    {
        $input = $this->isCancelWord($text) ? DialogInput::cancel() : DialogInput::text($text);

        return $this->advance($chatId, $input);
    }

    /**
     * Явная отмена (кнопка «Отмена»): сбрасывает активный диалог.
     */
    public function cancel(int $chatId): DialogResult
    {
        $state = $this->states->find($chatId);
        $this->states->clear($chatId);

        if ($state === null) {
            return DialogResult::idle('Активного диалога нет.');
        }

        return DialogResult::cancelled(['Диалог отменён.']);
    }

    public function activeState(int $chatId): ?DialogState
    {
        return $this->states->find($chatId);
    }

    private function advance(int $chatId, DialogInput $input): DialogResult
    {
        $state = $this->states->find($chatId);

        if ($state === null) {
            return DialogResult::idle('Диалог не начат.');
        }

        if ($state->isExpired(new DateTimeImmutable())) {
            $this->states->clear($chatId);

            return DialogResult::cancelled(['⌛ Диалог истёк по таймауту. Начните заново.']);
        }

        $flow = DialogFlow::from($state->flow);
        $handler = $this->handlers->get($flow);

        if ($input->isCancel()) {
            $this->states->clear($chatId);

            return DialogResult::cancelled(['Диалог отменён.']);
        }

        $transition = $handler->transition(new DialogStep($flow, $state->step), $state->payload, $input);

        return match ($transition->outcome) {
            DialogOutcome::Prompt => $transition->step === null
                ? $this->finalizeCancel($chatId, '❌ Внутренняя ошибка перехода, диалог прерван.')
                : DialogResult::prompt(
                    $transition->message ?? '',
                    $this->saveState($chatId, $flow, $transition->step->name, $transition->payload),
                ),
            DialogOutcome::Completed => $this->finalizeComplete($chatId, $handler, $transition->payload),
            DialogOutcome::Cancelled => $this->finalizeCancel($chatId, $transition->message ?? 'Диалог прерван.'),
            DialogOutcome::Idle => DialogResult::idle($transition->message ?? ''),
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function finalizeComplete(int $chatId, DialogHandlerInterface $handler, array $payload): DialogResult
    {
        $this->states->clear($chatId);

        try {
            return DialogResult::completed([$handler->complete($chatId, $payload)]);
        } catch (DomainException $e) {
            // Доменная ошибка на финише (например, задача удалена) — диалог всё равно закрываем.
            return DialogResult::cancelled(['❌ ' . $e->getMessage()]);
        }
    }

    private function finalizeCancel(int $chatId, string $message): DialogResult
    {
        $this->states->clear($chatId);

        return DialogResult::cancelled([$message]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function saveState(int $chatId, DialogFlow $flow, string $step, array $payload): DialogState
    {
        $expiresAt = (new DateTimeImmutable())->modify("+{$this->ttlMinutes} minutes");

        $state = new DialogState(
            chatId: $chatId,
            flow: $flow->value,
            step: $step,
            payload: $payload,
            expiresAt: $expiresAt,
            updatedAt: new DateTimeImmutable(),
        );

        $this->states->save($state);

        return $state;
    }

    private function isCancelWord(string $text): bool
    {
        return in_array(mb_strtolower(trim($text)), self::CANCEL_WORDS, true);
    }
}
