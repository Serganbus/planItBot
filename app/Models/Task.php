<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\TaskStatus;
use App\Domain\TaskType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Задача плана дня (persistence-модель таблицы tasks).
 *
 * Доменный immutable-объект App\Domain\Task появится на Шаге 3;
 * эта Eloquent-модель — только слой хранения.
 */
class Task extends Model
{
    protected $table = 'tasks';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'id',
        'chat_id',
        'task_date',
        'type',
        'title',
        'scheduled_time',
        'estimated_minutes',
        'priority',
        'tags',
        'status',
        'telegram_message_id',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'int',
            'task_date' => 'date',
            'estimated_minutes' => 'int',
            'priority' => 'int',
            'tags' => 'array',
            'type' => TaskType::class,
            'status' => TaskStatus::class,
            'telegram_message_id' => 'int',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // UUID генерируется в приложении, а не sequence'ом БД.
        static::creating(function (Task $task): void {
            $task->id ??= (string) Str::uuid();
        });
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id', 'chat_id');
    }
}
