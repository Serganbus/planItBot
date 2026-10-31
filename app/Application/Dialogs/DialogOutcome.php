<?php

declare(strict_types=1);

namespace App\Application\Dialogs;

/**
 * Исход обработки ввода.
 */
enum DialogOutcome
{
    /** Диалог продолжается: задан следующий вопрос. */
    case Prompt;

    /** Диалог успешно завершён (побочный эффект выполнен). */
    case Completed;

    /** Диалог отменён (явно, по таймауту или по ошибке контекста). */
    case Cancelled;

    /** Активного диалога не было (нечего обрабатывать/отменять). */
    case Idle;
}
