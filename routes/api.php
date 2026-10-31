<?php

declare(strict_types=1);

use App\Http\Controllers\Api\WebApp\TaskController;
use App\Http\Controllers\TelegramWebhookController;
use App\Http\Middleware\VerifyTelegramWebhookSecret;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware(VerifyTelegramWebhookSecret::class)
    ->name('telegram.webhook');

Route::middleware(['telegram.webapp', 'throttle:60,1'])
    ->prefix('webapp')
    ->name('webapp.')
    ->group(function (): void {
        Route::get('/day', [TaskController::class, 'day'])->name('day');
        Route::post('/tasks', [TaskController::class, 'store'])->name('tasks.store');
        Route::patch('/tasks/{uuid}', [TaskController::class, 'update'])->name('tasks.update');
        Route::delete('/tasks/{uuid}', [TaskController::class, 'destroy'])->name('tasks.destroy');
        Route::post('/goal', [TaskController::class, 'goal'])->name('goal');
    });
