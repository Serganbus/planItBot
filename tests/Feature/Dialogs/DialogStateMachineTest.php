<?php

declare(strict_types=1);

use App\Application\Dialogs\DialogFlow;
use App\Application\Dialogs\DialogOutcome;
use App\Application\Dialogs\DialogStateMachine;
use App\Domain\Contracts\ChatRepositoryInterface;
use App\Domain\Contracts\StateRepositoryInterface;
use App\Domain\DialogState;
use App\Domain\Services\GoalService;
use App\Domain\Services\TaskService;
use App\Models\DialogState as DialogStateModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->machine = app(DialogStateMachine::class);
    $this->tasks = app(TaskService::class);
});

it('walks add_scheduled dialog from begin to created task', function (): void {
    $chatId = 910_001;

    $r1 = $this->machine->begin($chatId, DialogFlow::AddScheduled);
    expect($r1->outcome)->toBe(DialogOutcome::Prompt)
        ->and($r1->text())->toContain('название')
        ->and($r1->state->step)->toBe('title');

    $r2 = $this->machine->handleText($chatId, 'Отчёт директору');
    expect($r2->state->step)->toBe('date');

    $r3 = $this->machine->handleText($chatId, 'завтра');
    expect($r3->state->step)->toBe('time');

    $tzTomorrow = (new App\Support\DateParser(new DateTimeZone('UTC')))->shift((new App\Support\DateParser(new DateTimeZone('UTC')))->today(), 1);

    $r4 = $this->machine->handleText($chatId, '10:00');
    expect($r4->state->step)->toBe('tags');

    $r5 = $this->machine->handleText($chatId, '#работа #отчёты');
    expect($r5->outcome)->toBe(DialogOutcome::Completed)
        ->and($r5->text())->toContain('✅')
        ->and($r5->text())->toContain('10:00');

    // состояние очищено, задача создана
    expect($this->machine->activeState($chatId))->toBeNull();

    $created = $this->tasks->forDate($chatId, $tzTomorrow);
    expect($created)->toHaveCount(1)
        ->and($created[0]->title)->toBe('Отчёт директору')
        ->and($created[0]->scheduledTime)->toBe('10:00')
        ->and($created[0]->tags)->toBe(['работа', 'отчёты']);
});

it('skips the date step when date comes from context (button flow)', function (): void {
    $chatId = 910_002;

    $this->machine->begin($chatId, DialogFlow::AddScheduled, ['date' => '2026-12-01']);
    $afterTitle = $this->machine->handleText($chatId, 'Предновогодняя планёрка');

    expect($afterTitle->state->step)->toBe('time');
});

it('walks add_flexible dialog with skips', function (): void {
    $chatId = 910_003;

    $this->machine->begin($chatId, DialogFlow::AddFlexible);
    $this->machine->handleText($chatId, 'Рефакторинг');
    $this->machine->handleText($chatId, 'сегодня');
    $rMin = $this->machine->handleText($chatId, '45');
    expect($rMin->state->step)->toBe('priority');
    $rPr = $this->machine->handleText($chatId, 'высокий');
    expect($rPr->state->step)->toBe('tags');
    $rEnd = $this->machine->handleText($chatId, '-');
    expect($rEnd->outcome)->toBe(DialogOutcome::Completed);

    $today = (new App\Support\DateParser(new DateTimeZone('UTC')))->today();
    $created = $this->tasks->forDate($chatId, $today);
    expect($created)->toHaveCount(1)
        ->and($created[0]->estimatedMinutes)->toBe(45)
        ->and($created[0]->priority)->toBe(3)
        ->and($created[0]->tags)->toBe([]);
});

it('cancels dialog via button and via /cancel text', function (): void {
    $chatId = 910_004;

    $this->machine->begin($chatId, DialogFlow::AddFlexible);
    $this->machine->handleText($chatId, 'Черновик');
    $r = $this->machine->cancel($chatId);

    expect($r->outcome)->toBe(DialogOutcome::Cancelled)
        ->and($this->machine->activeState($chatId))->toBeNull();

    $this->machine->begin($chatId, DialogFlow::SetGoal);
    $r2 = $this->machine->handleText($chatId, '/cancel');

    expect($r2->outcome)->toBe(DialogOutcome::Cancelled)
        ->and($this->machine->activeState($chatId))->toBeNull()
        ->and($this->tasks->forDate($chatId, '2099-01-01'))->toHaveCount(0);
});

it('cancel with no active dialog is Idle', function (): void {
    $r = $this->machine->cancel(910_005);

    expect($r->outcome)->toBe(DialogOutcome::Idle);
});

it('keeps state on invalid input and recovers', function (): void {
    $chatId = 910_006;

    $this->machine->begin($chatId, DialogFlow::AddScheduled);
    $this->machine->handleText($chatId, 'Созвон');

    $bad = $this->machine->handleText($chatId, 'когда-нибудь');
    expect($bad->outcome)->toBe(DialogOutcome::Prompt)
        ->and($bad->text())->toContain('❌')
        ->and($bad->state->step)->toBe('date');

    $good = $this->machine->handleText($chatId, 'послезавтра-ish');
    expect($good->state->step)->toBe('date'); // всё ещё ждём дату

    $ok = $this->machine->handleText($chatId, 'сегодня');
    expect($ok->state->step)->toBe('time');
});

