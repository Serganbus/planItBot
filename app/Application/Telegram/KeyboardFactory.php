<?php

declare(strict_types=1);

namespace App\Application\Telegram;

use App\Domain\DayPlan;
use App\Domain\Task;
use App\Domain\TaskStatus;
use App\Support\IdResolver;
use DefStudio\Telegraph\Keyboard\Button;
use DefStudio\Telegraph\Keyboard\Keyboard;
use InvalidArgumentException;

/**
 * Inline-клавиатуры. callback_data СТРОГО ≤ 64 байта (проверяется strlen —
 * по байтам, не по символам).
 *
 * Формат данных — нативный для telegraph: "action;key:value"
 * (например, "done;id:3f2a91c0" — 15 байт, эквивалент планового "act:verb:8hex").
 * Разбор — PrefixCallbackDispatcher.
 */
final class KeyboardFactory
{
    public const A_DAY = 'day';
    public const A_STAT = 'stat';
    public const A_ADD_SCHEDULED = 'plusi';
    public const A_ADD_FLEXIBLE = 'plust';
    public const A_DONE = 'done';
    public const A_UNDO = 'undo';
    public const A_EDIT = 'edit';
    public const A_DEL = 'del';
    public const A_DELOK = 'delok';
    public const A_CANCEL_DIALOG = 'dlgcancel';

    /**
     * Собрать и валидировать callback_data (бросает исключение > 64 байт).
     * Каноническая форма: "action:done;id:1234abcd" (нативная для telegraph).
     *
     * @param array<string, string|int> $params
     */
    public static function data(string $action, array $params = []): string
    {
        $data = 'action:' . $action;
        foreach ($params as $key => $value) {
            $data .= ';' . $key . ':' . $value;
        }

        if (strlen($data) > 64) {
            throw new InvalidArgumentException('callback_data длиннее 64 байт: ' . $data);
        }

        return $data;
    }

    /**
     * Навигация по датам + разделы дня.
     */
    public static function dayNav(string $date, string $today): Keyboard
    {
        $prev = self::shiftDate($date, -1);
        $next = self::shiftDate($date, 1);

        return Keyboard::make()
            ->row([
                Button::make('◀')->action(self::A_DAY)->param('date', $prev),
                Button::make('Сегодня')->action(self::A_DAY)->param('date', $today),
                Button::make('▶')->action(self::A_DAY)->param('date', $next),
            ])
            ->row([
                Button::make('➕ Пункт')->action(self::A_ADD_SCHEDULED)->param('date', $date),
                Button::make('➕ Задачу')->action(self::A_ADD_FLEXIBLE)->param('date', $date),
                Button::make('📊')->action(self::A_STAT)->param('date', $date),
            ]);
    }

    /**
     * Список записей дня → действие по кнопке (edit/del).
     */
    public static function entries(DayPlan $plan, string $action, string $date): Keyboard
    {
        $keyboard = Keyboard::make();
        $n = 1;
        $empty = true;

        foreach ($plan->scheduled as $item) {
            $empty = false;
            $label = sprintf('%d. %s %s%s', $n, $item->scheduledTime ?? '--:--', self::cut($item->title, 24), $item->status === TaskStatus::Completed ? ' ✓' : '');
            $keyboard = $keyboard->row([Button::make($label)->action($action)->param('id', IdResolver::shorten($item->id))]);
            $n++;
        }

        foreach ($plan->flexible as $task) {
            $empty = false;
            $label = sprintf('%d. %s%s', $n, self::cut($task->title, 28), $task->status === TaskStatus::Completed ? ' ✓' : '');
            $keyboard = $keyboard->row([Button::make($label)->action($action)->param('id', IdResolver::shorten($task->id))]);
            $n++;
        }

        if ($empty) {
            $keyboard = $keyboard->row([Button::make('На этот день записей нет')->action(self::A_DAY)->param('date', $date)]);
        }

        return $keyboard->row([Button::make('« Назад к дню')->action(self::A_DAY)->param('date', $date)]);
    }

    /**
     * Список /done: невыполненные — ✅ отметить, выполненные — ↩ вернуть.
     */
    public static function doneList(DayPlan $plan, string $date): Keyboard
    {
        $keyboard = Keyboard::make();
        $n = 1;
        $empty = true;

        foreach (array_merge($plan->scheduled, $plan->flexible) as $task) {
            $empty = false;
            $done = $task->status === TaskStatus::Completed;
            $name = $task->type->value === 'scheduled' && $task->scheduledTime !== null
                ? $task->scheduledTime . ' ' . self::cut($task->title, 20)
                : self::cut($task->title, 24);

            $keyboard = $keyboard->row([Button::make(sprintf('%s %d. %s', $done ? '↩' : '✅', $n, $name))
                ->action($done ? self::A_UNDO : self::A_DONE)
                ->param('id', IdResolver::shorten($task->id))]);
            $n++;
        }

        if ($empty) {
            $keyboard = $keyboard->row([Button::make('На этот день записей нет')->action(self::A_DAY)->param('date', $date)]);
        }

        return $keyboard->row([Button::make('« Назад к дню')->action(self::A_DAY)->param('date', $date)]);
    }

    public static function statsNav(string $date, string $today): Keyboard
    {
        return Keyboard::make()
            ->row([
                Button::make('◀')->action(self::A_STAT)->param('date', self::shiftDate($date, -1)),
                Button::make('Сегодня')->action(self::A_STAT)->param('date', $today),
                Button::make('▶')->action(self::A_STAT)->param('date', self::shiftDate($date, 1)),
            ])
            ->row([Button::make('« К дню')->action(self::A_DAY)->param('date', $date)]);
    }

    public static function confirmDelete(string $shortId, string $date): Keyboard
    {
        return Keyboard::make()->row([
            Button::make('✅ Да, удалить')->action(self::A_DELOK)->param('id', $shortId),
            Button::make('« Отмена')->action(self::A_DAY)->param('date', $date),
        ]);
    }

    public static function backToDay(string $date, string $label = '📅 Показать день'): Keyboard
    {
        return Keyboard::make()->row([Button::make($label)->action(self::A_DAY)->param('date', $date)]);
    }

    /**
     * Кнопка открытия Mini App (telegraph Button::webApp — сверено в vendor).
     */
    public static function webAppButton(string $url, string $label = '📱 Открыть приложение'): Button
    {
        return Button::make($label)->webApp($url);
    }

    public static function cancelDialog(): Keyboard
    {
        return Keyboard::make()->row([Button::make('Отмена')->action(self::A_CANCEL_DIALOG)]);
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
