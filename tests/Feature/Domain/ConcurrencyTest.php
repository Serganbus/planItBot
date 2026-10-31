<?php

declare(strict_types=1);

use App\Domain\Services\TaskService;
use App\Domain\TaskStatus;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

/**
 * Конкурентность: ДВА ОТДЕЛЬНЫХ PHP-процесса одновременно завершают
 * одну и ту же задачу. RefreshDatabase здесь НЕ используется:
 * данные должны быть закоммичены, чтобы их видели дочерние процессы.
 */
beforeEach(function (): void {
    Artisan::call('migrate', ['--force' => true]);
    DB::statement('TRUNCATE TABLE chats CASCADE');
    DB::table('dialog_states')->delete();
    DB::table('telegram_updates')->delete();
});

afterEach(function (): void {
    DB::statement('TRUNCATE TABLE chats CASCADE');
});

it('completes the same task from two concurrent processes exactly once', function (): void {
    $chatId = 123_456_789;
    $task = app(TaskService::class)->addFlexibleTask($chatId, '2026-10-27', 'Гонка');

    $childCode = <<<'PHP'
require getcwd() . '/vendor/autoload.php';
$app = require getcwd() . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$service = $app->make(App\Domain\Services\TaskService::class);
try {
    $task = $service->completeTask($argv[1], (int) $argv[2]);
    echo $task->completedAt?->format('Y-m-d H:i:s') ?? 'NULL';
} catch (Throwable $e) {
    fwrite(STDERR, get_class($e) . ': ' . $e->getMessage());
    exit(1);
}
PHP;

    $env = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_HOST' => '127.0.0.1',
        'DB_PORT' => '5433',
        'DB_DATABASE' => 'planner_bot_test',
        'DB_USERNAME' => 'planner',
        'DB_PASSWORD' => 'planner_secret',
        'CACHE_STORE' => 'array',
        'QUEUE_CONNECTION' => 'sync',
    ];

    $p1 = new Process([PHP_BINARY, '-r', $childCode, $task->id, (string) $chatId], base_path(), $env);
    $p2 = new Process([PHP_BINARY, '-r', $childCode, $task->id, (string) $chatId], base_path(), $env);

    $p1->start();
    $p2->start();
    $p1->wait();
    $p2->wait();

    expect($p1->isSuccessful())->toBeTrue()->and($p2->isSuccessful())->toBeTrue();

    $out1 = trim($p1->getOutput());
    $out2 = trim($p2->getOutput());

    // Оба процесса «успели нажать», но физически завершение зафиксировано один раз:
    // оба видят одинаковый completed_at.
    expect($out1)->not->toBe('NULL')->not->toBe('')
        ->and($out2)->toBe($out1);

    $final = app(TaskService::class)->forDate($chatId, '2026-10-27')[0];
    expect($final->status)->toBe(TaskStatus::Completed)
        ->and($final->completedAt?->format('Y-m-d H:i:s'))->toBe($out1);
});
