<?php

declare(strict_types=1);

namespace App\Application\Telegram;

use App\Application\Dialogs\DialogFlow;
use App\Application\Dialogs\DialogStateMachine;
use App\Domain\Contracts\ChatRepositoryInterface;
use App\Domain\Contracts\TaskRepositoryInterface;
use App\Domain\Exceptions\AmbiguousTaskIdException;
use App\Domain\Exceptions\TaskNotFoundException;
use App\Domain\Services\DayPlanService;
use App\Domain\Services\TaskService;
use App\Domain\Task;
use App\Support\DateParser;
use App\Support\IdResolver;
use DateTimeZone;

/**
 * Разбор и выполнение callback_query (формат "action;key:value",
 * собирается KeyboardFactory; ≤ 64 байт).
 */
final class PrefixCallbackDispatcher
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly TaskRepositoryInterface $taskRepository,
        private readonly DayPlanService $plans,
        private readonly DialogStateMachine $dialogs,
        private readonly PlannerMessenger $telegram,
        private readonly ChatRepositoryInterface $chats,
        private readonly IdResolver $ids,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     */
    public function dispatch(int $chatId, array $query): void
    {
        $queryId = (string) ($query['id'] ?? '');
        $messageId = (int) ($query['message']['message_id'] ?? 0);
        [$action, $params] = $this->parse((string) ($query['data'] ?? ''));

        switch ($action) {
            case KeyboardFactory::A_DAY:
                $this->showDay($chatId, $messageId, (string) ($params['date'] ?? ''), $queryId);
                break;
            case KeyboardFactory::A_STAT:
                $this->showStats($chatId, $messageId, (string) ($params['date'] ?? ''), $queryId);
                break;
            case KeyboardFactory::A_ADD_SCHEDULED:
                $this->startDialog($chatId, $queryId, DialogFlow::AddScheduled, (string) ($params['date'] ?? ''));
                break;
            case KeyboardFactory::A_ADD_FLEXIBLE:
                $this->startDialog($chatId, $queryId, DialogFlow::AddFlexible, (string) ($params['date'] ?? ''));
                break;
            case KeyboardFactory::A_DONE:
                $this->setDone($chatId, $messageId, $queryId, (string) ($params['id'] ?? ''), true);
                break;
            case KeyboardFactory::A_UNDO:
                $this->setDone($chatId, $messageId, $queryId, (string) ($params['id'] ?? ''), false);
                break;
            case KeyboardFactory::A_EDIT:
                $this->startEdit($chatId, $queryId, (string) ($params['id'] ?? ''));
                break;
            case KeyboardFactory::A_DEL:
                $this->askDelete($chatId, $messageId, $queryId, (string) ($params['id'] ?? ''));
                break;
            case KeyboardFactory::A_DELOK:
                $this->doDelete($chatId, $messageId, $queryId, (string) ($params['id'] ?? ''));
                break;
            case KeyboardFactory::A_CANCEL_DIALOG:
                $this->telegram->answerCallback($queryId, $this->dialogs->cancel($chatId)->text());
                break;
            default:
                $this->telegram->answerCallback($queryId, 'Неизвестная команда кнопки', true);
        }
    }

    /**
     * Формат: "action:done;id:1234abcd" (нативный для telegraph).
     *
     * @return array{0:?string,1:array<string,string>}
     */
    private function parse(string $data): array
    {
        if ($data === '' || strlen($data) > 64) {
            return [null, []];
        }

        $params = [];
        foreach (explode(';', $data) as $part) {
            $sepPos = strpos($part, ':');
            if ($sepPos !== false) {
                $params[substr($part, 0, $sepPos)] = substr($part, $sepPos + 1);
            }
        }

        $action = $params['action'] ?? null;
        unset($params['action']);

        return [$action, $params];
    }

    private function showDay(int $chatId, int $messageId, string $date, string $queryId): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $this->today($chatId);
        }

        $plan = $this->plans->planForDate($chatId, $date);
        $html = $plan->scheduled === [] && $plan->flexible === [] && $plan->mainGoal === null
            ? DayViewRenderer::formatDayEmpty($date)
            : DayViewRenderer::formatPlan($plan);

        $this->telegram->editHtml($chatId, $messageId, $html, KeyboardFactory::dayNav($date, $this->today($chatId)));
        $this->telegram->answerCallback($queryId);
    }

    private function showStats(int $chatId, int $messageId, string $date, string $queryId): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = $this->today($chatId);
        }

        $plan = $this->plans->planForDate($chatId, $date);
        $this->telegram->editHtml($chatId, $messageId, DayViewRenderer::formatStats($plan), KeyboardFactory::statsNav($date, $this->today($chatId)));
        $this->telegram->answerCallback($queryId);
    }

    private function startDialog(int $chatId, string $queryId, DialogFlow $flow, string $date): void
    {
        $context = $date !== '' ? ['date' => $date] : [];
        $result = $this->dialogs->begin($chatId, $flow, $context);

        $this->telegram->sendHtml($chatId, $result->text(), KeyboardFactory::cancelDialog());
        $this->telegram->answerCallback($queryId);
    }

    private function setDone(int $chatId, int $messageId, string $queryId, string $shortId, bool $done): void
    {
        try {
            $task = $done
                ? $this->tasks->completeTask($shortId, $chatId)
                : $this->tasks->reopenTask($shortId, $chatId);
        } catch (TaskNotFoundException | AmbiguousTaskIdException) {
            $this->telegram->answerCallback($queryId, 'Запись не найдена', true);

            return;
        }

        // Перерисовываем список отметок в том же сообщении (если оно известно).
        if ($messageId > 0) {
            $plan = $this->plans->planForDate($chatId, $task->date);
            $this->telegram->replaceKeyboard($chatId, $messageId, KeyboardFactory::doneList($plan, $task->date));
        }

        $this->telegram->answerCallback($queryId, $done ? '✅ Выполнено' : '↩ Возвращено в работу');
    }

    private function startEdit(int $chatId, string $queryId, string $shortId): void
    {
        $task = $this->findTaskByPrefix($chatId, $shortId);
        if ($task === null) {
            $this->telegram->answerCallback($queryId, 'Запись не найдена', true);

            return;
        }

        $result = $this->dialogs->begin($chatId, DialogFlow::EditTask, ['task_id' => $task->id]);
        $this->telegram->sendHtml($chatId, $result->text(), KeyboardFactory::cancelDialog());
        $this->telegram->answerCallback($queryId);
    }

    private function askDelete(int $chatId, int $messageId, string $queryId, string $shortId): void
    {
        $task = $this->findTaskByPrefix($chatId, $shortId);
        if ($task === null) {
            $this->telegram->answerCallback($queryId, 'Запись уже удалена', true);

            return;
        }

        $this->telegram->replaceKeyboard($chatId, $messageId, KeyboardFactory::confirmDelete($shortId, $task->date));
        $this->telegram->answerCallback($queryId, 'Подтвердите удаление');
    }

    private function doDelete(int $chatId, int $messageId, string $queryId, string $shortId): void
    {
        $task = $this->findTaskByPrefix($chatId, $shortId);
        if ($task === null) {
            $this->telegram->answerCallback($queryId, 'Запись уже удалена', true);

            return;
        }

        $this->tasks->deleteTask($task->id, $chatId);

        if ($messageId > 0) {
            $plan = $this->plans->planForDate($chatId, $task->date);
            $this->telegram->editHtml(
                $chatId,
                $messageId,
                '<b>Что удалить?</b> — ' . DayViewRenderer::dateHeader($task->date),
                KeyboardFactory::entries($plan, KeyboardFactory::A_DEL, $task->date),
            );
        }

        $this->telegram->answerCallback($queryId, '🗑 Удалено');
    }

    private function findTaskByPrefix(int $chatId, string $shortId): ?Task
    {
        try {
            $id = $this->ids->resolve($shortId, $chatId);
        } catch (TaskNotFoundException | AmbiguousTaskIdException) {
            return null;
        }

        return $this->taskRepository->findForChat($id, $chatId);
    }

    private function today(int $chatId): string
    {
        return (new DateParser(new DateTimeZone($this->chats->timezoneOf($chatId))))->today();
    }
}
