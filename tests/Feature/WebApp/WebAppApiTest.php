<?php

declare(strict_types=1);

use App\Domain\Services\TaskService;
use App\Domain\TaskStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['telegraph.bots.main.token' => 'test-bot-token']);
});

/**
 * Собирает подписанную initDataRaw строку (та же формула, что и в Telegram).
 */
function webappInitData(array $params, ?int $authDate = null): string
{
    $params['auth_date'] = (string) ($authDate ?? time());
    ksort($params, SORT_STRING);

    $dataCheckString = implode("\n", array_map(
        static fn (string $k, string $v): string => "$k=$v",
        array_keys($params),
        $params,
    ));

    $token = (string) config('telegraph.bots.main.token');
    $hash = hash_hmac('sha256', $dataCheckString, hash_hmac('sha256', $token, 'WebAppData', true));

    $raw = [];
    foreach ($params as $k => $v) {
        $raw[] = rawurlencode($k) . '=' . rawurlencode($v);
    }

    return implode('&', $raw) . '&hash=' . $hash;
}

function webappUser(int $id, string $name = 'Test'): string
{
    return json_encode(['id' => $id, 'first_name' => $name], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function webappHeaders(array $params, ?int $authDate = null): array
{
    return ['X-Telegram-Init-Data' => webappInitData($params, $authDate)];
}

// ── Подпись ──────────────────────────────────────────────────────────────────

it('accepts the aiogram known-answer vector (algorithm verified externally)', function (): void {
    config(['telegraph.bots.main.token' => '42:TEST']);
    // initData из тестов aiogram (check_webapp_signature) — эталон алгоритма.
    $initData = 'auth_date=1650385342'
        . '&user=%7B%22id%22%3A42%2C%22first_name%22%3A%22Test%22%7D'
        . '&query_id=test'
        . '&hash=46d2ea5e32911ec8d30999b56247654460c0d20949b6277af519e76271182803';

    // auth_date старый — отключаем TTL только для проверки подписи.
    config(['services.telegram.webapp_auth_max_age' => PHP_INT_MAX]);

    $this->withHeader('X-Telegram-Init-Data', $initData)
        ->getJson('/api/webapp/day')
        ->assertOk()
        ->assertJsonStructure(['data' => ['date', 'goal', 'scheduled', 'flexible', 'stats']]);
});

it('accepts a properly signed request', function (): void {
    $this->withHeaders(webappHeaders(['user' => webappUser(777_001)]))
        ->getJson('/api/webapp/day?date=2026-10-26')
        ->assertOk()
        ->assertJsonPath('data.date', '2026-10-26');
});

it('rejects a tampered signature (403)', function (): void {
    $initData = webappInitData(['user' => webappUser(777_002)]);

    // подменили значение без пересчёта hash
    $forged = str_replace('777002', '666666', $initData);

    $this->withHeader('X-Telegram-Init-Data', $forged)
        ->getJson('/api/webapp/day')
        ->assertForbidden();
});

it('rejects a signature computed with a wrong token (403)', function (): void {
    $initData = webappInitData(['user' => webappUser(777_003)]);
    config(['telegraph.bots.main.token' => 'another-token']);

    $this->withHeader('X-Telegram-Init-Data', $initData)
        ->getJson('/api/webapp/day')
        ->assertForbidden();
});

it('rejects stale auth_date (401)', function (): void {
    $this->withHeaders(webappHeaders(['user' => webappUser(777_004)], time() - 3700))
        ->getJson('/api/webapp/day')
        ->assertUnauthorized();

    $this->withHeaders(webappHeaders(['user' => webappUser(777_004)], time() + 3600))
        ->getJson('/api/webapp/day')
        ->assertUnauthorized();
});

it('rejects missing init data (401)', function (): void {
    $this->getJson('/api/webapp/day')->assertUnauthorized();
});

// ── CRUD + IDOR ──────────────────────────────────────────────────────────────

it('creates, patches and deletes a task via API', function (): void {
    $headers = webappHeaders(['user' => webappUser(777_010)]);

    $created = $this->withHeaders($headers)->postJson('/api/webapp/tasks', [
        'type' => 'flexible',
        'task_date' => '2026-10-26',
        'title' => 'Отчёт',
        'estimated_minutes' => 40,
        'priority' => 2,
        'tags' => ['работа'],
    ])->assertCreated()->json('data');

    expect($created['title'])->toBe('Отчёт')
        ->and($created['status'])->toBe('pending')
        ->and($created['priority_label'])->toBe('средний');

    // PATCH: завершение + правка названия
    $updated = $this->withHeaders($headers)->patchJson('/api/webapp/tasks/' . $created['id'], [
        'status' => 'completed',
        'title' => 'Отчёт ✓',
    ])->assertOk()->json('data');

    expect($updated['status'])->toBe('completed')
        ->and($updated['title'])->toBe('Отчёт ✓')
        ->and($updated['completed_at'])->not->toBeNull();

    // возврат в работу
    $reopened = $this->withHeaders($headers)->patchJson('/api/webapp/tasks/' . $created['id'], [
        'status' => 'pending',
    ])->assertOk()->json('data');
    expect($reopened['status'])->toBe('pending');

    $this->withHeaders($headers)->deleteJson('/api/webapp/tasks/' . $created['id'])
        ->assertOk()
        ->assertJsonPath('data.deleted', true);

    // удалено — больше не находится
    $this->withHeaders($headers)->patchJson('/api/webapp/tasks/' . $created['id'], ['title' => 'x'])
        ->assertNotFound();
});

it('validates POST /tasks payload', function (): void {
    $headers = webappHeaders(['user' => webappUser(777_011)]);

    $this->withHeaders($headers)->postJson('/api/webapp/tasks', [
        'type' => 'scheduled',
        'task_date' => '2026-10-26',
        // нет title и scheduled_time
    ])->assertUnprocessable();
});

it('isolates users: no cross-user reads/writes (IDOR)', function (): void {
    $owner = webappHeaders(['user' => webappUser(777_020)]);
    $intruder = webappHeaders(['user' => webappUser(777_021)]);

    // владелец создаёт задачу
    $created = $this->withHeaders($owner)->postJson('/api/webapp/tasks', [
        'type' => 'scheduled',
        'task_date' => '2026-10-26',
        'title' => 'Личное',
        'scheduled_time' => '10:00',
    ])->assertCreated()->json('data');

    // владелец видит свой день
    $this->withHeaders($owner)->getJson('/api/webapp/day?date=2026-10-26')
        ->assertOk()
        ->assertJsonPath('data.scheduled.0.id', $created['id']);

    // чужак видит пустой день
    $day = $this->withHeaders($intruder)->getJson('/api/webapp/day?date=2026-10-26')->assertOk()->json('data');
    expect($day['scheduled'])->toBe([])->and($day['flexible'])->toBe([]);

    // чужак не может править/удалять чужую задачу (404, не 403-riddle)
    $this->withHeaders($intruder)->patchJson('/api/webapp/tasks/' . $created['id'], ['title' => 'взлом'])
        ->assertNotFound();
    $this->withHeaders($intruder)->deleteJson('/api/webapp/tasks/' . $created['id'])
        ->assertNotFound();

    // задача цела
    expect(app(TaskService::class)->forDate(777_020, '2026-10-26'))->toHaveCount(1);
});

it('sets and reads the goal', function (): void {
    $headers = webappHeaders(['user' => webappUser(777_030)]);

    $this->withHeaders($headers)->postJson('/api/webapp/goal', [
        'date' => '2026-10-26',
        'goal' => 'Спокойный день',
    ])->assertOk();

    $this->withHeaders($headers)->getJson('/api/webapp/day?date=2026-10-26')
        ->assertOk()
        ->assertJsonPath('data.goal', 'Спокойный день')
        ->assertJsonPath('data.goal', fn ($goal) => $goal === 'Спокойный день');
});
