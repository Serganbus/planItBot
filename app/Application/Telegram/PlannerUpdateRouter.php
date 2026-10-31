<?php

declare(strict_types=1);

namespace App\Application\Telegram;

use App\Application\Dialogs\DialogFlow;
use App\Application\Dialogs\DialogOutcome;
use App\Application\Dialogs\DialogStateMachine;
use App\Domain\Contracts\ChatRepositoryInterface;
use App\Domain\DayPlan;
use App\Domain\Services\DayPlanService;
use App\Domain\Services\GoalService;
use App\Domain\Services\TaskService;
use App\Domain\TaskStatus;
use App\Domain\TaskType;
use App\Support\DateParser;
use App\Support\QuickCommandParser;
use App\Support\TagParser;
use DateTimeZone;

/**
 * Собственный диспетчер апдейтов внутри queue-воркера:
 * команды -> DialogStateMachine/сервисы, callback_query -> PrefixCallbackDispatcher,
 * web_app_data -> WebAppDataHandler.
 */
final class PlannerUpdateRouter
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly GoalService $goals,
        private readonly DayPlanService $plans,
        private readonly DialogStateMachine $dialogs,
        private readonly PrefixCallbackDispatcher $callbacks,
        private readonly WebAppDataHandler $webAppData,
        private readonly PlannerMessenger $telegram,
        private readonly ChatRepositoryInterface $chats,
    ) {
    }

    /**
     * @param array<string, mixed> $update
     */
    public function route(array $update): void
    {
        $chatId = self::extractChatId($update);
        if ($chatId === null) {
            return;
        }

        if (isset($update['callback_query']) && is_array($update['callback_query'])) {
            $this->callbacks->dispatch($chatId, $update['callback_query']);

            return;
        }

        $message = $update['message'] ?? null;
        if (!is_array($message)) {
            return; // my_chat_member, edited без текста и т.п. — игнорируем
        }

        if (isset($message['web_app_data']) && is_array($message['web_app_data'])) {
            $this->webAppData->handle($chatId, (string) ($message['web_app_data']['data'] ?? ''));

            return;
        }

        $text = trim((string) ($message['text'] ?? ''));
        if ($text === '') {
            return; // стикеры/фото/медиа без текста — молчим
        }

        if (str_starts_with($text, '/')) {
            $this->handleCommand($chatId, $text);
        } else {
            $this->handlePlainText($chatId, $text);
        }
    }

    /**
     * chat_id из любого поддерживаемого типа апдейта.
     *
     * @param array<string, mixed> $update
     */
    public static function extractChatId(array $update): ?int
    {
        $candidates = [
            $update['message']['chat']['id'] ?? null,
            $update['edited_message']['chat']['id'] ?? null,
            $update['callback_query']['message']['chat']['id'] ?? null,
            $update['my_chat_member']['chat']['id'] ?? null,
            $update['chat_member']['chat']['id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_numeric($candidate)) {
                return (int) $candidate;
            }
        }

        return null;
    }

    // ── Команды ───────────────────────────────────────────────────────────────

    private function handleCommand(int $chatId, string $text): void
    {
        [$command, $args] = $this->splitCommand($text);

        match ($command) {
            'start' => $this->cmdStart($chatId),
            'help' => $this->cmdHelp($chatId),
            'today' => $this->cmdDay($chatId, ''),
            'day' => $this->cmdDay($chatId, $args),
            'add' => $this->cmdAdd($chatId, $args),
            'task' => $this->cmdTask($chatId, $args),
            'goal' => $this->cmdGoal($chatId, $args),
            'done' => $this->cmdDone($chatId),
            'edit' => $this->cmdEntries($chatId, 'edit'),
            'remove' => $this->cmdEntries($chatId, 'del'),
            'stats' => $this->cmdStats($chatId),
            'tags' => $this->cmdTags($chatId),
            'tag' => $this->cmdTag($chatId, $args),
            'app' => $this->cmdApp($chatId),
            'cancel' => $this->cmdCancel($chatId),
            default => $this->telegram->sendHtml($chatId, 'Неизвестная команда. /help — список команд.'),
        };
    }

    /**
     * @return array{0:string,1:string}
     */
    private function splitCommand(string $text): array
    {
        $firstSpace = strpos($text, ' ');
        $head = $firstSpace === false ? $text : substr($text, 0, $firstSpace);
        $args = $firstSpace === false ? '' : trim(substr($text, $firstSpace + 1));

        // /cmd@MyBot → cmd
        $atPos = strpos($head, '@');
        if ($atPos !== false) {
            $head = substr($head, 0, $atPos);
        }

        return [mb_strtolower(ltrim($head, '/')), $args];
    }

    private function cmdStart(int $chatId): void
    {
        $today = $this->today($chatId);

        $keyboard = KeyboardFactory::dayNav($today, $today);
        $appUrl = (string) config('services.telegram.webapp_url', '');
        if ($appUrl !== '') {
            $keyboard = $keyboard->row([KeyboardFactory::webAppButton($appUrl)]);
        }

        $this->telegram->sendHtml(
            $chatId,
            "👋 Привет! Я бот-планировщик.\n\n"
            . "Помогаю вести расписание дня и список задач.\n\n"
            . "Основные команды:\n"
            . "/today — день на сегодня\n"
            . "/add — пункт расписания (время + название)\n"
            . "/task — задача с оценкой и приоритетом\n"
            . "/goal — главная цель дня\n"
            . "/done — отметить выполненное\n"
            . "/stats — статистика дня\n"
            . "/app — мини-приложение\n"
            . "/help — полная справка",
            $keyboard,
        );
    }

    private function cmdApp(int $chatId): void
    {
        $appUrl = (string) config('services.telegram.webapp_url', '');

        if ($appUrl === '') {
            $this->telegram->sendHtml($chatId, 'Мини-приложение не настроено (TELEGRAM_WEBAPP_URL).');

            return;
        }

        $this->telegram->sendHtml(
            $chatId,
            "📱 Откройте мини-приложение планировщика:",
            \DefStudio\Telegraph\Keyboard\Keyboard::make()->row([KeyboardFactory::webAppButton($appUrl)]),
        );
    }

    private function cmdHelp(int $chatId): void
    {
        $this->telegram->sendHtml(
            $chatId,
            "<b>📖 Справка</b>\n\n"
            . "<b>Просмотр:</b>\n"
            . "/today — сегодня\n"
            . "/day [дата] — день на дату (YYYY-MM-DD, сегодня, завтра, вчера, +N)\n"
            . "/stats — статистика дня\n"
            . "/tags — теги дня\n"
            . "/tag #имя — записи с тегом\n\n"
            . "<b>Добавление:</b>\n"
            . "/add — диалог или быстро: <code>/add 09:00 Стендап #работа</code>\n"
            . "/task — диалог или быстро: <code>/task Отчёт 60 high #работа</code>\n"
            . "/goal [текст] — главная цель на сегодня (показать/установить)\n\n"
            . "<b>Управление:</b>\n"
            . "/done — отметить выполненное / вернуть\n"
            . "/edit — изменить запись\n"
            . "/remove — удалить запись\n"
            . "/cancel — прервать диалог",
        );
    }

    private function cmdDay(int $chatId, string $args): void
    {
        $parser = $this->dates($chatId);
        $date = $args === '' ? $parser->today() : $parser->parse($args);

        if ($date === null) {
            $this->telegram->sendHtml(
                $chatId,
                '❌ Не удалось разобрать дату «' . DayViewRenderer::e($args) . '». Форматы: YYYY-MM-DD, ДД.ММ.ГГГГ, сегодня, завтра, вчера, +N.',
            );

            return;
        }

        $this->sendDay($chatId, $date);
    }

    private function cmdAdd(int $chatId, string $args): void
    {
        if ($args === '') {
            $result = $this->dialogs->begin($chatId, DialogFlow::AddScheduled);
            $this->telegram->sendHtml($chatId, $result->text(), KeyboardFactory::cancelDialog());

            return;
        }

        $parsed = QuickCommandParser::parseScheduled($args);

        if ($parsed['time'] === null || $parsed['title'] === '') {
            $this->telegram->sendHtml(
                $chatId,
                '❌ Формат: <code>/add 09:00 Название #тег</code> — нужны время и название. Или просто /add для пошагового диалога.',
            );

            return;
        }

        $today = $this->today($chatId);
        $task = $this->tasks->addScheduledTask($chatId, $today, $parsed['time'], $parsed['title'], $parsed['tags']);

        $this->telegram->sendHtml(
            $chatId,
            '✅ Добавлено: <code>' . DayViewRenderer::e($parsed['time']) . '</code> ' . DayViewRenderer::e($parsed['title'])
            . ($parsed['tags'] !== [] ? ' ' . DayViewRenderer::e(TagParser::format($parsed['tags'])) : ''),
            KeyboardFactory::backToDay($today),
        );
    }

    private function cmdTask(int $chatId, string $args): void
    {
        if ($args === '') {
            $result = $this->dialogs->begin($chatId, DialogFlow::AddFlexible);
            $this->telegram->sendHtml($chatId, $result->text(), KeyboardFactory::cancelDialog());

            return;
        }

        $parsed = QuickCommandParser::parseFlexible($args);

        if ($parsed['title'] === '') {
            $this->telegram->sendHtml(
                $chatId,
                '❌ Формат: <code>/task Название [минуты] [low|medium|high] #тег</code>. Или просто /task для пошагового диалога.',
            );

            return;
        }

        $today = $this->today($chatId);
        $task = $this->tasks->addFlexibleTask($chatId, $today, $parsed['title'], $parsed['minutes'], $parsed['priority'], $parsed['tags']);

        $this->telegram->sendHtml(
            $chatId,
            '✅ Задача добавлена: ' . DayViewRenderer::e($task->title),
            KeyboardFactory::backToDay($today),
        );
    }

    private function cmdGoal(int $chatId, string $args): void
    {
        $today = $this->today($chatId);

        if ($args === '') {
            $current = $this->goals->getGoal($chatId, $today);
            if ($current !== null && $current !== '') {
                $this->telegram->sendHtml(
                    $chatId,
                    '🎯 Цель дня <b>' . DayViewRenderer::e($today) . '</b>: ' . DayViewRenderer::e($current)
                    . "\nОтправьте /goal новый текст, чтобы изменить.",
                );

                return;
            }

            $result = $this->dialogs->begin($chatId, DialogFlow::SetGoal, ['date' => $today]);
            $this->telegram->sendHtml($chatId, $result->text(), KeyboardFactory::cancelDialog());

            return;
        }

        $this->goals->setGoal($chatId, $today, $args);
        $this->telegram->sendHtml(
            $chatId,
            '🎯 Цель дня <b>' . DayViewRenderer::e($today) . '</b> установлена: ' . DayViewRenderer::e($args),
            KeyboardFactory::backToDay($today),
        );
    }

    private function cmdDone(int $chatId): void
    {
        $today = $this->today($chatId);
        $plan = $this->plans->planForDate($chatId, $today);

        $this->telegram->sendHtml(
            $chatId,
            '<b>Отметить выполнение</b> — ' . DayViewRenderer::dateHeader($today) . "\n(✅ — выполнить, ↩ — вернуть в работу)",
            KeyboardFactory::doneList($plan, $today),
        );
    }

    private function cmdEntries(int $chatId, string $action): void
    {
        $today = $this->today($chatId);
        $plan = $this->plans->planForDate($chatId, $today);

        $title = $action === 'edit' ? '<b>Что изменить?</b> — ' : '<b>Что удалить?</b> — ';

        $this->telegram->sendHtml(
            $chatId,
            $title . DayViewRenderer::dateHeader($today),
            KeyboardFactory::entries($plan, $action === 'edit' ? KeyboardFactory::A_EDIT : KeyboardFactory::A_DEL, $today),
        );
    }

    private function cmdStats(int $chatId): void
    {
        $today = $this->today($chatId);
        $plan = $this->plans->planForDate($chatId, $today);

        $this->telegram->sendHtml($chatId, DayViewRenderer::formatStats($plan), KeyboardFactory::statsNav($today, $today));
    }

    private function cmdTags(int $chatId): void
    {
        $today = $this->today($chatId);
        $plan = $this->plans->planForDate($chatId, $today);

        $counts = [];
        foreach (array_merge($plan->scheduled, $plan->flexible) as $task) {
            foreach ($task->tags as $tag) {
                $counts[$tag] = ($counts[$tag] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            $this->telegram->sendHtml($chatId, 'На ' . DayViewRenderer::e($today) . ' тегов нет.');

            return;
        }

        ksort($counts);
        $lines = ['<b>🏷 Теги дня ' . DayViewRenderer::e($today) . ':</b>'];
        foreach ($counts as $tag => $count) {
            $lines[] = '#' . DayViewRenderer::e($tag) . ' ×' . $count;
        }
        $lines[] = '';
        $lines[] = 'Фильтр: /tag #имя';

        $this->telegram->sendHtml($chatId, implode("\n", $lines));
    }

    private function cmdTag(int $chatId, string $args): void
    {
        $tag = TagParser::normalize($args);

        if ($tag === '') {
            $this->telegram->sendHtml($chatId, '❌ Формат: <code>/tag #работа</code>');

            return;
        }

        $today = $this->today($chatId);
        $plan = $this->plans->planForDate($chatId, $today);

        $lines = ['<b>#' . DayViewRenderer::e($tag) . '</b> на ' . DayViewRenderer::e($today) . ':'];
        $found = 0;

        foreach (array_merge($plan->scheduled, $plan->flexible) as $task) {
            if (in_array($tag, $task->tags, true)) {
                $lines[] = $task->type === TaskType::Scheduled
                    ? DayViewRenderer::formatScheduled($task)
                    : DayViewRenderer::formatFlexible($task);
                $found++;
            }
        }

        if ($found === 0) {
            $lines[] = '<i>Ничего не найдено.</i>';
        }

        $this->telegram->sendHtml($chatId, implode("\n", $lines), KeyboardFactory::backToDay($today));
    }

    private function cmdCancel(int $chatId): void
    {
        $result = $this->dialogs->cancel($chatId);

        $text = $result->outcome === DialogOutcome::Idle
            ? 'Активного диалога нет. /help — список команд.'
            : $result->text();

        $this->telegram->sendHtml($chatId, $text, KeyboardFactory::backToDay($this->today($chatId)));
    }

    // ── Обычный текст (диалог) ────────────────────────────────────────────────

    private function handlePlainText(int $chatId, string $text): void
    {
        $result = $this->dialogs->handleText($chatId, $text);

        if ($result->outcome === DialogOutcome::Idle) {
            $this->telegram->sendHtml($chatId, 'Не понимаю 🤔 /help — список команд, /today — план на сегодня.');

            return;
        }

        $keyboard = $result->outcome === DialogOutcome::Prompt ? KeyboardFactory::cancelDialog() : null;
        $this->telegram->sendHtml($chatId, $result->text(), $keyboard);
    }

    // ── Вспомогательное ───────────────────────────────────────────────────────

    private function sendDay(int $chatId, string $date): void
    {
        $plan = $this->plans->planForDate($chatId, $date);

        $html = $plan->scheduled === [] && $plan->flexible === [] && $plan->mainGoal === null
            ? DayViewRenderer::formatDayEmpty($date)
            : DayViewRenderer::formatPlan($plan);

        $this->telegram->sendHtml($chatId, $html, KeyboardFactory::dayNav($date, $this->today($chatId)));
    }

    private function today(int $chatId): string
    {
        return $this->dates($chatId)->today();
    }

    private function dates(int $chatId): DateParser
    {
        return new DateParser(new DateTimeZone($this->chats->timezoneOf($chatId)));
    }
}
