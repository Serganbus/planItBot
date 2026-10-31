<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\WebApp;

use App\Domain\Contracts\ChatRepositoryInterface;
use App\Domain\Exceptions\TaskNotFoundException;
use App\Domain\Services\DayPlanService;
use App\Domain\Services\GoalService;
use App\Domain\Services\TaskService;
use App\Domain\Task;
use App\Domain\TaskStatus;
use App\Domain\TaskType;
use App\Http\Middleware\ValidateTelegramWebApp;
use App\Support\DateParser;
use App\Support\PriorityParser;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * REST API для Telegram Mini App. Вся бизнес-логика — в доменных
 * сервисах Шага 3; здесь только валидация, скоуп чата и трансформация.
 */
class TaskController
{
    public function __construct(
        private readonly TaskService $tasks,
        private readonly DayPlanService $plans,
        private readonly GoalService $goals,
        private readonly ChatRepositoryInterface $chats,
    ) {
    }

    /**
     * GET /api/webapp/day?date=Y-m-d
     */
    public function day(Request $request): JsonResponse
    {
        $chatId = $this->chatId($request);

        $date = (string) $request->query('date', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = (new DateParser(new DateTimeZone($this->chats->timezoneOf($chatId))))->today();
        }

        $plan = $this->plans->planForDate($chatId, $date);

        return response()->json([
            'data' => [
                'date' => $plan->date,
                'goal' => $plan->mainGoal,
                'scheduled' => array_map($this->serializeTask(...), $plan->scheduled),
                'flexible' => array_map($this->serializeTask(...), $plan->flexible),
                'stats' => $plan->stats,
            ],
        ]);
    }

    /**
     * POST /api/webapp/tasks
     */
    public function store(Request $request): JsonResponse
    {
        $chatId = $this->chatId($request);

        $data = $request->validate([
            'type' => ['required', Rule::enum(TaskType::class)],
            'task_date' => ['required', 'date_format:Y-m-d'],
            'title' => ['required', 'string', 'max:500'],
            'scheduled_time' => ['nullable', 'regex:/^\d{1,2}:\d{2}$/', 'required_if:type,scheduled'],
            'estimated_minutes' => ['nullable', 'integer', 'min:0', 'max:10000'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:3'],
            'tags' => ['nullable', 'array', 'max:10'],
            'tags.*' => ['string', 'max:64'],
        ]);

        $task = $data['type'] === TaskType::Scheduled->value
            ? $this->tasks->addScheduledTask($chatId, $data['task_date'], (string) $data['scheduled_time'], $data['title'], $data['tags'] ?? [])
            : $this->tasks->addFlexibleTask($chatId, $data['task_date'], $data['title'], isset($data['estimated_minutes']) ? (int) $data['estimated_minutes'] : null, (int) ($data['priority'] ?? 0), $data['tags'] ?? []);

        return response()->json(['data' => $this->serializeTask($task)], 201);
    }

    /**
     * PATCH /api/webapp/tasks/{uuid}
     */
    public function update(Request $request, string $uuid): JsonResponse
    {
        $chatId = $this->chatId($request);

        $data = $request->validate([
            'title' => ['sometimes', 'string', 'max:500'],
            'task_date' => ['sometimes', 'date_format:Y-m-d'],
            'scheduled_time' => ['sometimes', 'nullable', 'regex:/^\d{1,2}:\d{2}$/'],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'priority' => ['sometimes', 'integer', 'min:0', 'max:3'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:64'],
            'status' => ['sometimes', Rule::enum(TaskStatus::class)],
        ]);

        try {
            $task = $this->applyStatus($chatId, $uuid, $data['status'] ?? null);

            $changes = array_intersect_key($data, array_flip(['title', 'task_date', 'scheduled_time', 'estimated_minutes', 'priority', 'tags']));

            if ($changes !== [] || $task === null) {
                $map = [
                    'task_date' => 'date',
                    'scheduled_time' => 'time',
                ];
                $serviceChanges = [];
                foreach ($changes as $key => $value) {
                    $serviceChanges[$map[$key] ?? $key] = $value;
                }

                $task = $this->tasks->editTask($uuid, $chatId, $serviceChanges);
            }

            return response()->json(['data' => $this->serializeTask($task)]);
        } catch (TaskNotFoundException) {
            return $this->notFound();
        }
    }

    /**
     * DELETE /api/webapp/tasks/{uuid}
     */
    public function destroy(Request $request, string $uuid): JsonResponse
    {
        $chatId = $this->chatId($request);

        try {
            $this->tasks->deleteTask($uuid, $chatId);
        } catch (TaskNotFoundException) {
            return $this->notFound();
        }

        return response()->json(['data' => ['deleted' => true]]);
    }

    /**
     * POST /api/webapp/goal
     */
    public function goal(Request $request): JsonResponse
    {
        $chatId = $this->chatId($request);

        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'goal' => ['required', 'string', 'max:1000'],
        ]);

        $this->goals->setGoal($chatId, $data['date'], $data['goal']);

        return response()->json(['data' => ['date' => $data['date'], 'goal' => $data['goal']]]);
    }

    // ── Вспомогательное ───────────────────────────────────────────────────────

    private function applyStatus(int $chatId, string $uuid, ?string $status): ?Task
    {
        return match ($status) {
            TaskStatus::Completed->value => $this->tasks->completeTask($uuid, $chatId),
            TaskStatus::Cancelled->value => $this->tasks->cancelTask($uuid, $chatId),
            TaskStatus::Pending->value => $this->tasks->reopenTask($uuid, $chatId),
            default => null,
        };
    }

    private function chatId(Request $request): int
    {
        return (int) $request->attributes->get(ValidateTelegramWebApp::ATTR_CHAT_ID);
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Задача не найдена.'], 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeTask(Task $task): array
    {
        return [
            'id' => $task->id,
            'date' => $task->date,
            'type' => $task->type->value,
            'title' => $task->title,
            'scheduled_time' => $task->scheduledTime,
            'estimated_minutes' => $task->estimatedMinutes,
            'priority' => $task->priority,
            'priority_label' => PriorityParser::label($task->priority),
            'tags' => $task->tags,
            'status' => $task->status->value,
            'completed_at' => $task->completedAt?->format(DATE_ATOM),
        ];
    }
}
