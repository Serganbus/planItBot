<?php

declare(strict_types=1);

use App\Domain\Services\TaskService;
use App\Domain\TaskStatus;
use App\Jobs\ProcessTelegramUpdateJob;
use App\Support\IdResolver;
use DefStudio\Telegraph\Facades\Telegraph;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Telegraph::fake();
    // Без токена telegraph уходит искать бота в БД TelegraphBot — подставляем тестовый.
    config(['telegraph.bots.main.token' => 'test-bot-token']);
});

function tgText(int $updateId, int $chatId, string $text): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 100 + $updateId % 1000,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => 1],
            'date' => time(),
            'text' => $text,
        ],
    ];
}

function tgCallback(int $updateId, int $chatId, string $data, int $messageId = 55): array
{
    return [
        'update_id' => $updateId,
        'callback_query' => [
            // telegraph требует int для callback_query_id
            'id' => (string) (900_000_000 + $updateId % 1000),
            'from' => ['id' => 1],
            'message' => ['message_id' => $messageId, 'chat' => ['id' => $chatId, 'type' => 'private']],
            'data' => $data,
        ],
    ];
}

it('e2e: same update_id twice creates a task only once (controller + job idempotency)', function (): void {
    $payload = tgText(800_000_001, 550_101, '/task Срочное 30 high');

    $this->postJson('/api/telegram/webhook', $payload)->assertOk();
    $this->postJson('/api/telegram/webhook', $payload)->assertOk(); // дубликат доставки Telegram

    $today = (new App\Support\DateParser(new DateTimeZone('UTC')))->today();
    $tasks = app(TaskService::class)->forDate(550_101, $today);

    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->title)->toBe('Срочное')
        ->and($tasks[0]->estimatedMinutes)->toBe(30)
        ->and($tasks[0]->priority)->toBe(3);
});

it('e2e: job dispatched twice for same update runs effects once (2nd protection level)', function (): void {
    $payload = tgText(800_000_002, 550_102, '/task Разовое');
    \DB::table('telegram_updates')->insert(['chat_id' => 550_102, 'update_id' => 800_000_002]);

    \Illuminate\Support\Facades\Bus::dispatchSync(new ProcessTelegramUpdateJob($payload));
    \Illuminate\Support\Facades\Bus::dispatchSync(new ProcessTelegramUpdateJob($payload));

    $today = (new App\Support\DateParser(new DateTimeZone('UTC')))->today();
    expect(app(TaskService::class)->forDate(550_102, $today))->toHaveCount(1);
});

it('answers /start with greeting', function (): void {
    $this->postJson('/api/telegram/webhook', tgText(800_000_010, 550_103, '/start'))->assertOk();

    Telegraph::assertSent('Привет', exact: false);
});

it('answers unknown slash command and stray text politely', function (): void {
    $this->postJson('/api/telegram/webhook', tgText(800_000_011, 550_104, '/приветик'))->assertOk();
    Telegraph::assertSent('Неизвестная команда', exact: false);

    $this->postJson('/api/telegram/webhook', tgText(800_000_012, 550_104, 'просто текст'))->assertOk();
    Telegraph::assertSent('Не понимаю', exact: false);
});

it('creates a task via quick /task command', function (): void {
    $this->postJson('/api/telegram/webhook', tgText(800_000_013, 550_105, '/task Отчёт 45 высокий #работа'))->assertOk();

    Telegraph::assertSent('✅', exact: false);

    $today = (new App\Support\DateParser(new DateTimeZone('UTC')))->today();
    $tasks = app(TaskService::class)->forDate(550_105, $today);
    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->title)->toBe('Отчёт')
        ->and($tasks[0]->tags)->toBe(['работа']);
});

it('walks the /add dialog via text inputs', function (): void {
    $chatId = 550_106;

    $this->postJson('/api/telegram/webhook', tgText(800_000_020, $chatId, '/add'))->assertOk();
    $this->postJson('/api/telegram/webhook', tgText(800_000_021, $chatId, 'Стендап'))->assertOk();
    $this->postJson('/api/telegram/webhook', tgText(800_000_022, $chatId, 'завтра'))->assertOk();
    $this->postJson('/api/telegram/webhook', tgText(800_000_023, $chatId, '09:30'))->assertOk();
    $this->postJson('/api/telegram/webhook', tgText(800_000_024, $chatId, '#работа'))->assertOk();

    $tz = (new App\Support\DateParser(new DateTimeZone('UTC')));
    $tasks = app(TaskService::class)->forDate($chatId, $tz->shift($tz->today(), 1));
    expect($tasks)->toHaveCount(1)
        ->and($tasks[0]->scheduledTime)->toBe('09:30');
});

it('completes a task via done callback and answers the callback query', function (): void {
    $chatId = 550_107;
    $task = app(TaskService::class)->addFlexibleTask($chatId, (new App\Support\DateParser(new DateTimeZone('UTC')))->today(), 'Кнопка');
    $shortId = IdResolver::shorten($task->id);

    $this->postJson('/api/telegram/webhook', tgCallback(800_000_030, $chatId, App\Application\Telegram\KeyboardFactory::data('done', ['id' => $shortId])))->assertOk();

    Telegraph::assertRepliedWebhook('✅ Выполнено');

    $today = (new App\Support\DateParser(new DateTimeZone('UTC')))->today();
    expect(app(TaskService::class)->forDate($chatId, $today)[0]->status)->toBe(TaskStatus::Completed);

    // Повторное нажатие той же кнопки (задержанный callback) — задача одна и без дублей эффектов.
    $this->postJson('/api/telegram/webhook', tgCallback(800_000_031, $chatId, App\Application\Telegram\KeyboardFactory::data('done', ['id' => $shortId])))->assertOk();
    expect(app(TaskService::class)->forDate($chatId, $today))->toHaveCount(1);
});

it('starts edit dialog via edit callback', function (): void {
    $chatId = 550_108;
    $task = app(TaskService::class)->addFlexibleTask($chatId, (new App\Support\DateParser(new DateTimeZone('UTC')))->today(), 'Редактируемая');
    $shortId = IdResolver::shorten($task->id);

    $this->postJson('/api/telegram/webhook', tgCallback(800_000_040, $chatId, App\Application\Telegram\KeyboardFactory::data('edit', ['id' => $shortId])))->assertOk();

    Telegraph::assertSent('Какое поле изменить', exact: false);

    // продолжение диалога текстом
    $this->postJson('/api/telegram/webhook', tgText(800_000_041, $chatId, 'приоритет'))->assertOk();
    $this->postJson('/api/telegram/webhook', tgText(800_000_042, $chatId, 'высокий'))->assertOk();

    Telegraph::assertSent('✏️', exact: false);

    $today = (new App\Support\DateParser(new DateTimeZone('UTC')))->today();
    expect(app(TaskService::class)->forDate($chatId, $today)[0]->priority)->toBe(3);
});

it('passes web_app_data to the Web App handler', function (): void {
    $payload = tgText(800_000_050, 550_109, '');
    unset($payload['message']['text']);
    $payload['message']['web_app_data'] = ['data' => 'ping'];

    $this->postJson('/api/telegram/webhook', $payload)->assertOk();

    Telegraph::assertSent('Mini App', exact: false);
});
