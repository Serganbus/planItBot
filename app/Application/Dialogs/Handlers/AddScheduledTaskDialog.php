<?php

declare(strict_types=1);

namespace App\Application\Dialogs\Handlers;

use App\Application\Dialogs\DialogFlow;
use App\Application\Dialogs\DialogInput;
use App\Application\Dialogs\DialogStep;
use App\Application\Dialogs\DialogTransition;
use App\Domain\Services\TaskService;
use App\Support\TagParser;
use App\Support\TimeParser;

/**
 * Добавление записи расписания: название -> дата -> время -> теги.
 * Если дата передана в контексте (кнопка «+» у дня), шаг даты пропускается.
 */
final class AddScheduledTaskDialog extends AbstractDialogHandler
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    public function flow(): DialogFlow
    {
        return DialogFlow::AddScheduled;
    }

    public function begin(array $context): DialogTransition
    {
        return DialogTransition::prompt(
            $this->step($this->flow(), 'title'),
            $context,
            'Новая запись расписания. Введите название:',
        );
    }

    public function transition(DialogStep $step, array $payload, DialogInput $input): DialogTransition
    {
        $text = (string) $input->text;

        if ($step->is('title')) {
            $title = trim($text);
            if ($title === '') {
                return DialogTransition::prompt($step, $payload, '❌ Название не может быть пустым. Попробуйте ещё раз:');
            }
            $payload['title'] = $title;

            return isset($payload['date'])
                ? DialogTransition::prompt($this->step($this->flow(), 'time'), $payload, 'Во сколько? (ЧЧ:ММ)')
                : DialogTransition::prompt($this->step($this->flow(), 'date'), $payload, 'На какую дату? (сегодня / завтра / +N / ДД.ММ.ГГГГ)');
        }

        if ($step->is('date')) {
            $date = self::parseDate($payload, $text);
            if ($date === null) {
                return DialogTransition::prompt($step, $payload, '❌ Не удалось разобрать дату (сегодня / завтра / +N / ДД.ММ.ГГГГ). Попробуйте ещё раз:');
            }
            $payload['date'] = $date;

            return DialogTransition::prompt($this->step($this->flow(), 'time'), $payload, 'Во сколько? (ЧЧ:ММ)');
        }

        if ($step->is('time')) {
            $time = TimeParser::parse($text);
            if ($time === null) {
                return DialogTransition::prompt($step, $payload, '❌ Не удалось разобрать время. Формат ЧЧ:ММ, попробуйте ещё раз:');
            }
            $payload['time'] = $time;

            return DialogTransition::prompt($this->step($this->flow(), 'tags'), $payload, 'Теги через пробел (#работа #дом) или «-», если без тегов:');
        }

        if ($step->is('tags')) {
            $payload['tags'] = self::isSkip($text) ? [] : TagParser::parseMany($text);

            return DialogTransition::complete($payload);
        }

        return DialogTransition::cancel('❌ Неизвестный шаг диалога. Диалог прерван, начните заново.');
    }

    public function complete(int $chatId, array $payload): string
    {
        $task = $this->tasks->addScheduledTask(
            $chatId,
            (string) $payload['date'],
            (string) $payload['time'],
            (string) $payload['title'],
            (array) ($payload['tags'] ?? []),
        );

        $short = mb_substr($task->id, 0, 8);

        return "✅ Добавлено в расписание: {$task->date} {$task->scheduledTime} «{$task->title}» (id: {$short})";
    }
}
