<?php

declare(strict_types=1);

namespace PlannerBot\View;

/**
 * Рендерит статистику дня (PlannerService::getStats()).
 */
final class StatsFormatter
{
    public function __construct(private readonly DayFormatter $dayFormatter)
    {
    }

    /**
     * @param array{schedule_total:int,schedule_done:int,tasks_total:int,tasks_done:int,total_estimated_minutes:int} $stats
     */
    public function format(string $date, array $stats): string
    {
        $lines = [];
        $lines[] = '<b>📊 Статистика: ' . $this->dayFormatter->formatDateHeader($date) . '</b>';
        $lines[] = '';
        $lines[] = sprintf('Расписание: %d/%d выполнено', $stats['schedule_done'], $stats['schedule_total']);
        $lines[] = sprintf('Задачи: %d/%d выполнено', $stats['tasks_done'], $stats['tasks_total']);

        $minutes = $stats['total_estimated_minutes'];
        $lines[] = sprintf('Оценка задач: %d ч %d мин', intdiv($minutes, 60), $minutes % 60);

        $total = $stats['schedule_total'] + $stats['tasks_total'];
        $done = $stats['schedule_done'] + $stats['tasks_done'];
        $percent = $total > 0 ? (int) round($done / $total * 100) : 0;
        $lines[] = '';
        $lines[] = sprintf('%s %d%%', $this->progressBar($percent), $percent);

        return implode("\n", $lines);
    }

    private function progressBar(int $percent, int $width = 10): string
    {
        $filled = (int) round($percent / 100 * $width);

        return str_repeat('▓', $filled) . str_repeat('░', $width - $filled);
    }
}
