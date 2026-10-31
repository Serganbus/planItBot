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
        Schema::create('dialog_states', function (Blueprint $table): void {
            // Один активный диалог на чат: chat_id — PK и FK одновременно.
            $table->bigInteger('chat_id');
            $table->primary('chat_id');

            $table->string('flow');
            $table->string('step');
            $table->jsonb('payload')->default(DB::raw("'{}'::jsonb"));
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('updated_at')->useCurrent();

            $table->foreign('chat_id')
                ->references('chat_id')
                ->on('chats')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dialog_states');
    }
};
