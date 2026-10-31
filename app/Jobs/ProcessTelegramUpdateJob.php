<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Application\Telegram\PlannerUpdateRouter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Обработка одного webhook-апдейта в воркере.
 *
 * В очередь кладётся ТОЛЬКО массив payload (никаких Request/Closure/моделей).
 * Идемпотентность — второй уровень: забираем «право на обработку» апдейта
 * транзакционно (UNIQUE(chat_id, update_id) + processed_at IS NULL).
 */
class ProcessTelegramUpdateJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    /** @var int[] */
    public array $backoff = [5, 30, 120];

    /**
     * @param array<string, mixed> $update
     */
    public function __construct(public readonly array $update)
    {
        $this->onQueue('telegram');
    }

    public function uniqueId(): string
    {
        $updateId = (int) ($this->update['update_id'] ?? 0);
        $chatId = PlannerUpdateRouter::extractChatId($this->update) ?? 0;

        return "telegram-update:{$chatId}:{$updateId}";
    }

    public function handle(PlannerUpdateRouter $router): void
    {
        $updateId = (int) ($this->update['update_id'] ?? 0);
        $chatId = PlannerUpdateRouter::extractChatId($this->update);

        if ($updateId <= 0 || $chatId === null) {
            return;
        }

        // Второй уровень идемпотентности (первый — insertOrIgnore в контроллере):
        // забираем право на обработку ровно один раз.
        $claimed = DB::transaction(static function () use ($chatId, $updateId): bool {
            return DB::table('telegram_updates')
                ->where('chat_id', $chatId)
                ->where('update_id', $updateId)
                ->whereNull('processed_at')
                ->update(['processed_at' => now()]) === 1;
        });

        if (!$claimed) {
            return;
        }

        try {
            $router->route($this->update);
        } catch (Throwable $e) {
            // Освобождаем «право обработки», чтобы retry очереди мог довезти апдейт.
            DB::table('telegram_updates')
                ->where('chat_id', $chatId)
                ->where('update_id', $updateId)
                ->update(['processed_at' => null]);

            report($e);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('ProcessTelegramUpdateJob failed', [
            'update_id' => $this->update['update_id'] ?? null,
            'error' => $exception->getMessage(),
        ]);
    }
}
