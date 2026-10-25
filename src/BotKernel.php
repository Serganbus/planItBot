<?php

declare(strict_types=1);

namespace PlannerBot;

use Planner\Domain\Model\Day;
use PlannerBot\Callback\CallbackRouter;
use PlannerBot\Flow\FlowManager;
use PlannerBot\Infrastructure\ChatContext;
use PlannerBot\Infrastructure\ChatContextFactory;
use PlannerBot\Keyboard\KeyboardFactory;
use PlannerBot\Service\GoalService;
use PlannerBot\Util\DateParser;
use PlannerBot\Util\IdResolver;
use PlannerBot\Util\PriorityParser;
use PlannerBot\Util\QuickCommandParser;
use PlannerBot\Util\TagParser;
use PlannerBot\Util\TimeParser;
use PlannerBot\View\DayFormatter;
use PlannerBot\View\StatsFormatter;
use TelegramBot\Api\Client;
use TelegramBot\Api\Types\CallbackQuery;
use TelegramBot\Api\Types\Message;
use TelegramBot\Api\Types\Update;

/**
 * Ядро бота: регистрация обработчиков и цикл long polling.
 */
final class BotKernel
{
    private readonly DateParser $dates;
    private readonly GoalService $goals;
    private readonly DayFormatter $dayFormatter;
    private readonly StatsFormatter $statsFormatter;
    private readonly FlowManager $flows;

    public function __construct(
        private readonly Client $bot,
        private readonly ChatContextFactory $chats,
        string $timezone,
        private readonly int $pollTimeout = 30,
    ) {
        $tz = new \DateTimeZone($timezone);
        $this->dates = new DateParser($tz);
        $this->goals = new GoalService();
        $this->dayFormatter = new DayFormatter($tz);
        $this->statsFormatter = new StatsFormatter($this->dayFormatter);
        $this->flows = new FlowManager($this->dates, $this->goals);
    }

    public function run(): void
    {
        $this->registerHandlers();

        $this->log('Planner bot запущен (long polling, timeout=' . $this->pollTimeout . 's)');

        // Сбрасываем бэклог обновлений, накопившийся пока бот был выключен.
        // Первая же ошибка (некорректный токен, нет сети) — повод завершить работу сразу.
        try {
            $updates = $this->bot->getUpdates(-1);
        } catch (\Throwable $e) {
            throw new \RuntimeException(
                'Не удалось подключиться к Telegram API. Проверьте BOT_TOKEN и сеть. Причина: ' . $e->getMessage(),
                0,
                $e,
            );
        }
        $offset = 0;
        foreach ($updates as $update) {
            $offset = max($offset, $update->getUpdateId() + 1);
        }

        while (true) {
            try {
                $updates = $this->bot->getUpdates($offset, 100, $this->pollTimeout);
            } catch (\Throwable $e) {
                $this->log('Ошибка getUpdates: ' . $e->getMessage() . ' — повтор через 3 сек');
                sleep(3);
                continue;
            }

            foreach ($updates as $update) {
                $offset = max($offset, $update->getUpdateId() + 1);
                try {
                    $this->bot->handle([$update]);
                } catch (\Throwable $e) {
                    $this->log('Ошибка обработки update #' . $update->getUpdateId() . ': ' . $e->getMessage());
                    $chatId = $this->extractChatId($update);
                    if ($chatId !== null) {
                        $this->safeReply($chatId, '⚠️ Произошла внутренняя ошибка, попробуйте ещё раз.');
                    }
                }
            }
        }
    }

