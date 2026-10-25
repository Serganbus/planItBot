<?php

declare(strict_types=1);

namespace PlannerBot\Flow;

use Planner\Domain\Model\Day;
use PlannerBot\Infrastructure\ChatContext;
use PlannerBot\Keyboard\KeyboardFactory;
use PlannerBot\Service\EditService;
use PlannerBot\Service\GoalService;
use PlannerBot\Util\DateParser;
use PlannerBot\Util\IdResolver;
use PlannerBot\Util\PriorityParser;
use PlannerBot\Util\TagParser;
use PlannerBot\Util\TimeParser;
use TelegramBot\Api\Types\Inline\InlineKeyboardMarkup;

/**
 * Многошаговые диалоги. Каждый диалог получает текст пользователя,
 * обновляет состояние и возвращает ответ (текст + опциональная клавиатура).
 *
 * Контракт результата: ['text' => string, 'reply_markup' => ?InlineKeyboardMarkup].
 */
final class FlowManager
{
    public const FLOW_ADD_ITEM = 'add_item';
    public const FLOW_ADD_TASK = 'add_task';
    public const FLOW_SET_GOAL = 'set_goal';
    public const FLOW_EDIT_ITEM = 'edit_item';
    public const FLOW_EDIT_TASK = 'edit_task';

    public const STEP_START = 'start';
    public const STEP_END = 'end';
    public const STEP_TITLE = 'title';
    public const STEP_MINUTES = 'minutes';
    public const STEP_PRIORITY = 'priority';
    public const STEP_TAGS = 'tags';
    public const STEP_GOAL = 'goal';
    public const STEP_FIELD = 'field';
    public const STEP_VALUE = 'value';

    public function __construct(
        private readonly DateParser $dateParser,
        private readonly GoalService $goalService,
        private readonly EditService $editService = new EditService(),
    ) {
    }

    /**
     * @return array{text:string,reply_markup:?InlineKeyboardMarkup}|null
     */
    public function handle(ChatContext $chat, string $flow, string $step, array $payload, string $text): ?array
    {
        return match ($flow) {
            self::FLOW_ADD_ITEM => $this->addItem($chat, $step, $payload, $text),
            self::FLOW_ADD_TASK => $this->addTask($chat, $step, $payload, $text),
            self::FLOW_SET_GOAL => $this->setGoal($chat, $step, $payload, $text),
            self::FLOW_EDIT_ITEM => $this->editItem($chat, $step, $payload, $text),
            self::FLOW_EDIT_TASK => $this->editTask($chat, $step, $payload, $text),
            default => null,
        };
    }

    /**
     * Подсказка после старта диалога.
     *
     * @return array{text:string,reply_markup:?InlineKeyboardMarkup}|null
     */
    public function prompt(string $flow, string $step, array $payload): ?array
    {
        return match ([$flow, $step]) {
            [self::FLOW_ADD_ITEM, self::STEP_START] => $this->reply('Введите <b>время начала</b> (например, 09:00):'),
            [self::FLOW_ADD_ITEM, self::STEP_END] => $this->reply('Введите <b>время окончания</b> (например, 10:30):'),
            [self::FLOW_ADD_ITEM, self::STEP_TITLE] => $this->reply('Введите <b>название</b> пункта расписания:'),
            [self::FLOW_ADD_ITEM, self::STEP_TAGS] => $this->reply('Введите <b>теги</b> через пробел (#работа #дом) или «-», если без тегов:'),
            [self::FLOW_ADD_TASK, self::STEP_TITLE] => $this->reply('Введите <b>название</b> задачи:'),
            [self::FLOW_ADD_TASK, self::STEP_MINUTES] => $this->reply('Введите <b>оценку в минутах</b> (например, 30):'),
            [self::FLOW_ADD_TASK, self::STEP_PRIORITY] => $this->reply("Введите <b>приоритет</b>: низкий / средний / высокий (low/medium/high):"),
            [self::FLOW_ADD_TASK, self::STEP_TAGS] => $this->reply('Введите <b>теги</b> через пробел (#работа #дом) или «-», если без тегов:'),
            [self::FLOW_SET_GOAL, self::STEP_GOAL] => $this->reply('Введите <b>главную цель дня</b> одним сообщением:'),
            [self::FLOW_EDIT_ITEM, self::STEP_FIELD] => $this->reply(
                'Какое поле изменить? Введите: <b>начало</b>, <b>конец</b>, <b>название</b> или <b>теги</b>:'
            ),
            [self::FLOW_EDIT_TASK, self::STEP_FIELD] => $this->reply(
                'Какое поле изменить? Введите: <b>название</b>, <b>минуты</b>, <b>приоритет</b> или <b>теги</b>:'
            ),
            [self::FLOW_EDIT_ITEM, self::STEP_VALUE], [self::FLOW_EDIT_TASK, self::STEP_VALUE] => $this->reply(
                'Введите новое значение:'
            ),
            default => null,
        };
    }

