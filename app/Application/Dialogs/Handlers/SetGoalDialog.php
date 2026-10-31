<?php

declare(strict_types=1);

namespace App\Application\Dialogs\Handlers;

use App\Application\Dialogs\DialogFlow;
use App\Application\Dialogs\DialogInput;
use App\Application\Dialogs\DialogStep;
use App\Application\Dialogs\DialogTransition;
use App\Domain\Services\GoalService;

/**
 * Установка главной цели дня: дата -> формулировка цели.
 * Дата из контекста пропускает свой шаг.
 */
final class SetGoalDialog extends AbstractDialogHandler
{
    public function __construct(private readonly GoalService $goals)
    {
    }

    public function flow(): DialogFlow
    {
        return DialogFlow::SetGoal;
    }

    public function begin(array $context): DialogTransition
    {
        return isset($context['date'])
            ? DialogTransition::prompt(
                $this->step($this->flow(), 'goal'),
                $context,
                "Сформулируйте главную цель на {$context['date']}:",
            )
            : DialogTransition::prompt(
                $this->step($this->flow(), 'date'),
                $context,
                'На какую дату ставим цель? (сегодня / завтра / +N / ДД.ММ.ГГГГ)',
            );
    }

    public function transition(DialogStep $step, array $payload, DialogInput $input): DialogTransition
    {
        $text = (string) $input->text;

        if ($step->is('date')) {
            $date = self::parseDate($payload, $text);
            if ($date === null) {
                return DialogTransition::prompt($step, $payload, '❌ Не удалось разобрать дату (сегодня / завтра / +N / ДД.ММ.ГГГГ). Попробуйте ещё раз:');
            }
            $payload['date'] = $date;

            return DialogTransition::prompt($this->step($this->flow(), 'goal'), $payload, "Сформулируйте главную цель на {$date}:");
        }

        if ($step->is('goal')) {
            $goal = trim($text);
            if ($goal === '') {
                return DialogTransition::prompt($step, $payload, '❌ Цель не может быть пустой. Попробуйте ещё раз:');
            }
            $payload['goal'] = $goal;

            return DialogTransition::complete($payload);
        }

        return DialogTransition::cancel('❌ Неизвестный шаг диалога. Диалог прерван, начните заново.');
    }

    public function complete(int $chatId, array $payload): string
    {
        $this->goals->setGoal($chatId, (string) $payload['date'], (string) $payload['goal']);

        return "🎯 Цель на {$payload['date']} сохранена: {$payload['goal']}";
    }
}
