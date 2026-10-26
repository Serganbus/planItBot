<?php

declare(strict_types=1);

namespace PlannerBot\Service;

use Planner\Domain\Model\FlexibleTask;
use Planner\Domain\Model\ScheduleItem;
use PlannerBot\Infrastructure\ChatContext;

/**
 * Редактирование элементов дня.
 *
 * Реализовано пересозданием элемента с тем же id: remove + add в Day,
 * сохранение через репозиторий. Изначально так сделано из-за того, что
 * в пакете planner до v1.0.1 методы updateScheduleItem()/updateFlexibleTask()
 * меняли readonly-свойства через reflection и падали на PHP >= 8.4
 * (в v1.0.1 reflection убран, readonly остался только у id).
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
