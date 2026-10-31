<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Тип записи плана дня.
 *
 * Строковые значения зафиксированы в БД CHECK-ограничением
 * `tasks_type_check` (миграция create_tasks_table) — менять
 * значения можно только вместе с миграцией.
 */
enum TaskType: string
{
    /** Элемент расписания с конкретным временем начала. */
    case Scheduled = 'scheduled';

    /** Гибкая задача без привязки ко времени, с оценкой в минутах. */
    case Flexible = 'flexible';

    /** Главная цель дня. */
    case Goal = 'goal';
}
