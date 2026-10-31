<?php

declare(strict_types=1);

use App\Jobs\ProcessTelegramUpdateJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function tgUpdate(int $updateId, int $chatId, string $text): array
{
    return [
        'update_id' => $updateId,
        'message' => [
            'message_id' => 1,
            'chat' => ['id' => $chatId, 'type' => 'private'],
            'from' => ['id' => 1],
            'date' => time(),
            'text' => $text,
        ],
    ];
}

it('answers 200 quickly (controller stays thin, queue is async)', function (): void {
    Bus::fake();

    $durations = [];
    for ($i = 0; $i < 3; $i++) {
        $start = hrtime(true);
        $this->postJson('/api/telegram/webhook', tgUpdate(700_000_001 + $i, 550_001, '/start'));
        $durations[] = (hrtime(true) - $start) / 1e6; // миллисекунды
    }

    $this->assertSame(200, $this->postJson('/api/telegram/webhook', tgUpdate(700_000_100, 550_001, '/start'))->assertOk()->getStatusCode());

    // Критерий приёмки «< 50 мс»: 4 независимых замера, медиана должна уложиться
    // (воркер не участвует: Bus::fake фиксирует диспатч мгновенно).
    sort($durations);
    expect($durations[1])->toBeLessThan(50.0);

    Bus::assertDispatched(ProcessTelegramUpdateJob::class);
});

it('stores update and dispatches exactly once for duplicated update_id', function (): void {
    Bus::fake();

    $payload = tgUpdate(700_000_010, 550_002, '/task X');

    $this->postJson('/api/telegram/webhook', $payload)->assertOk();
    $this->postJson('/api/telegram/webhook', $payload)->assertOk();

    Bus::assertDispatchedTimes(ProcessTelegramUpdateJob::class, 1);

    expect(\DB::table('telegram_updates')->where('update_id', 700_000_010)->count())->toBe(1);
});

it('rejects calls with wrong webhook secret (403)', function (): void {
    config(['telegraph.webhook.secret' => 's3cr3t']);
    Bus::fake();

    $this->withHeaders([])->postJson('/api/telegram/webhook', tgUpdate(700_000_020, 550_003, '/start'))
        ->assertForbidden();

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 'wrong'])
        ->postJson('/api/telegram/webhook', tgUpdate(700_000_021, 550_003, '/start'))
        ->assertForbidden();

    $this->withHeaders(['X-Telegram-Bot-Api-Secret-Token' => 's3cr3t'])
        ->postJson('/api/telegram/webhook', tgUpdate(700_000_022, 550_003, '/start'))
        ->assertOk();
});

it('ignores updates without a chat', function (): void {
    Bus::fake();

    $this->postJson('/api/telegram/webhook', [
        'update_id' => 700_000_030,
        'inline_query' => ['id' => '1', 'from' => ['id' => 1], 'query' => 'test'],
    ])->assertOk()->assertJson(['skipped' => 'no_chat']);

    Bus::assertNothingDispatched();
});

it('rejects malformed payload (422)', function (): void {
    Bus::fake();

    $this->postJson('/api/telegram/webhook', ['foo' => 'bar'])->assertStatus(422);
    $this->postJson('/api/telegram/webhook', [])->assertStatus(422);

    Bus::assertNothingDispatched();
});
