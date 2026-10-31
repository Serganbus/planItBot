<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telegram_updates', function (Blueprint $table): void {
            $table->bigInteger('chat_id');
            $table->bigInteger('update_id');
            $table->timestamp('processed_at')->nullable();

            // Составной PK реализует требуемый UNIQUE(chat_id, update_id):
            // повторная запись пары отклоняется БД (идемпотентность вебхука).
            $table->primary(['chat_id', 'update_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telegram_updates');
    }
};
