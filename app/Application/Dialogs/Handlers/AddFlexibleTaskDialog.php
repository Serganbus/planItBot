<?php

declare(strict_types=1);

namespace App\Application\Dialogs\Handlers;

use App\Application\Dialogs\DialogFlow;
use App\Application\Dialogs\DialogInput;
use App\Application\Dialogs\DialogStep;
use App\Application\Dialogs\DialogTransition;
use App\Domain\Services\TaskService;
use App\Support\PriorityParser;
use App\Support\TagParser;

/**
 * Добавление гибкой задачи: название -> дата -> оценка (мин) -> приоритет -> теги.
 * Оценку и приоритет можно пропустить («-»). Дата из контекста пропускает свой шаг.
 */
final class AddFlexibleTaskDialog extends AbstractDialogHandler
{
    public function __construct(private readonly TaskService $tasks)
    {
    }

    public function flow(): DialogFlow
    {
        return DialogFlow::AddFlexible;
    }

    public function begin(array $context): DialogTransition
    {
        return DialogTransition::prompt(
            $this->step($this->flow(), 'title'),
            $context,
            'Новая гибкая задача. Введите название:',
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
                ? DialogTransition::prompt($this->step($this->flow(), 'minutes'), $payload, 'Оценка в минутах (например, 30) или «-» без оценки:')
                : DialogTransition::prompt($this->step($this->flow(), 'date'), $payload, 'На какую дату? (сегодня / завтра / +N / ДД.ММ.ГГГГ)');
        }

        if ($step->is('date')) {
            $date = self::parseDate($payload, $text);
            if ($date === null) {
                return DialogTransition::prompt($step, $payload, '❌ Не удалось разобрать дату (сегодня / завтра / +N / ДД.ММ.ГГГГ). Попробуйте ещё раз:');
            }
            $payload['date'] = $date;

            return DialogTransition::prompt($this->step($this->flow(), 'minutes'), $payload, 'Оценка в минутах (например, 30) или «-» без оценки:');
        }

        if ($step->is('minutes')) {
            if (self::isSkip($text)) {
                $payload['minutes'] = null;
            } elseif (preg_match('/^\d{1,4}$/', trim($text))) {
                $payload['minutes'] = (int) trim($text);
            } else {
                return DialogTransition::prompt($step, $payload, '❌ Оценка — число минут (например, 30) или «-». Попробуйте ещё раз:');
            }

            return DialogTransition::prompt($this->step($this->flow(), 'priority'), $payload, 'Приоритет: низкий / средний / высокий или «-» без приоритета:');
        }

        if ($step->is('priority')) {
            if (self::isSkip($text)) {
                $payload['priority'] = 0;
            } elseif (PriorityParser::parse($text) !== null) {
                $payload['priority'] = PriorityParser::toInt($text);
            } else {
                return DialogTransition::prompt($step, $payload, '❌ Приоритет: низкий / средний / высокий или «-». Попробуйте ещё раз:');
            }

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
        $task = $this->tasks->addFlexibleTask(
            $chatId,
            (string) $payload['date'],
            (string) $payload['title'],
            $payload['minutes'] === null ? null : (int) $payload['minutes'],
            (int) ($payload['priority'] ?? 0),
            (array) ($payload['tags'] ?? []),
        );

        $short = mb_substr($task->id, 0, 8);
        $est = $task->estimatedMinutes === null ? 'без оценки' : $task->estimatedMinutes . ' мин';

        return "✅ Задача добавлена: {$task->date} «{$task->title}» ({$est}; id: {$short})";
    }
}