    private function registerHandlers(): void
    {
        $bot = $this->bot;

        $bot->command('start', fn(Message $m) => $this->cmdStart($m));
        $bot->command('help', fn(Message $m) => $this->cmdHelp($m));
        $bot->command('today', fn(Message $m) => $this->cmdDay($m, $this->dates->today(), true));
        $bot->command('day', fn(Message $m) => $this->cmdDay($m, $this->args($m)));
        $bot->command('add', fn(Message $m) => $this->cmdAdd($m, $this->args($m)));
        $bot->command('task', fn(Message $m) => $this->cmdTask($m, $this->args($m)));
        $bot->command('goal', fn(Message $m) => $this->cmdGoal($m, $this->args($m)));
        $bot->command('done', fn(Message $m) => $this->cmdDone($m));
        $bot->command('edit', fn(Message $m) => $this->cmdEdit($m));
        $bot->command('remove', fn(Message $m) => $this->cmdRemove($m));
        $bot->command('tags', fn(Message $m) => $this->cmdTags($m));
        $bot->command('tag', fn(Message $m) => $this->cmdTag($m, $this->args($m)));
        $bot->command('stats', fn(Message $m) => $this->cmdStats($m));
        $bot->command('cancel', fn(Message $m) => $this->cmdCancel($m));

        $bot->callbackQuery(fn(CallbackQuery $q) => $this->onCallback($q));

        // Всё остальное текстовое — в активный диалог или подсказка.
        $bot->on(
            function (Update $update) {
                $message = $update->getMessage();
                if ($message === null) {
                    return;
                }
                $this->onPlainText($message, (string) $message->getText());
            },
            fn(Update $update): bool => $update->getMessage() !== null
                && !str_starts_with((string) $update->getMessage()->getText(), '/')
        );

        // Незнакомые slash-команды.
        $bot->on(
            function (Update $update) {
                $message = $update->getMessage();
                if ($message === null) {
                    return;
                }
                $this->bot->sendMessage(
                    $message->getChat()->getId(),
                    'Неизвестная команда. /help — список команд.',
                    'HTML',
                    true,
                );
            },
            fn(Update $update): bool => $update->getMessage() !== null
                && str_starts_with((string) $update->getMessage()->getText(), '/')
        );
    }

    // ── Команды ───────────────────────────────────────────────────────────────

    private function cmdStart(Message $message): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $chat->state->clearFlow();
        $chat->state->setDay($this->dates->today());

