<?php

declare(strict_types=1);

namespace App\Application\Dialogs\Contracts;

use App\Application\Dialogs\DialogFlow;
use App\Application\Dialogs\DialogInput;
use App\Application\Dialogs\DialogStep;
use App\Application\Dialogs\DialogTransition;

/**
 * Обработчик одного диалогового сценария.
 *
 * begin() и transition() — ЧИСТЫЕ функции (без побочных эффектов и БД):
 * (контекст | шаг, payload, ввод) -> DialogTransition.
 * complete() — единственная граница с побочными эффектами (доменные сервисы),
 * вызывается машиной один раз после исхода Completed, состояние уже очищено.
 */
interface DialogHandlerInterface
{
    public function flow(): DialogFlow;

    /**
     * Первый шаг. Контекст: стартовый payload (теги, дата из кнопки,
     * task_id для edit_task и служебный 'timezone' — подставляет машина).
     *
     * @param array<string, mixed> $context
     */
    public function begin(array $context): DialogTransition;

    /**
     * @param array<string, mixed> $payload
     */
    public function transition(DialogStep $step, array $payload, DialogInput $input): DialogTransition;

    /**
     * Побочный эффект завершённого диалога. Возвращает финальное
     * сообщение пользователю.
     *
     * @param array<string, mixed> $payload
     */
    public function complete(int $chatId, array $payload): string;
}
