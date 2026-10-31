<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table): void {
            // UUID генерируется в приложении (модель Task), не bigint.
            $table->uuid('id')->primary();

            $table->bigInteger('chat_id');
            $table->date('task_date');

            // string + CHECK вместо нативного PG enum (см. отчёт Шага 2).
            $table->string('type', 20);
            $table->text('title');
            $table->time('scheduled_time')->nullable();
            $table->smallInteger('estimated_minutes')->nullable();
            $table->smallInteger('priority')->default(0);
            $table->jsonb('tags')->default(DB::raw("'[]'::jsonb"));

            $table->string('status', 20)->default('pending');
            $table->bigInteger('telegram_message_id')->nullable();

            $table->timestamps();
            $table->timestamp('completed_at')->nullable();

            $table->foreign('chat_id')
                ->references('chat_id')
                ->on('chats')
                ->cascadeOnDelete();

            $table->index(['chat_id', 'task_date', 'status']);
            $table->index(['chat_id', 'type']);
        });

        // Псевдонимы PHP-enum App\Domain\TaskType / TaskStatus на уровне БД.
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_type_check CHECK (type IN ('scheduled', 'flexible', 'goal'))");
        DB::statement("ALTER TABLE tasks ADD CONSTRAINT tasks_status_check CHECK (status IN ('pending', 'completed', 'cancelled'))");

        // Поиск по тегам (jsonb @> и т.п.) и «горячий» список незакрытых задач.
        DB::statement('CREATE INDEX tasks_tags_gin ON tasks USING GIN (tags)');
        DB::statement("CREATE INDEX tasks_pending_idx ON tasks (chat_id, task_date) WHERE status = 'pending'");
    }

    public function down(): void
    {
        // При удалении таблицы constraints и индексы (включая созданные вручную) удаляются вместе с ней.
        Schema::dropIfExists('tasks');
    }
};
