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
        Schema::create('chats', function (Blueprint $table): void {
            // Telegram chat_id: BIGINT (знаковый, без auto_increment) — защита от переполнения int.
            $table->bigInteger('chat_id');
            $table->primary('chat_id');

            $table->string('timezone')->default('UTC');
            $table->string('language', 10)->default('ru');
            $table->jsonb('settings')->default(DB::raw("'{}'::jsonb"));

            // Главная цель дня (main_goal) действует на дату goal_date.
            $table->date('goal_date')->nullable();
            $table->text('main_goal')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