    // ── add item ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload
     * @return array{reply_markup: ?InlineKeyboardMarkup}|null
     */
    private function addItem(ChatContext $chat, string $step, array $payload, string $text): ?array
    {
        $date = (string) ($payload['date'] ?? $this->dateParser->today());

        switch ($step) {
            case self::STEP_START:
                $start = TimeParser::parse($text);
                if ($start === null) {
                    return $this->reply('❌ Не удалось разобрать время. Попробуйте ещё раз (формат HH:MM):');
                }
                $chat->state->startFlow(self::FLOW_ADD_ITEM, self::STEP_END, ['date' => $date, 'start' => $start]);

                return $this->reply('Введите <b>время окончания</b> (например, 10:30):');

            case self::STEP_END:
                $end = TimeParser::parse($text);
                if ($end === null) {
                    return $this->reply('❌ Не удалось разобрать время. Попробуйте ещё раз (формат HH:MM):');
                }
                if (!TimeParser::isValidRange((string) $payload['start'], $end)) {
                    return $this->reply('❌ Время окончания должно быть позже начала. Введите ещё раз:');
                }
                $chat->state->startFlow(self::FLOW_ADD_ITEM, self::STEP_TITLE, [
                    'date' => $date, 'start' => $payload['start'], 'end' => $end,
                ]);

                return $this->reply('Введите <b>название</b> пункта расписания:');

            case self::STEP_TITLE:
                $title = trim($text);
                if ($title === '') {
                    return $this->reply('❌ Название не может быть пустым. Попробуйте ещё раз:');
                }
                $chat->state->startFlow(self::FLOW_ADD_ITEM, self::STEP_TAGS, [
                    'date' => $date,
                    'start' => $payload['start'],
                    'end' => $payload['end'],
                    'title' => $title,
                ]);

                return $this->reply('Введите <b>теги</b> через пробел (#работа #дом) или «-», если без тегов:');

            case self::STEP_TAGS:
                $tags = $text === '-' ? [] : TagParser::parseMany($text);
                $item = $chat->planner->addScheduleItem(
                    $date,
                    (string) $payload['start'],
                    (string) $payload['end'],
                    (string) $payload['title'],
                    $tags,
                );
                $chat->state->clearFlow();
                $chat->state->setDay($date);

                return $this->reply(
                    '✅ Добавлено: <code>' . htmlspecialchars((string) $payload['start'] . '–' . $payload['end']) . '</code> '
                    . htmlspecialchars((string) $payload['title']) . ' <i>(id: ' . substr($item->id, 0, 8) . ')</i>',
                    KeyboardFactory::backToDay($date),
                );
        }

        return null;
    }

