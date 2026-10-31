<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Contracts\ChatRepositoryInterface;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Доступ к таблице chats (профиль + главная цель дня).
 */
final class PostgresChatRepository implements ChatRepositoryInterface
{
    public function ensure(int $chatId): void
    {
        // Дефолты колонок (timezone/language/settings) подставит сама БД.
        DB::table('chats')->insertOrIgnore([
            'chat_id' => $chatId,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function timezoneOf(int $chatId): string
    {
        return (string) (DB::table('chats')->where('chat_id', $chatId)->value('timezone') ?? 'UTC');
    }

    public function getGoal(int $chatId, string $date): ?string
    {
        $row = DB::table('chats')
            ->where('chat_id', $chatId)
            ->first(['goal_date', 'main_goal']);

        if ($row === null || $row->goal_date === null) {
            return null;
        }

        return substr((string) $row->goal_date, 0, 10) === $date
            ? $row->main_goal
            : null;
    }

    public function setGoal(int $chatId, string $date, string $goal): void
    {
        $this->ensure($chatId);

        DB::table('chats')->where('chat_id', $chatId)->update([
            'goal_date' => $date,
            'main_goal' => $goal,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
