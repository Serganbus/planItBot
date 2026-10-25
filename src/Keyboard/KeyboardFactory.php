<?php

declare(strict_types=1);

namespace PlannerBot\Keyboard;

use Planner\Domain\Model\Day;
use Planner\Domain\Model\FlexibleTask;
use Planner\Domain\Model\ScheduleItem;
use PlannerBot\Callback\CallbackRouter;
use PlannerBot\Util\IdResolver;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;

/**
 * Строит inline-клавиатуры. Все callback_data проходят проверку на 64 байта.
 */
final class KeyboardFactory
{
    /**
     * Навигация по датам + разделы.
     */
    public static function dayNav(string $date, string $today): InlineKeyboardMarkup
    {
        $prev = self::shiftDate($date, -1);
        $next = self::shiftDate($date, 1);

        $rows = [[
            self::btn('◀', CallbackRouter::PREFIX_DAY . $prev),
            self::btn('Сегодня', CallbackRouter::PREFIX_DAY . $today),
            self::btn('▶', CallbackRouter::PREFIX_DAY . $next),
        ], [
            self::btn('➕ Пункт', CallbackRouter::PREFIX_ITEMMENU . $date),
            self::btn('➕ Задачу', CallbackRouter::PREFIX_TASKMENU . $date),
            self::btn('📊', CallbackRouter::PREFIX_STATDAY . $date),
        ]];

        return new InlineKeyboardMarkup($rows);
    }

    /**
     * Единый список элементов и задач дня: номера → действие по префиксу.
     *
     * @param 'del'|'edit' $action
     */
    public static function entries(Day $day, string $date, string $action, string $backTo): InlineKeyboardMarkup
    {
        $prefix = match ($action) {
            'del' => CallbackRouter::PREFIX_DEL,
            'edit' => CallbackRouter::PREFIX_EDIT,
            default => throw new \InvalidArgumentException('Unknown action: ' . $action),
        };

        $rows = [];
        $n = 1;

        $schedule = self::sortedSchedule($day);
        foreach ($schedule as $item) {
            $label = sprintf('%d. %s–%s %s%s', $n, $item->startTime, $item->endTime, self::cut($item->title, 24), $item->status === 'done' ? ' ✓' : '');
            $rows[] = [self::btn($label, $prefix . IdResolver::shorten($item->id))];
            $n++;
        }

        foreach (self::sortedTasks($day) as $task) {
            $label = sprintf('%d. %s%s', $n, self::cut($task->title, 28), $task->status === 'done' ? ' ✓' : '');
            $rows[] = [self::btn($label, $prefix . IdResolver::shorten($task->id))];
            $n++;
        }

        if ($rows === []) {
            $rows[] = [self::btn('На этот день записей нет', CallbackRouter::PREFIX_DAY . $date)];
        }

        $rows[] = [self::btn('« Назад к дню', $backTo)];

        return new InlineKeyboardMarkup($rows);
    }

    /**
     * Список для /done: сначала невыполненные (✅ отметить), затем выполненные (↩ вернуть).
     */
    public static function doneList(Day $day, string $date): InlineKeyboardMarkup
    {
        $rows = [];
        $n = 1;

        foreach (self::sortedSchedule($day) as $item) {
            $prefix = $item->status === 'done' ? CallbackRouter::PREFIX_UNDONE : CallbackRouter::PREFIX_DONE;
            $icon = $item->status === 'done' ? '↩' : '✅';
            $rows[] = [self::btn(
                sprintf('%s %d. %s–%s %s', $icon, $n, $item->startTime, $item->endTime, self::cut($item->title, 22)),
                $prefix . IdResolver::shorten($item->id)
            )];
            $n++;
        }

        foreach (self::sortedTasks($day) as $task) {
            $prefix = $task->status === 'done' ? CallbackRouter::PREFIX_UNDONE : CallbackRouter::PREFIX_DONE;
            $icon = $task->status === 'done' ? '↩' : '✅';
            $rows[] = [self::btn(
                sprintf('%s %d. %s', $icon, $n, self::cut($task->title, 26)),
                $prefix . IdResolver::shorten($task->id)
            )];
            $n++;
        }

        if ($rows === []) {
            $rows[] = [self::btn('На этот день записей нет', CallbackRouter::PREFIX_DAY . $date)];
        }

        $rows[] = [self::btn('« Назад к дню', CallbackRouter::PREFIX_DAY . $date)];

        return new InlineKeyboardMarkup($rows);
    }

    /**
     * Навигация для экрана статистики.
     */
    public static function statsNav(string $date, string $today): InlineKeyboardMarkup
    {
        return new InlineKeyboardMarkup([[
            self::btn('◀', CallbackRouter::PREFIX_STATDAY . self::shiftDate($date, -1)),
            self::btn('Сегодня', CallbackRouter::PREFIX_STATDAY . $today),
            self::btn('▶', CallbackRouter::PREFIX_STATDAY . self::shiftDate($date, 1)),
        ], [
            self::btn('« К дню', CallbackRouter::PREFIX_DAY . $date),
        ]]);
    }

    public static function confirmDelete(string $prefixId, string $backTo): InlineKeyboardMarkup
    {
        return new InlineKeyboardMarkup([[
            self::btn('✅ Да, удалить', CallbackRouter::PREFIX_DELOK . $prefixId),
            self::btn('« Отмена', $backTo),
        ]]);
    }

    /**
     * Одна кнопка "показать день".
     */
    public static function backToDay(string $date, string $label = '📅 Показать день'): InlineKeyboardMarkup
    {
        return new InlineKeyboardMarkup([[self::btn($label, CallbackRouter::PREFIX_DAY . $date)]]);
    }

    /**
     * @return array<string,string>
     */
    private static function btn(string $text, string $callbackData): array
    {
        if (!CallbackRouter::isValid($callbackData)) {
            throw new \InvalidArgumentException('Invalid callback_data: ' . $callbackData);
        }

        return ['text' => $text, 'callback_data' => $callbackData];
    }

    /**
     * @param ScheduleItem[] $items
     * @return ScheduleItem[]
     */
    private static function sortedSchedule(Day $day): array
    {
        $items = $day->getSchedule();
        usort($items, fn(ScheduleItem $a, ScheduleItem $b): int => strcmp($a->startTime, $b->startTime));

        return $items;
    }

    /**
     * @return FlexibleTask[]
     */
    private static function sortedTasks(Day $day): array
    {
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        $tasks = $day->getFlexibleTasks();
        usort(
            $tasks,
            fn(FlexibleTask $a, FlexibleTask $b): int
                => ($order[$a->priority] ?? 1) <=> ($order[$b->priority] ?? 1)
        );

        return $tasks;
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }

    private static function shiftDate(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format('Y-m-d');
    }
}
