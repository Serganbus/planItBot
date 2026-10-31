<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\DialogState;
use Illuminate\Console\Command;

/**
 * Удаление просроченных FSM-состояний (expires_at < now).
 * Запускается планировщиком (bootstrap/app.php, hourly).
 */
class PurgeStaleDialogStates extends Command
{
    protected $signature = 'dialogs:purge-stale';

    protected $description = 'Удалить просроченные состояния диалогов (TTL истёк)';

    public function handle(): int
    {
        $count = DialogState::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->delete();

        $this->info("Удалено просроченных состояний диалогов: {$count}.");

        return self::SUCCESS;
    }
}
