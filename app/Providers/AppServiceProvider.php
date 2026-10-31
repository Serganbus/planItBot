<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Dialogs\DialogHandlerRegistry;
use App\Application\Dialogs\DialogStateMachine;
use App\Application\Dialogs\Handlers\AddFlexibleTaskDialog;
use App\Application\Dialogs\Handlers\AddScheduledTaskDialog;
use App\Application\Dialogs\Handlers\EditTaskDialog;
use App\Application\Dialogs\Handlers\SetGoalDialog;
use App\Domain\Contracts\ChatRepositoryInterface;
use App\Domain\Contracts\StateRepositoryInterface;
use App\Domain\Contracts\TaskRepositoryInterface;
use App\Infrastructure\Persistence\EloquentStateRepository;
use App\Infrastructure\Persistence\PostgresChatRepository;
use App\Infrastructure\Persistence\PostgresTaskRepository;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Доменные контракты → инфраструктурные реализации (Шаг 3).
        $this->app->singleton(TaskRepositoryInterface::class, PostgresTaskRepository::class);
        $this->app->singleton(StateRepositoryInterface::class, EloquentStateRepository::class);
        $this->app->singleton(ChatRepositoryInterface::class, PostgresChatRepository::class);

        // FSM-диалоги (Шаг 4).
        $this->app->singleton(DialogHandlerRegistry::class, static fn ($app) => new DialogHandlerRegistry([
            $app->make(AddScheduledTaskDialog::class),
            $app->make(AddFlexibleTaskDialog::class),
            $app->make(EditTaskDialog::class),
            $app->make(SetGoalDialog::class),
        ]));

        $this->app->singleton(DialogStateMachine::class, static fn ($app) => new DialogStateMachine(
            $app->make(StateRepositoryInterface::class),
            $app->make(ChatRepositoryInterface::class),
            $app->make(DialogHandlerRegistry::class),
            (int) config('app.dialog_ttl_minutes', 30),
        ));
    }

    public function boot(): void
    {
        //
    }
}
