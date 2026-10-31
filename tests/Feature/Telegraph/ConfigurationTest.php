<?php

declare(strict_types=1);

use App\Telegraph\Bots\PlannerBot;
use DefStudio\Telegraph\Telegraph;
use Illuminate\Support\Facades\Artisan;

it('exposes configuration of the named bot "main"', function (): void {
    expect(config('telegraph.bots.main'))
        ->toBeArray()
        ->toHaveKeys(['token', 'webhook_url'])
        ->and(config('telegraph.bots.main.token'))->toBe(env('TELEGRAM_BOT_TOKEN'));
});

it('registers telegraph artisan commands', function (): void {
    $commands = array_keys(Artisan::all());

    expect($commands)
        ->toContain('telegraph:new-bot')
        ->toContain('telegraph:new-chat')
        ->toContain('telegraph:set-webhook')
        ->toContain('telegraph:unset-webhook')
        ->toContain('telegraph:debug-webhook');
});

it('has telegraph migrations published', function (): void {
    expect(glob(database_path('migrations/*create_telegraph_bots_table.php')))->not->toBeEmpty()
        ->and(glob(database_path('migrations/*create_telegraph_chats_table.php')))->not->toBeEmpty();
});

it('provides a custom PlannerBot telegraph client', function (): void {
    expect(is_subclass_of(PlannerBot::class, Telegraph::class))->toBeTrue();
});