it('expires stale dialog (ttl) and reports it', function (): void {
    $chatId = 910_007;

    $this->machine->begin($chatId, DialogFlow::AddFlexible);

    $repo = app(StateRepositoryInterface::class);
    $repo->save(new DialogState(
        chatId: $chatId,
        flow: DialogFlow::AddFlexible->value,
        step: 'title',
        payload: ['timezone' => 'UTC'],
        expiresAt: (new DateTimeImmutable())->modify('-1 minute'),
        updatedAt: new DateTimeImmutable(),
    ));

    $r = $this->machine->handleText($chatId, 'Любой ввод');

    expect($r->outcome)->toBe(DialogOutcome::Cancelled)
        ->and($r->text())->toContain('⌛')
        ->and($this->machine->activeState($chatId))->toBeNull();
});

it('resumes dialog after process restart (state lives in DB only)', function (): void {
    $chatId = 910_008;

    $m1 = $this->machine;
    $m1->begin($chatId, DialogFlow::AddFlexible);
    $after = $m1->handleText($chatId, 'Возобновлённая задача');
    expect($after->state->step)->toBe('date');

    // «Перезапуск процесса»: новый экземпляр машины, никакой памяти процесса.
    $m2 = new DialogStateMachine(
        app(StateRepositoryInterface::class),
        app(ChatRepositoryInterface::class),
        app(App\Application\Dialogs\DialogHandlerRegistry::class),
        30,
    );

    $m2->handleText($chatId, 'завтра'); // minutes? нет: date
    $m2->handleText($chatId, '60');
    $m2->handleText($chatId, 'средний');
    $done = $m2->handleText($chatId, '-');

    expect($done->outcome)->toBe(DialogOutcome::Completed);

    $tzTomorrow = (new App\Support\DateParser(new DateTimeZone('UTC')))->shift((new App\Support\DateParser(new DateTimeZone('UTC')))->today(), 1);
    expect($this->tasks->forDate($chatId, $tzTomorrow))->toHaveCount(1);
});

it('edits one field of an existing task', function (): void {
    $chatId = 910_009;
    $task = $this->tasks->addScheduledTask($chatId, '2026-10-28', '09:00', 'Ревью');

    $begin = $this->machine->begin($chatId, DialogFlow::EditTask, ['task_id' => $task->id]);
    expect($begin->outcome)->toBe(DialogOutcome::Prompt);

    $this->machine->handleText($chatId, 'время');
    $done = $this->machine->handleText($chatId, '12:30');

    expect($done->outcome)->toBe(DialogOutcome::Completed)
        ->and($done->text())->toContain('✏️');

    $updated = $this->tasks->forDate($chatId, '2026-10-28')[0];
    expect($updated->scheduledTime)->toBe('12:30')
        ->and($updated->title)->toBe('Ревью');
});

it('refuses edit dialog without task id in context', function (): void {
    $r = $this->machine->begin(910_010, DialogFlow::EditTask, []);

    expect($r->outcome)->toBe(DialogOutcome::Cancelled)
        ->and($this->machine->activeState(910_010))->toBeNull();
});

it('sets main goal through set_goal dialog', function (): void {
    $chatId = 910_011;

    $this->machine->begin($chatId, DialogFlow::SetGoal);
    $this->machine->handleText($chatId, 'завтра');
    $done = $this->machine->handleText($chatId, 'Разобрать инцидент');

    expect($done->outcome)->toBe(DialogOutcome::Completed)
        ->and($done->text())->toContain('🎯');

    $tzTomorrow = (new App\Support\DateParser(new DateTimeZone('UTC')))->shift((new App\Support\DateParser(new DateTimeZone('UTC')))->today(), 1);
    expect(app(GoalService::class)->getGoal($chatId, $tzTomorrow))->toBe('Разобрать инцидент');
});

it('purges only stale dialog states via artisan command', function (): void {
    foreach ([920_001, 920_002, 920_003] as $chatId) {
        \App\Models\Chat::query()->create(['chat_id' => $chatId]);
    }

    DialogStateModel::query()->create(['chat_id' => 920_001, 'flow' => 'add_flexible', 'step' => 'title', 'expires_at' => now()->subMinute()]);
    DialogStateModel::query()->create(['chat_id' => 920_002, 'flow' => 'add_flexible', 'step' => 'title', 'expires_at' => now()->addHour()]);
    DialogStateModel::query()->create(['chat_id' => 920_003, 'flow' => 'set_goal', 'step' => 'goal', 'expires_at' => null]);

    Artisan::call('dialogs:purge-stale');

    expect(DialogStateModel::query()->find(920_001))->toBeNull()
        ->and(DialogStateModel::query()->find(920_002))->not->toBeNull()
        ->and(DialogStateModel::query()->find(920_003))->not->toBeNull();
});
