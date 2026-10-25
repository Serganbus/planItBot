<?php

declare(strict_types=1);

namespace PlannerBot\Infrastructure;

use Planner\Domain\Repository\DayRepositoryInterface;
use Planner\Domain\Service\PlannerService;
use Planner\Infrastructure\Repository\JsonDayRepository;
use PlannerBot\State\StateStore;

/**
 * Создаёт изолированный набор сервисов для каждого чата.
 */
final class ChatContextFactory
{
    public function __construct(private readonly string $dataDir)
    {
    }

    public function forChat(int $chatId): ChatContext
    {
        $dir = $this->chatDir($chatId);
        $repository = $this->repository($dir);

        return new ChatContext(
            $chatId,
            new PlannerService($repository),
            $repository,
            new StateStore($dir),
        );
    }

    public function chatDir(int $chatId): string
    {
        return rtrim($this->dataDir, '/\\') . '/chats/' . $chatId;
    }

    private function repository(string $dir): DayRepositoryInterface
    {
        return new JsonDayRepository($dir);
    }
}
