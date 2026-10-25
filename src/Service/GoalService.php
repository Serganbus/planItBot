<?php

declare(strict_types=1);

namespace PlannerBot\Service;

use Planner\Domain\Model\Day;
use PlannerBot\Infrastructure\ChatContext;

/**
 * Управляет главной целью дня (в PlannerService нет прямого метода —
 * целевое поле Day->mainGoal сохраняем через репозиторий чата).
 */
final class GoalService
{
    public function setGoal(ChatContext $chat, string $date, string $goal): Day
    {
        $day = $chat->planner->getOrCreateDay($date);
        $day->mainGoal = $goal;
        $chat->repository->save($day);

        return $day;
    }
}
