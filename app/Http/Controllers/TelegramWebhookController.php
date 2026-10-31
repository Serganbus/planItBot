<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Application\Telegram\PlannerUpdateRouter;
use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Тонкий приёмник webhook: валидация -> запись в БД (идемпотентно) ->
 * dispatch в очередь -> HTTP 200. Никаких вызовов Bot API синхронно,
 * никаких Request/Closure в очереди.
 */
class TelegramWebhookController
{
    public function __invoke(Request $request): JsonResponse
    {
        /** @var array<string, mixed> $update */
        $update = (array) $request->json()->all();

        $updateId = (int) ($update['update_id'] ?? 0);

        if ($updateId <= 0 || $update === []) {
            return response()->json(['ok' => false, 'error' => 'invalid update payload'], 422);
        }

        $chatId = PlannerUpdateRouter::extractChatId($update);

        // Апдейты без чата (inline_query и т.п.) — не наша задача.
        if ($chatId === null) {
            return response()->json(['ok' => true, 'skipped' => 'no_chat']);
        }

        // Первый уровень идемпотентности: UNIQUE(chat_id, update_id).
        $isNew = DB::table('telegram_updates')->insertOrIgnore([
            'chat_id' => $chatId,
            'update_id' => $updateId,
        ]) === 1;

        if ($isNew) {
            ProcessTelegramUpdateJob::dispatch($update);
        }

        return response()->json(['ok' => true, 'queued' => $isNew]);
    }
}
