<?php

declare(strict_types=1);

namespace PlannerBot\View;

use DateTimeImmutable;
use DateTimeZone;
use Planner\Domain\Model\Day;
use Planner\Domain\Model\FlexibleTask;
use Planner\Domain\Model\ScheduleItem;
use PlannerBot\Util\PriorityParser;
use PlannerBot\Util\TagParser;

/**
 * Рендерит день в текст сообщения для Telegram (parse_mode=HTML).
 */
final class DayFormatter
{
    private const WEEKDAYS = [
        1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс',
    ];

    public function __construct(private readonly DateTimeZone $timezone)
    {
    }

    public function format(Day $day, int $maxEntries = 30): string
    {
        $lines = [];
        $lines[] = '<b>📅 ' . $this->formatDateHeader($day->date) . '</b>';
        $lines[] = '🎯 Цель: ' . ($day->mainGoal !== '' ? self::e($day->mainGoal) : '<i>не задана (/goal)</i>');

        $schedule = $this->sortSchedule($day->getSchedule());
        $tasks = $this->sortTasks($day->getFlexibleTasks());

        $lines[] = '';
        $lines[] = '<b>── Расписание ──</b>';
        if ($schedule === []) {
            $lines[] = '<i>пусто (/add)</i>';
        } else {
            foreach (array_slice($schedule, 0, $maxEntries) as $item) {
                $lines[] = $this->formatScheduleItem($item);
            }
        }

        $lines[] = '';
        $lines[] = '<b>── Задачи ──</b>';
        if ($tasks === []) {
            $lines[] = '<i>пусто (/task)</i>';
        } else {
            foreach (array_slice($tasks, 0, $maxEntries) as $task) {
                $lines[] = $this->formatTask($task);
            }
        }

        $totalEntries = count($schedule) + count($tasks);
        if ($totalEntries > $maxEntries * 2) {
            $lines[] = '<i>…показаны первые ' . ($maxEntries * 2) . ' записей</i>';
        }

        $lines[] = '';
        $lines[] = $this->formatProgress($schedule, $tasks);

        $text = implode("\n", $lines);

        // Лимит Telegram: 4096 символов на сообщение.
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 3990) . "\n<i>…сообщение обрезано</i>";
        }

        return $text;
    }

    public function formatEmpty(string $date): string
    {
        return '<b>📅 ' . $this->formatDateHeader($date) . "</b>\n"
            . 'На эту дату ничего не запланировано. Добавьте пункт расписания (/add) или задачу (/task).';
    }

    public function formatDateHeader(string $date): string
    {
        $dt = new DateTimeImmutable($date, $this->timezone);
        $weekday = self::WEEKDAYS[(int) $dt->format('N')];

        return $date . ' (' . $weekday . ')';
    }

    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @param ScheduleItem[] $schedule
     * @param FlexibleTask[] $tasks
     */
    private function formatProgress(array $schedule, array $tasks): string
    {
        $shownTotal = 0;
        $shownDone = 0;
        $minutes = 0;

        foreach ($schedule as $item) {
            $shownTotal++;
            if ($item->status === 'done') {
                $shownDone++;
            }
        }
        foreach ($tasks as $task) {
            $shownTotal++;
            if ($task->status === 'done') {
                $shownDone++;
            }
            $minutes += $task->estimatedMinutes;
        }

        $parts = [sprintf('Прогресс: %d/%d', $shownDone, $shownTotal)];
        if ($minutes > 0) {
            $parts[] = sprintf('задач на %d ч %d мин', intdiv($minutes, 60), $minutes % 60);
        }

        return implode(' · ', $parts);
    }

    private function formatScheduleItem(ScheduleItem $item): string
    {
        $mark = $item->status === 'done' ? '✅' : '⬜';
        $tags = $item->tags ? ' ' . self::e(TagParser::format($item->tags)) : '';
        $title = self::e($item->title);

        $line = sprintf('%s <code>%s–%s</code> %s%s', $mark, $item->startTime, $item->endTime, $title, $tags);

        return $item->status === 'done' ? '<s>' . $line . '</s>' : $line;
    }

    private function formatTask(FlexibleTask $task): string
    {
        $mark = $task->status === 'done' ? '✅' : '⬜';
        $priority = PriorityParser::label($task->priority);
        $tags = $task->tags ? ' ' . self::e(TagParser::format($task->tags)) : '';
        $title = self::e($task->title);

        $line = sprintf('%s %s <i>[%s, %d мин]</i>%s', $mark, $title, $priority, $task->estimatedMinutes, $tags);

        return $task->status === 'done' ? '<s>' . $line . '</s>' : $line;
    }

    /**
     * @param ScheduleItem[] $schedule
     * @return ScheduleItem[]
     */
    private function sortSchedule(array $schedule): array
    {
        usort($schedule, fn(ScheduleItem $a, ScheduleItem $b): int => strcmp($a->startTime, $b->startTime));

        return $schedule;
    }

    /**
     * @param FlexibleTask[] $tasks
     * @return FlexibleTask[]
     */
    private function sortTasks(array $tasks): array
    {
        $order = ['high' => 0, 'medium' => 1, 'low' => 2];
        usort(
            $tasks,
            fn(FlexibleTask $a, FlexibleTask $b): int
                => ($order[$a->priority] ?? 1) <=> ($order[$b->priority] ?? 1)
        );

        return $tasks;
    }
}
