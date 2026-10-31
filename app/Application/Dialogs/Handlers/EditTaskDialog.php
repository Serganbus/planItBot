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
use App\Support\TimeParser;

/**
 * Редактирование задачи (одно поле за проход): поле -> новое значение.
 * Контекст обязан содержать task_id (полный uuid).
 */
final class EditTaskDialog extends AbstractDialogHandler
{
    private const FIELD_MAP = [
        'название' => 'title', 'title' => 'title',
        'дата' => 'date', 'date' => 'date',
        'время' => 'time', 'time' => 'time',
        'оценка' => 'estimate', 'минуты' => 'estimate', 'мин' => 'estimate', 'minutes' => 'estimate', 'estimate' => 'estimate',
        'приоритет' => 'priority', 'priority' => 'priority',
        'теги' => 'tags', 'tags' => 'tags',
    ];

    public function __construct(private readonly TaskService $tasks)
    {
    }

    public function flow(): DialogFlow
    {
        return DialogFlow::EditTask;
    }

    public function begin(array $context): DialogTransition
    {
        if (empty($context['task_id'])) {
            return DialogTransition::cancel('❌ Внутренняя ошибка: не задана задача для редактирования.');
        }

        return DialogTransition::prompt(
            $this->step($this->flow(), 'field'),
            $context,
            'Какое поле изменить? Введите: название, дата, время, оценка, приоритет или теги:',
        );
    }

    public function transition(DialogStep $step, array $payload, DialogInput $input): DialogTransition
    {
        $text = (string) $input->text;

        if ($step->is('field')) {
            $field = self::FIELD_MAP[mb_strtolower(trim($text))] ?? null;
            if ($field === null) {
                return DialogTransition::prompt($step, $payload, '❌ Понимаю поля: название, дата, время, оценка, приоритет, теги. Попробуйте ещё раз:');
            }
            $payload['field'] = $field;

            return DialogTransition::prompt($this->step($this->flow(), 'value'), $payload, 'Введите новое значение' . self::hint($field) . ':');
        }

        if ($step->is('value')) {
            $field = (string) ($payload['field'] ?? '');
            $parsed = $this->parseValue($field, $payload, $text);

            if ($parsed instanceof DialogTransition) {
                return $parsed; // невалидный ввод — повторный вопрос
            }

            $payload['value'] = $parsed;

            return DialogTransition::complete($payload);
        }

        return DialogTransition::cancel('❌ Неизвестный шаг диалога. Диалог прерван, начните заново.');
    }

    public function complete(int $chatId, array $payload): string
    {
        $field = (string) $payload['field'];
        $changes = match ($field) {
            'estimate' => ['estimated_minutes' => $payload['value']],
            default => [$field => $payload['value']],
        };

        $task = $this->tasks->editTask((string) $payload['task_id'], $chatId, $changes);

        return "✏️ Задача обновлена: «{$task->title}» ({$task->date})";
    }

    private static function hint(string $field): string
    {
        return match ($field) {
            'date' => ' (сегодня / завтра / +N / ДД.ММ.ГГГГ)',
            'time' => ' (ЧЧ:ММ)',
            'estimate' => ' (число минут или «-» без оценки)',
            'priority' => ' (низкий / средний / высокий или «-»)',
            'tags' => ' (через пробел, #работа #дом, или «-» без тегов)',
            default => '',
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return mixed|DialogTransition распарсенное значение или повторный вопрос
     */
    private function parseValue(string $field, array $payload, string $text): mixed
    {
        return match ($field) {
            'title' => trim($text) !== ''
                ? trim($text)
                : DialogTransition::prompt(new DialogStep($this->flow(), 'value'), $payload, '❌ Название не может быть пустым. Попробуйте ещё раз:'),

            'date' => self::parseDate($payload, $text)
                ?? DialogTransition::prompt(new DialogStep($this->flow(), 'value'), $payload, '❌ Не удалось разобрать дату. Попробуйте ещё раз:'),

            'time' => TimeParser::parse($text)
                ?? DialogTransition::prompt(new DialogStep($this->flow(), 'value'), $payload, '❌ Формат времени ЧЧ:ММ. Попробуйте ещё раз:'),

            'estimate' => $this->parseEstimateValue($payload, $text),

            'priority' => $this->parsePriorityValue($payload, $text),

            'tags' => self::isSkip($text) ? [] : TagParser::parseMany($text),

            default => DialogTransition::cancel('❌ Неизвестное поле. Диалог прерван, начните заново.'),
        };
    }

    /** @param array<string, mixed> $payload */
    private function parseEstimateValue(array $payload, string $text): mixed
    {
        if (self::isSkip($text)) {
            return null;
        }
        if (preg_match('/^\d{1,4}$/', trim($text))) {
            return (int) trim($text);
        }

        return DialogTransition::prompt(new DialogStep($this->flow(), 'value'), $payload, '❌ Оценка — число минут или «-». Попробуйте ещё раз:');
    }

    /** @param array<string, mixed> $payload */
    private function parsePriorityValue(array $payload, string $text): mixed
    {
        if (self::isSkip($text)) {
            return 0;
        }
        if (PriorityParser::parse($text) !== null) {
            return PriorityParser::toInt($text);
        }

        return DialogTransition::prompt(new DialogStep($this->flow(), 'value'), $payload, '❌ Приоритет: низкий / средний / высокий или «-». Попробуйте ещё раз:');
    }
}