    // ── add task ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload
     * @return array{reply_markup: ?InlineKeyboardMarkup}|null
     */
    private function addTask(ChatContext $chat, string $step, array $payload, string $text): ?array
    {
        $date = (string) ($payload['date'] ?? $this->dateParser->today());

        switch ($step) {
            case self::STEP_TITLE:
                $title = trim($text);
                if ($title === '') {
                    return $this->reply('❌ Название не может быть пустым. Попробуйте ещё раз:');
                }
                $chat->state->startFlow(self::FLOW_ADD_TASK, self::STEP_MINUTES, [
                    'date' => $date, 'title' => $title,
                ]);

                return $this->reply('Введите <b>оценку в минутах</b> (например, 30):');

            case self::STEP_MINUTES:
                $minutes = preg_match('/^\d{1,4}$/', trim($text)) ? (int) trim($text) : null;
                if ($minutes === null) {
                    return $this->reply('❌ Оценка должна быть числом минут (например, 30). Попробуйте ещё раз:');
                }
                $chat->state->startFlow(self::FLOW_ADD_TASK, self::STEP_PRIORITY, [
                    'date' => $date, 'title' => $payload['title'], 'minutes' => $minutes,
                ]);

                return $this->reply("Введите <b>приоритет</b>: низкий / средний / высокий (low/medium/high):");

            case self::STEP_PRIORITY:
                $priority = PriorityParser::parse($text);
                if ($priority === null) {
                    return $this->reply('❌ Приоритет: низкий / средний / высокий (low/medium/high). Попробуйте ещё раз:');
                }
                $chat->state->startFlow(self::FLOW_ADD_TASK, self::STEP_TAGS, [
                    'date' => $date,
                    'title' => $payload['title'],
                    'minutes' => $payload['minutes'],
                    'priority' => $priority,
                ]);

                return $this->reply('Введите <b>теги</b> через пробел (#работа #дом) или «-», если без тегов:');

            case self::STEP_TAGS:
                $tags = $text === '-' ? [] : TagParser::parseMany($text);
                $task = $chat->planner->addFlexibleTask(
                    $date,
                    (string) $payload['title'],
                    (int) $payload['minutes'],
                    (string) $payload['priority'],
                    $tags,
                );
                $chat->state->clearFlow();
                $chat->state->setDay($date);

                return $this->reply(
                    '✅ Задача добавлена: ' . htmlspecialchars((string) $payload['title'])
                    . ' <i>('. htmlspecialchars((string) $payload['minutes']) . ' мин, '
                    . PriorityParser::label((string) $payload['priority']) . ', id: ' . substr($task->id, 0, 8) . ')</i>',
                    KeyboardFactory::backToDay($date),
                );
        }

        return null;
    }

    // ── set goal ─────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload
     * @return array{reply_markup: ?InlineKeyboardMarkup}|null
     */
    private function setGoal(ChatContext $chat, string $step, array $payload, string $text): ?array
    {
        if ($step !== self::STEP_GOAL) {
            return null;
        }

        $goal = trim($text);
        if ($goal === '') {
            return $this->reply('❌ Цель не может быть пустой. Попробуйте ещё раз:');
        }

        $date = (string) ($payload['date'] ?? $this->dateParser->today());
        $this->goalService->setGoal($chat, $date, $goal);
        $chat->state->clearFlow();
        $chat->state->setDay($date);

        return $this->reply('🎯 Цель дня <b>' . htmlspecialchars($date) . '</b> установлена: ' . htmlspecialchars($goal),
            KeyboardFactory::backToDay($date));
    }

    // ── edit item ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload
     * @return array{reply_markup: ?InlineKeyboardMarkup}|null
     */
    private function editItem(ChatContext $chat, string $step, array $payload, string $text): ?array
    {
        $date = (string) ($payload['date'] ?? $this->dateParser->today());
        $idPrefix = (string) ($payload['id'] ?? '');
        $item = IdResolver::resolveScheduleItem($chat->planner->getDay($date) ?? new Day($date), $idPrefix);
        if ($item === null) {
            $chat->state->clearFlow();

            return $this->reply('❌ Элемент не найден (возможно, был удалён).');
        }

        if ($step === self::STEP_FIELD) {
            $field = $this->resolveEditItemField($text);
            if ($field === null) {
                return $this->reply('❌ Понимаю поля: начало, конец, название, теги. Попробуйте ещё раз:');
            }
            $chat->state->startFlow(self::FLOW_EDIT_ITEM, self::STEP_VALUE, [
                'date' => $date, 'id' => $idPrefix, 'field' => $field,
            ]);

            return $this->prompt(self::FLOW_EDIT_ITEM, self::STEP_VALUE, []);
        }

        // STEP_VALUE
        $field = (string) ($payload['field'] ?? '');

        $startTime = $endTime = $title = null;
        $tags = null;

        switch ($field) {
            case 'start':
                $startTime = TimeParser::parse($text);
                if ($startTime === null) {
                    return $this->reply('❌ Не удалось разобрать время. Формат HH:MM, попробуйте ещё раз:');
                }
                if (!TimeParser::isValidRange($startTime, (string) $item->endTime)) {
                    return $this->reply('❌ Новое время начала должно быть раньше конца (' . htmlspecialchars($item->endTime) . '). Попробуйте ещё раз:');
                }
                break;
            case 'end':
                $endTime = TimeParser::parse($text);
                if ($endTime === null) {
                    return $this->reply('❌ Не удалось разобрать время. Формат HH:MM, попробуйте ещё раз:');
                }
                if (!TimeParser::isValidRange((string) $item->startTime, $endTime)) {
                    return $this->reply('❌ Новое время окончания должно быть позже начала (' . htmlspecialchars($item->startTime) . '). Попробуйте ещё раз:');
                }
                break;
            case 'title':
                $title = trim($text);
                if ($title === '') {
                    return $this->reply('❌ Название не может быть пустым. Попробуйте ещё раз:');
                }
                break;
            case 'tags':
                $tags = $text === '-' ? [] : TagParser::parseMany($text);
                break;
            default:
                $chat->state->clearFlow();

                return $this->reply('❌ Неизвестное поле. Диалог прерван, начните заново через /edit.');
        }

        $this->editService->updateScheduleItem($chat, $date, $item->id, $startTime, $endTime, $title, $tags);
        $chat->state->clearFlow();
        $chat->state->setDay($date);

        return $this->reply('✏️ Элемент обновлён.', KeyboardFactory::backToDay($date));
    }

