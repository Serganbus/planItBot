<?php

declare(strict_types=1);

namespace App\Application\Dialogs;

/**
 * Сценарии многошаговых диалогов.
 * Строковые значения персистятся в dialog_states.flow.
 */
enum DialogFlow: string
{
    case AddScheduled = 'add_scheduled';
    case AddFlexible = 'add_flexible';
    case EditTask = 'edit_task';
    case SetGoal = 'set_goal';
}
