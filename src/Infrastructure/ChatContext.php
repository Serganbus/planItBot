<?php

declare(strict_types=1);

namespace PlannerBot\Infrastructure;

use Planner\Domain\Repository\DayRepositoryInterface;
use Planner\Domain\Service\PlannerService;
use PlannerBot\State\StateStore;

/**
 * Все сервисы, привязанные к одному чату.
 */
final class ChatContext
{
    public function __construct(
        public readonly int $chatId,
        public readonly PlannerService $planner,
        public readonly DayRepositoryInterface $repository,
        public readonly StateStore $state,
    ) {
    }
}
