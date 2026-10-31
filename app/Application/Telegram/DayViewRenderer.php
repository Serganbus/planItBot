<?php

declare(strict_types=1);

namespace App\Application\Telegram;

use App\Domain\DayPlan;
use App\Domain\Task;
use App\Domain\TaskStatus;
use App\Support\PriorityParser;
use App\Support\TagParser;
use DateTimeImmutable;

/**
 * Рендер плана дня и статистики в HTML (parse_mode=HTML).
 * Пользовательский текст пропускается через self::e().
 */
final class DayViewRenderer
{
    private const WEEKDAYS = [
        1 => 'пн', 2 => 'вт', 3 => 'ср', 4 => 'чт', 5 => 'пт', 6 => 'сб', 7 => 'вс',
    ];

    public static function formatPlan(DayPlan $plan, int $maxEntries = 30): string
    {
        $lines = [];
        $lines[] = '<b>📅 ' . self::dateHeader($plan->date) . '</b>';
        $lines[] = '🎯 Цель: ' . ($plan->mainGoal !== null && $plan->mainGoal !== ''
            ? self::e($plan->mainGoal)
            : '<i>не задана (/goal)</i>');

        $lines[] = '';
        $lines[] = '<b>── Расписание ──</b>';
        if ($plan->scheduled === []) {
            $lines[] = '<i>пусто (/add)</i>';
        } else {
            foreach (array_slice($plan->scheduled, 0, $maxEntries) as $item) {
                $lines[] = self::formatScheduled($item);
            }
        }

        $lines[] = '';
        $lines[] = '<b>── Задачи ──</b>';
        if ($plan->flexible === []) {
            $lines[] = '<i>пусто (/task)</i>';
        } else {
            foreach (array_slice($plan->flexible, 0, $maxEntries) as $task) {
                $lines[] = self::formatFlexible($task);
            }
        }

        $total = count($plan->scheduled) + count($plan->flexible);
        if ($total > $maxEntries * 2) {
            $lines[] = '<i>…показаны первые ' . ($maxEntries * 2) . ' записей</i>';
        }

        $lines[] = '';
        $lines[] = self::formatProgress($plan);

        $text = implode("\n", $lines);

        // Лимит Telegram: 4096 символов на сообщение.
        if (mb_strlen($text) > 4000) {
            $text = mb_substr($text, 0, 3990) . "\n<i>…сообщение обрезано</i>";
        }

        return $text;
    }

    public static function formatDayEmpty(string $date): string
    {
        return '<b>📅 ' . self::dateHeader($date) . "</b>\n"
            . 'На эту дату ничего не запланировано. Добавьте пункт расписания (/add) или задачу (/task).';
    }

    public static function formatStats(DayPlan $plan): string
    {
        $stats = $plan->stats;

        $lines = [];
        $lines[] = '<b>📊 Статистика: ' . self::dateHeader($plan->date) . '</b>';
        $lines[] = '';
        $lines[] = sprintf('Расписание: %d/%d выполнено', $stats['schedule_done'], $stats['schedule_total']);
        $lines[] = sprintf('Задачи: %d/%d выполнено', $stats['tasks_done'], $stats['tasks_total']);

        $minutes = $stats['total_estimated_minutes'];
        $lines[] = sprintf('Оценка задач: %d ч %d мин', intdiv($minutes, 60), $minutes % 60);

        $total = $stats['schedule_total'] + $stats['tasks_total'];
        $done = $stats['schedule_done'] + $stats['tasks_done'];
        $percent = $total > 0 ? (int) round($done / $total * 100) : 0;
        $lines[] = '';
        $lines[] = sprintf('%s %d%%', self::progressBar($percent), $percent);

        return implode("\n", $lines);
    }

    public static function dateHeader(string $date): string
    {
        $dt = new DateTimeImmutable($date);
        $weekday = self::WEEKDAYS[(int) $dt->format('N')];

        return $date . ' (' . $weekday . ')';
    }

    /**
     * Экранирование пользовательского текста для HTML parse_mode.
     */
    public static function e(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function formatScheduled(Task $item): string
    {
        $mark = $item->status === TaskStatus::Completed ? '✅' : '⬜';
        $cancelled = $item->status === TaskStatus::Cancelled ? ' 🚫' : '';
        $tags = $item->tags !== [] ? ' ' . self::e(TagParser::format($item->tags)) : '';
        $time = $item->scheduledTime ?? '--:--';

        $line = sprintf('%s <code>%s</code> %s%s%s', $mark, self::e($time), self::e($item->title), $tags, $cancelled);

        return $item->status === TaskStatus::Completed ? '<s>' . $line . '</s>' : $line;
    }

    public static function formatFlexible(Task $task): string
    {
        $mark = $task->status === TaskStatus::Completed ? '✅' : '⬜';
        $cancelled = $task->status === TaskStatus::Cancelled ? ' 🚫' : '';
        $priority = PriorityParser::label($task->priority);
        $tags = $task->tags !== [] ? ' ' . self::e(TagParser::format($task->tags)) : '';
        $est = $task->estimatedMinutes === null ? 'без оценки' : $task->estimatedMinutes . ' мин';

        $line = sprintf('%s %s <i>[%s, %s]</i>%s%s', $mark, self::e($task->title), $priority, $est, $tags, $cancelled);

        return $task->status === TaskStatus::Completed ? '<s>' . $line . '</s>' : $line;
    }

    private static function formatProgress(DayPlan $plan): string
    {
        $s = $plan->stats;
        $total = $s['schedule_total'] + $s['tasks_total'];
        $done = $s['schedule_done'] + $s['tasks_done'];

        $parts = [sprintf('Прогресс: %d/%d', $done, $total)];
        if ($s['total_estimated_minutes'] > 0) {
            $minutes = $s['total_estimated_minutes'];
            $parts[] = sprintf('задач на %d ч %d мин', intdiv($minutes, 60), $minutes % 60);
        }

        return implode(' · ', $parts);
    }

    private static function progressBar(int $percent, int $width = 10): string
    {
        $filled = (int) round($percent / 100 * $width);

        return str_repeat('▓', $filled) . str_repeat('░', $width - $filled);
    }
}
