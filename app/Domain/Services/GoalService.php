<?php

declare(strict_types=1);

namespace App\Domain\Services;

use App\Domain\Contracts\ChatRepositoryInterface;

/**
 * Главная цель дня: цель привязана к конкретной дате
 * (chats.goal_date + chats.main_goal).
 */
final class GoalService
{
    public function __construct(private readonly ChatRepositoryInterface $chats)
    {
    }

    public function setGoal(int $chatId, string $date, string $goal): void
    {
        $this->chats->setGoal($chatId, $date, trim($goal));
    }

    /**
     * Цель на дату; null, если цели нет или она установлена на другой день.
     */
    public function getGoal(int $chatId, string $date): ?string
    {
        return $this->chats->getGoal($chatId, $date);
    }
}
