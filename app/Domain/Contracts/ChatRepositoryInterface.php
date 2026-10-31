<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

/**
 * Доступ к профилю чата и главной цели дня (таблица chats).
 *
 * ВЫХОДИТ ЗА РАМКИ явного списка контрактов Шага 3 плана: goal в схеме
 * (Шаг 2) лежит в chats.goal_date/main_goal, поэтому GoalService нужен
 * собственный канал к chats, замешивать его в TaskRepository нельзя.
 */
interface ChatRepositoryInterface
{
    /**
     * Гарантирует наличие строки чата (идемпотентно, дефолты схемы).
     */
    public function ensure(int $chatId): void;

    public function timezoneOf(int $chatId): string;

    /**
     * Цель чата на конкретную дату (null, если цель установлена на другую дату или отсутствует).
     */
    public function getGoal(int $chatId, string $date): ?string;

    public function setGoal(int $chatId, string $date, string $goal): void;
}
