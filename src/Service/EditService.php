<?php

declare(strict_types=1);

namespace PlannerBot\Service;

use Planner\Domain\Model\FlexibleTask;
use Planner\Domain\Model\ScheduleItem;
use PlannerBot\Infrastructure\ChatContext;

/**
 * Редактирование элементов дня.
 *
 * В пакете planner/cli методы updateScheduleItem()/updateFlexibleTask()
 * меняют readonly-свойства через reflection — на PHP >= 8.4 это приводит
 * к ошибке "Cannot modify readonly property". Поэтому редактирование
 * реализовано здесь как пересоздание элемента с тем же id: remove + add в Day,
 * сохранение через репозиторий. Reflection не используется.
 */
final class EditService
{
    /**
     * @param string[]|null $tags
     */
    public function updateScheduleItem(
        ChatContext $chat,
        string $date,
        string $id,
        ?string $startTime = null,
        ?string $endTime = null,
        ?string $title = null,
        ?array $tags = null,
    ): bool {
        $day = $chat->planner->getDay($date);
        $item = $day?->getScheduleItem($id);
        if ($day === null || $item === null) {
            return false;
        }

        $day->removeScheduleItem($id);
        $day->addScheduleItem(new ScheduleItem(
            $item->id,
            $startTime ?? $item->startTime,
            $endTime ?? $item->endTime,
            $title ?? $item->title,
            $tags ?? $item->tags,
            $item->status,
        ));
        $chat->repository->save($day);

        return true;
    }

    /**
     * @param string[]|null $tags
     */
    public function updateFlexibleTask(
        ChatContext $chat,
        string $date,
        string $id,
        ?string $title = null,
        ?int $estimatedMinutes = null,
        ?string $priority = null,
        ?array $tags = null,
    ): bool {
        $day = $chat->planner->getDay($date);
        $task = $day?->getFlexibleTask($id);
        if ($day === null || $task === null) {
            return false;
        }

        $day->removeFlexibleTask($id);
        $day->addFlexibleTask(new FlexibleTask(
            $task->id,
            $title ?? $task->title,
            $estimatedMinutes ?? $task->estimatedMinutes,
            $priority ?? $task->priority,
            $tags ?? $task->tags,
            $task->status,
        ));
        $chat->repository->save($day);

        return true;
    }
}