        $this->bot->sendMessage(
            $chat->chatId,
            "👋 Привет! Я бот-планировщик.\n\n"
            . "Помогаю вести расписание дня и список задач.\n\n"
            . 'Основные команды:' . "\n"
            . "/today — день на сегодня\n"
            . "/add — пункт расписания (время + название)\n"
            . "/task — задача с оценкой и приоритетом\n"
            . "/goal — главная цель дня\n"
            . "/done — отметить выполненное\n"
            . "/stats — статистика дня\n"
            . "/help — полная справка",
            'HTML',
            true,
            null,
            KeyboardFactory::dayNav($this->dates->today(), $this->dates->today()),
        );
    }

    private function cmdHelp(Message $message): void
    {
        $this->bot->sendMessage(
            $message->getChat()->getId(),
            "<b>📖 Справка</b>\n\n"
            . "<b>Просмотр:</b>\n"
            . "/today — сегодня\n"
            . "/day [дата] — день на дату (YYYY-MM-DD, сегодня, завтра, вчера, +N)\n"
            . "/stats — статистика текущего дня\n"
            . "/tags — теги текущего дня\n"
            . "/tag #имя — записи с тегом\n\n"
            . "<b>Добавление:</b>\n"
            . "/add — диалог или быстро: <code>/add 09:00 10:30 Стендап #работа</code>\n"
            . "/task — диалог или быстро: <code>/task Отчёт 60 high #работа</code>\n"
            . "/goal [текст] — главная цель дня (показать/установить)\n\n"
            . "<b>Управление:</b>\n"
            . "/done — отметить выполненное / вернуть\n"
            . "/edit — изменить запись\n"
            . "/remove — удалить запись\n"
            . "/cancel — прервать диалог",
            'HTML',
            true,
        );
    }

    private function cmdDay(Message $message, string $dateArg, bool $silent = false): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $dateArg = trim($dateArg);

        if ($dateArg === '') {
            $date = $this->dates->today();
        } else {
            $date = $this->dates->parse($dateArg);
            if ($date === null) {
                $this->bot->sendMessage(
                    $chat->chatId,
                    '❌ Не удалось разобрать дату «' . DayFormatter::e($dateArg) . '». Форматы: YYYY-MM-DD, ДД.ММ.ГГГГ, сегодня, завтра, вчера, +N.',
                    'HTML',
                );

                return;
            }
        }

        $chat->state->setDay($date);
        $this->sendDay($chat->chatId, $date);
    }

    private function cmdAdd(Message $message, string $args): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $date = $this->currentDate($chat);

        if ($args === '') {
            $chat->state->startFlow(FlowManager::FLOW_ADD_ITEM, FlowManager::STEP_START, ['date' => $date]);
            $prompt = $this->flows->prompt(FlowManager::FLOW_ADD_ITEM, FlowManager::STEP_START, ['date' => $date]);
            $this->bot->sendMessage($chat->chatId, $prompt['text'], 'HTML');

            return;
        }

        $parsed = QuickCommandParser::parseScheduleItem($args);

        if ($parsed['start'] === null || $parsed['end'] === null || $parsed['title'] === '') {
            $this->bot->sendMessage(
                $chat->chatId,
                '❌ Формат: <code>/add 09:00 10:30 Название #тег</code> — нужны время начала, время окончания и название. Или просто /add для пошагового диалога.',
                'HTML',
            );

            return;
        }

        if (!TimeParser::isValidRange($parsed['start'], $parsed['end'])) {
            $this->bot->sendMessage($chat->chatId, '❌ Время окончания должно быть позже начала.', 'HTML');

            return;
        }

        $item = $chat->planner->addScheduleItem($date, $parsed['start'], $parsed['end'], $parsed['title'], $parsed['tags']);

        $this->bot->sendMessage(
            $chat->chatId,
            '✅ Добавлено: <code>' . DayFormatter::e($parsed['start'] . '–' . $parsed['end']) . '</code> '
            . DayFormatter::e($parsed['title'])
            . ($parsed['tags'] ? ' ' . DayFormatter::e(TagParser::format($parsed['tags'])) : ''),
            'HTML',
            true,
            null,
            KeyboardFactory::backToDay($date),
        );
    }

    private function cmdTask(Message $message, string $args): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $date = $this->currentDate($chat);

        if ($args === '') {
            $chat->state->startFlow(FlowManager::FLOW_ADD_TASK, FlowManager::STEP_TITLE, ['date' => $date]);
            $prompt = $this->flows->prompt(FlowManager::FLOW_ADD_TASK, FlowManager::STEP_TITLE, ['date' => $date]);
            $this->bot->sendMessage($chat->chatId, $prompt['text'], 'HTML');

            return;
        }

        $parsed = QuickCommandParser::parseFlexibleTask($args);

        if ($parsed['title'] === '') {
            $this->bot->sendMessage(
                $chat->chatId,
                '❌ Формат: <code>/task Название [минуты] [low|medium|high] #тег</code>. Или просто /task для пошагового диалога.',
                'HTML',
            );

            return;
        }

        $task = $chat->planner->addFlexibleTask(
            $date,
            $parsed['title'],
            $parsed['minutes'] ?? 30,
            $parsed['priority'] ?? 'medium',
            $parsed['tags'],
        );

        $this->bot->sendMessage(
            $chat->chatId,
            '✅ Задача добавлена: ' . DayFormatter::e($task->title)
            . ' <i>[' . PriorityParser::label($task->priority) . ', ' . $task->estimatedMinutes . ' мин]</i>',
            'HTML',
            true,
            null,
            KeyboardFactory::backToDay($date),
        );
    }

    private function cmdGoal(Message $message, string $args): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $date = $this->currentDate($chat);

        if ($args === '') {
            $day = $chat->planner->getDay($date);
            $current = $day?->mainGoal;

            if ($current !== null && $current !== '') {
                $this->bot->sendMessage(
                    $chat->chatId,
                    '🎯 Цель дня <b>' . DayFormatter::e($date) . '</b>: ' . DayFormatter::e($current)
                    . "\nОтправьте /goal новый текст, чтобы изменить.",
                    'HTML',
                );

                return;
            }

            $chat->state->startFlow(FlowManager::FLOW_SET_GOAL, FlowManager::STEP_GOAL, ['date' => $date]);
            $prompt = $this->flows->prompt(FlowManager::FLOW_SET_GOAL, FlowManager::STEP_GOAL, ['date' => $date]);
            $this->bot->sendMessage($chat->chatId, $prompt['text'], 'HTML');

            return;
        }

        $this->goals->setGoal($chat, $date, $args);
        $this->bot->sendMessage(
            $chat->chatId,
            '🎯 Цель дня <b>' . DayFormatter::e($date) . '</b> установлена: ' . DayFormatter::e($args),
            'HTML',
            true,
            null,
            KeyboardFactory::backToDay($date),
        );
    }

    private function cmdDone(Message $message): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $date = $this->currentDate($chat);
        $day = $chat->planner->getDay($date) ?? new Day($date);

        $this->bot->sendMessage(
            $chat->chatId,
            '<b>Отметить выполнение</b> — ' . DayFormatter::e($date) . "\n(✅ — выполнить, ↩ — вернуть в работу)",
            'HTML',
            true,
            null,
            KeyboardFactory::doneList($day, $date),
        );
    }

    private function cmdEdit(Message $message): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $date = $this->currentDate($chat);
        $day = $chat->planner->getDay($date) ?? new Day($date);

        $this->bot->sendMessage(
            $chat->chatId,
            '<b>Что изменить?</b> — ' . DayFormatter::e($date),
            'HTML',
            true,
            null,
            KeyboardFactory::entries($day, $date, 'edit', CallbackRouter::PREFIX_DAY . $date),
        );
    }

    private function cmdRemove(Message $message): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $date = $this->currentDate($chat);
        $day = $chat->planner->getDay($date) ?? new Day($date);

        $this->bot->sendMessage(
            $chat->chatId,
            '<b>Что удалить?</b> — ' . DayFormatter::e($date),
            'HTML',
            true,
            null,
            KeyboardFactory::entries($day, $date, 'del', CallbackRouter::PREFIX_DAY . $date),
        );
    }

    private function cmdTags(Message $message): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $date = $this->currentDate($chat);
        $day = $chat->planner->getDay($date);

        $counts = [];
        if ($day !== null) {
            foreach ($day->getSchedule() as $item) {
                foreach ($item->tags as $tag) {
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            }
            foreach ($day->getFlexibleTasks() as $task) {
                foreach ($task->tags as $tag) {
                    $counts[$tag] = ($counts[$tag] ?? 0) + 1;
                }
            }
        }

        if ($counts === []) {
            $this->bot->sendMessage($chat->chatId, 'На ' . DayFormatter::e($date) . ' тегов нет.', 'HTML');

            return;
        }

        ksort($counts);
        $lines = ['<b>🏷 Теги дня ' . DayFormatter::e($date) . ':</b>'];
        foreach ($counts as $tag => $count) {
            $lines[] = '#' . DayFormatter::e($tag) . ' ×' . $count;
        }
        $lines[] = '';
        $lines[] = 'Фильтр: /tag #имя';

        $this->bot->sendMessage($chat->chatId, implode("\n", $lines), 'HTML');
    }

    private function cmdTag(Message $message, string $args): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $date = $this->currentDate($chat);
        $tag = TagParser::normalize($args);

        if ($tag === '') {
            $this->bot->sendMessage($chat->chatId, '❌ Формат: <code>/tag #работа</code>', 'HTML');

            return;
        }

        $day = $chat->planner->getDay($date);
        $lines = ['<b>#' . DayFormatter::e($tag) . '</b> на ' . DayFormatter::e($date) . ':'];
        $found = 0;

        if ($day !== null) {
            foreach ($day->getSchedule() as $item) {
                if (in_array($tag, $item->tags, true)) {
                    $mark = $item->status === 'done' ? '✅' : '⬜';
                    $lines[] = sprintf('%s <code>%s–%s</code> %s', $mark, $item->startTime, $item->endTime, DayFormatter::e($item->title));
                    $found++;
                }
            }
            foreach ($day->getFlexibleTasks() as $task) {
                if (in_array($tag, $task->tags, true)) {
                    $mark = $task->status === 'done' ? '✅' : '⬜';
                    $lines[] = sprintf('%s %s <i>[%s, %d мин]</i>', $mark, DayFormatter::e($task->title), PriorityParser::label($task->priority), $task->estimatedMinutes);
                    $found++;
                }
            }
        }

        if ($found === 0) {
            $lines[] = '<i>Ничего не найдено.</i>';
        }

        $this->bot->sendMessage($chat->chatId, implode("\n", $lines), 'HTML', true, null, KeyboardFactory::backToDay($date));
    }

    private function cmdStats(Message $message): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $date = $this->currentDate($chat);

        $this->bot->sendMessage(
            $chat->chatId,
            $this->statsFormatter->format($date, $chat->planner->getStats($date)),
            'HTML',
            true,
            null,
            KeyboardFactory::statsNav($date, $this->dates->today()),
        );
    }

    private function cmdCancel(Message $message): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $chat->state->clearFlow();

        $this->bot->sendMessage(
            $chat->chatId,
            'Диалог отменён. /help — список команд.',
            'HTML',
            true,
            null,
            KeyboardFactory::backToDay($this->currentDate($chat)),
        );
    }

    // ── Callback-кнопки ───────────────────────────────────────────────────────

    private function onCallback(CallbackQuery $query): void
    {
        $data = (string) $query->getData();
        $message = $query->getMessage();
        if ($message === null) {
            $this->bot->answerCallbackQuery($query->getId());

            return;
        }

        $chat = $this->chats->forChat($message->getChat()->getId());
        $messageId = $message->getMessageId();

        $parsed = CallbackRouter::parse($data);
        if ($parsed === null) {
            $this->bot->answerCallbackQuery($query->getId(), 'Неизвестная команда кнопки');

            return;
        }

        [$type, $arg] = $parsed;

        switch ($type) {
            case 'day':
                $date = $arg !== '' ? $arg : $this->dates->today();
                $chat->state->setDay($date);
                $this->editDay($chat->chatId, $messageId, $date);
                $this->bot->answerCallbackQuery($query->getId());
                break;

            case 'statday':
                $date = $arg !== '' ? $arg : $this->dates->today();
                $chat->state->setDay($date);
                $this->bot->editMessageText(
                    $chat->chatId,
                    $messageId,
                    $this->statsFormatter->format($date, $chat->planner->getStats($date)),
                    'HTML',
                    true,
                    KeyboardFactory::statsNav($date, $this->dates->today()),
                );
                $this->bot->answerCallbackQuery($query->getId());
                break;

            case 'stats':
                $date = $this->currentDate($chat);
                $this->bot->sendMessage(
                    $chat->chatId,
                    $this->statsFormatter->format($date, $chat->planner->getStats($date)),
                    'HTML',
                    true,
                    null,
                    KeyboardFactory::statsNav($date, $this->dates->today()),
                );
                $this->bot->answerCallbackQuery($query->getId());
                break;

            case 'itemmenu':
                $date = $arg !== '' ? $arg : $this->currentDate($chat);
                $chat->state->startFlow(FlowManager::FLOW_ADD_ITEM, FlowManager::STEP_START, ['date' => $date]);
                $prompt = $this->flows->prompt(FlowManager::FLOW_ADD_ITEM, FlowManager::STEP_START, ['date' => $date]);
                $this->bot->sendMessage($chat->chatId, $prompt['text'], 'HTML');
                $this->bot->answerCallbackQuery($query->getId());
                break;

            case 'taskmenu':
                $date = $arg !== '' ? $arg : $this->currentDate($chat);
                $chat->state->startFlow(FlowManager::FLOW_ADD_TASK, FlowManager::STEP_TITLE, ['date' => $date]);
                $prompt = $this->flows->prompt(FlowManager::FLOW_ADD_TASK, FlowManager::STEP_TITLE, ['date' => $date]);
                $this->bot->sendMessage($chat->chatId, $prompt['text'], 'HTML');
                $this->bot->answerCallbackQuery($query->getId());
                break;

            case 'edit':
                $this->startEditFlow($chat, $query, $arg);
                break;

            case 'done':
            case 'undone':
                $this->toggleDone($chat, $query, $arg, $type === 'done');
                break;

            case 'del':
                $date = $this->currentDate($chat);
                $this->bot->editMessageReplyMarkup(
                    $chat->chatId,
                    $messageId,
                    KeyboardFactory::confirmDelete($arg, CallbackRouter::PREFIX_DAY . $date),
                );
                $this->bot->answerCallbackQuery($query->getId(), 'Подтвердите удаление');
                break;

            case 'delok':
                $this->confirmDelete($chat, $query, $arg);
                break;
        }
    }

    private function startEditFlow(ChatContext $chat, CallbackQuery $query, string $idPrefix): void
    {
        $date = $this->currentDate($chat);
        $day = $chat->planner->getDay($date);
        $resolved = $day !== null ? IdResolver::resolve($day, $idPrefix) : null;

        if ($resolved === null) {
            $this->bot->answerCallbackQuery($query->getId(), 'Запись не найдена', true);

            return;
        }

        [$kind] = $resolved;
        $flow = $kind === 'item' ? FlowManager::FLOW_EDIT_ITEM : FlowManager::FLOW_EDIT_TASK;
        $chat->state->startFlow($flow, FlowManager::STEP_FIELD, ['date' => $date, 'id' => $idPrefix]);
        $prompt = $this->flows->prompt($flow, FlowManager::STEP_FIELD, ['date' => $date, 'id' => $idPrefix]);
        $this->bot->sendMessage($chat->chatId, $prompt['text'], 'HTML');
        $this->bot->answerCallbackQuery($query->getId());
    }

    private function toggleDone(ChatContext $chat, CallbackQuery $query, string $idPrefix, bool $done): void
    {
        $date = $this->currentDate($chat);
        $day = $chat->planner->getDay($date);
        $resolved = $day !== null ? IdResolver::resolve($day, $idPrefix) : null;

        if ($resolved === null) {
            $this->bot->answerCallbackQuery($query->getId(), 'Запись не найдена', true);

            return;
        }

        [$kind, $entity] = $resolved;

        if ($done) {
            $kind === 'item'
                ? $chat->planner->markScheduleItemDone($date, $entity->id)
                : $chat->planner->markFlexibleTaskDone($date, $entity->id);
        } else {
            $kind === 'item'
                ? $chat->planner->markScheduleItemPending($date, $entity->id)
                : $chat->planner->markFlexibleTaskPending($date, $entity->id);
        }

        // Перерисовываем список отметок в том же сообщении.
        $freshDay = $chat->planner->getDay($date) ?? new Day($date);
        $message = $query->getMessage();
        if ($message !== null) {
            $this->bot->editMessageReplyMarkup(
                $chat->chatId,
                $message->getMessageId(),
                KeyboardFactory::doneList($freshDay, $date),
            );
        }

        $this->bot->answerCallbackQuery($query->getId(), $done ? '✅ Выполнено' : '↩ Возвращено в работу');
    }

    private function confirmDelete(ChatContext $chat, CallbackQuery $query, string $idPrefix): void
    {
        $date = $this->currentDate($chat);
        $day = $chat->planner->getDay($date);
        $resolved = $day !== null ? IdResolver::resolve($day, $idPrefix) : null;

        if ($resolved === null) {
            $this->bot->answerCallbackQuery($query->getId(), 'Запись уже удалена', true);

            return;
        }

        [$kind, $entity] = $resolved;

        $kind === 'item'
            ? $chat->planner->removeScheduleItem($date, $entity->id)
            : $chat->planner->removeFlexibleTask($date, $entity->id);

        // Перерисовываем список удаления в том же сообщении.
        $freshDay = $chat->planner->getDay($date) ?? new Day($date);
        $message = $query->getMessage();
        if ($message !== null) {
            $this->bot->editMessageReplyMarkup(
                $chat->chatId,
                $message->getMessageId(),
                KeyboardFactory::entries($freshDay, $date, 'del', CallbackRouter::PREFIX_DAY . $date),
            );
        }

        $this->bot->answerCallbackQuery($query->getId(), '🗑 Удалено');
    }

    // ── Обработка обычного текста (диалоги) ───────────────────────────────────

    private function onPlainText(Message $message, string $text): void
    {
        $chat = $this->chats->forChat($message->getChat()->getId());
        $state = $chat->state->get();

        if ($state['flow'] === null) {
            $this->bot->sendMessage(
                $chat->chatId,
                'Не понимаю 🤔 /help — список команд, /today — план на сегодня.',
                'HTML',
                true,
            );

            return;
        }

        $result = $this->flows->handle($chat, (string) $state['flow'], (string) $state['step'], $state['payload'], $text);

        if ($result === null) {
            $this->bot->sendMessage($chat->chatId, '⚠️ Диалог сброшен. Начните заново: /help', 'HTML');
            $chat->state->clearFlow();

            return;
        }

        $this->bot->sendMessage(
            $chat->chatId,
            $result['text'],
            'HTML',
            true,
            null,
            $result['reply_markup'],
        );
    }

    // ── Вспомогательные методы ────────────────────────────────────────────────

    private function sendDay(int $chatId, string $date): void
    {
        $chat = $this->chats->forChat($chatId);
        $day = $chat->planner->getDay($date);

        $this->bot->sendMessage(
            $chatId,
            $day !== null ? $this->dayFormatter->format($day) : $this->dayFormatter->formatEmpty($date),
            'HTML',
            true,
            null,
            KeyboardFactory::dayNav($date, $this->dates->today()),
        );
    }

    private function editDay(int $chatId, int $messageId, string $date): void
    {
        $chat = $this->chats->forChat($chatId);
        $day = $chat->planner->getDay($date);

        $this->bot->editMessageText(
            $chatId,
            $messageId,
            $day !== null ? $this->dayFormatter->format($day) : $this->dayFormatter->formatEmpty($date),
            'HTML',
            true,
            KeyboardFactory::dayNav($date, $this->dates->today()),
        );
    }

    private function currentDate(ChatContext $chat): string
    {
        return $chat->state->getDay() ?? $this->dates->today();
    }

    private function args(Message $message): string
    {
        $text = (string) $message->getText();
        if (preg_match(Client::REGEXP, $text, $m)) {
            return trim((string) ($m[3] ?? ''));
        }

        return '';
    }

    private function extractChatId(Update $update): ?int
    {
        $message = $update->getMessage() ?? $update->getCallbackQuery()?->getMessage();

        return $message?->getChat()->getId();
    }

    private function safeReply(int $chatId, string $text): void
    {
        try {
            $this->bot->sendMessage($chatId, $text, 'HTML', true);
        } catch (\Throwable $e) {
            $this->log('Не удалось отправить сообщение об ошибке: ' . $e->getMessage());
        }
    }

    private function log(string $message): void
    {
        fwrite(STDERR, '[' . date('Y-m-d H:i:s') . "] {$message}\n");
    }
}
