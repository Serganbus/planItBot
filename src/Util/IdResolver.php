<?php

declare(strict_types=1);

namespace PlannerBot\Util;

use Planner\Domain\Model\Day;
use Planner\Domain\Model\FlexibleTask;
use Planner\Domain\Model\ScheduleItem;

/**
 * Находит элемент дня по 8-символьному префиксу UUID
 * (UUID целиком не помещается в лимит callback_data = 64 байта).
 */
final class IdResolver
{
    public const PREFIX_LENGTH = 8;

    public static function shorten(string $uuid): string
    {
        return substr($uuid, 0, self::PREFIX_LENGTH);
    }

    /**
     * @return array{0:string,1:ScheduleItem|FlexibleTask}|null [тип ('item'|'task'), элемент]
     */
    public static function resolve(Day $day, string $prefix): ?array
    {
        foreach ($day->getSchedule() as $item) {
            if (str_starts_with($item->id, $prefix)) {
                return ['item', $item];
            }
        }

        foreach ($day->getFlexibleTasks() as $task) {
            if (str_starts_with($task->id, $prefix)) {
                return ['task', $task];
            }
        }

        return null;
    }

    public static function resolveScheduleItem(Day $day, string $prefix): ?ScheduleItem
    {
        foreach ($day->getSchedule() as $item) {
            if (str_starts_with($item->id, $prefix)) {
                return $item;
            }
        }

        return null;
    }

    public static function resolveFlexibleTask(Day $day, string $prefix): ?FlexibleTask
    {
        foreach ($day->getFlexibleTasks() as $task) {
            if (str_starts_with($task->id, $prefix)) {
                return $task;
            }
        }

        return null;
    }
}
