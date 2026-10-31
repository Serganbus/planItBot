<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Статус задачи.
 *
 * Строковые значения зафиксированы в БД CHECK-ограничением
 * `tasks_status_check` (миграция create_tasks_table).
 */
enum TaskStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