    // ── edit task ────────────────────────────────────────────────────────────

    /**
     * @param array<string,mixed> $payload
     * @return array{reply_markup: ?InlineKeyboardMarkup}|null
     */
    private function editTask(ChatContext $chat, string $step, array $payload, string $text): ?array
    {
        $date = (string) ($payload['date'] ?? $this->dateParser->today());
        $idPrefix = (string) ($payload['id'] ?? '');
        $task = IdResolver::resolveFlexibleTask($chat->planner->getDay($date) ?? new Day($date), $idPrefix);
        if ($task === null) {
            $chat->state->clearFlow();

            return $this->reply('❌ Задача не найдена (возможно, была удалена).');
        }

        if ($step === self::STEP_FIELD) {
            $field = $this->resolveEditTaskField($text);
            if ($field === null) {
                return $this->reply('❌ Понимаю поля: название, минуты, приоритет, теги. Попробуйте ещё раз:');
            }
            $chat->state->startFlow(self::FLOW_EDIT_TASK, self::STEP_VALUE, [
                'date' => $date, 'id' => $idPrefix, 'field' => $field,
            ]);

            return $this->prompt(self::FLOW_EDIT_TASK, self::STEP_VALUE, []);
        }

        // STEP_VALUE
        $field = (string) ($payload['field'] ?? '');

        $title = null;
        $minutes = null;
        $priority = null;
        $tags = null;

        switch ($field) {
            case 'title':
                $title = trim($text);
                if ($title === '') {
                    return $this->reply('❌ Название не может быть пустым. Попробуйте ещё раз:');
                }
                break;
            case 'minutes':
                $minutes = preg_match('/^\d{1,4}$/', trim($text)) ? (int) trim($text) : null;
                if ($minutes === null) {
                    return $this->reply('❌ Оценка должна быть числом минут. Попробуйте ещё раз:');
                }
                break;
            case 'priority':
                $priority = PriorityParser::parse($text);
                if ($priority === null) {
                    return $this->reply('❌ Приоритет: низкий / средний / высокий (low/medium/high). Попробуйте ещё раз:');
                }
                break;
            case 'tags':
                $tags = $text === '-' ? [] : TagParser::parseMany($text);
                break;
            default:
                $chat->state->clearFlow();

                return $this->reply('❌ Неизвестное поле. Диалог прерван, начните заново через /edit.');
        }

        $this->editService->updateFlexibleTask($chat, $date, $task->id, $title, $minutes, $priority, $tags);
        $chat->state->clearFlow();
        $chat->state->setDay($date);

        return $this->reply('✏️ Задача обновлена.', KeyboardFactory::backToDay($date));
    }

    private function resolveEditItemField(string $text): ?string
    {
        return match (mb_strtolower(trim($text))) {
            'начало', 'start', 'время начала' => 'start',
            'конец', 'end', 'время окончания' => 'end',
            'название', 'title' => 'title',
            'теги', 'tags' => 'tags',
            default => null,
        };
    }

    private function resolveEditTaskField(string $text): ?string
    {
        return match (mb_strtolower(trim($text))) {
            'название', 'title' => 'title',
            'минуты', 'minutes', 'мин', 'время' => 'minutes',
            'приоритет', 'priority' => 'priority',
            'теги', 'tags' => 'tags',
            default => null,
        };
    }

    /**
     * @return array{text:string,reply_markup:?InlineKeyboardMarkup}
     */
    private function reply(string $text, ?InlineKeyboardMarkup $keyboard = null): array
    {
        return ['text' => $text, 'reply_markup' => $keyboard];
    }
}
