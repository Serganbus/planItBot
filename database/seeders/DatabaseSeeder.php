<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Services\GoalService;
use App\Domain\Services\TaskService;
use App\Support\DateParser;
use DateTimeZone;
use Illuminate\Database\Seeder;

/**
 * Демонстрационный набор для локальной разработки:
 * один чат (demo, chat_id=42), пара задач на сегодня/завтра и цель дня.
 * Запуск: php artisan db:seed (или migrate:fresh --seed).
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $chatId = 42;
        $dates = new DateParser(new DateTimeZone('UTC'));
        $today = $dates->today();
        $tomorrow = $dates->shift($today, 1);

        $tasks = app(TaskService::class);

        $tasks->addScheduledTask($chatId, $today, '09:00', 'Планёрка', ['работа']);
        $tasks->addScheduledTask($chatId, $today, '18:30', 'Спортзал');
        $tasks->addFlexibleTask($chatId, $today, 'Разобрать входящие', 30, 2, ['дом']);
        $tasks->addFlexibleTask($chatId, $tomorrow, 'Подготовить презентацию', 90, 3, ['работа']);
        $tasks->addFlexibleTask($chatId, $tomorrow, 'Прочитать RFC-0123', 45, 1, ['работа', 'чтение']);

        app(GoalService::class)->setGoal($chatId, $today, 'Спокойный, но продуктивный день');

        $this->command->info("Демо-чат {$chatId}: " . count($tasks->forDate($chatId, $today)) . ' записей сегодня.');
    }
}
