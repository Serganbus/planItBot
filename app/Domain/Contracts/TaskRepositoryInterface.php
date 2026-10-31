<?php

declare(strict_types=1);

namespace App\Domain\Contracts;

use App\Domain\Task;
use App\Domain\TaskStatus;

/**
 * Хранилище задач. Все методы принимают chatId и работают
 * строго в его границах (защита от IDOR).
 */
interface TaskRepositoryInterface
{
    public function findForChat(string $id, int $chatId): ?Task;

    /**
     * Полные id задач чата, начинающиеся с префикса (нижний регистр hex).
     *
     * @return string[]
     */
    public function findIdsByPrefix(int $chatId, string $prefix): array;

    /**
     * Все задачи чата на дату (Y-m-d), без сортировки — порядок задаёт DayPlanService.
     *
     * @return Task[]
     */
    public function forChatAndDate(int $chatId, string $date): array;

    /**
     * Вставка новой задачи. Возвращает свежий immutable-снимок из БД.
     */
    public function insert(Task $task): Task;

    /**
     * Полная замена строки состоянием переданного объекта (того же id).
     * Кидает TaskNotFoundException, если строки (id + chatId) нет.
     */
    public function replace(Task $task): Task;

    /**
     * Отметка выполнения: DB-транзакция + SELECT ... FOR UPDATE.
     * Идемпотентно: повторный вызов НЕ перезатирает completed_at.
     * Кидает TaskNotFoundException.
     */
    public function completeForChat(string $id, int $chatId): Task;

    /**
     * Смена статуса (pending/cancelled). Кидает TaskNotFoundException.
     */
    public function setStatusForChat(string $id, int $chatId, TaskStatus $status): Task;

    /**
     * @return bool true, если строка удалена
     */
    public function deleteForChat(string $id, int $chatId): bool;
}
