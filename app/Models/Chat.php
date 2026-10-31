<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Доменный чат планировщика (НЕ путать со служебной telegraph_chats пакета).
 * PK — Telegram chat_id (bigint, без auto_increment).
 */
class Chat extends Model
{
    protected $table = 'chats';

    protected $primaryKey = 'chat_id';

    public $incrementing = false;

    protected $keyType = 'int';

    protected $fillable = [
        'chat_id',
        'timezone',
        'language',
        'settings',
        'goal_date',
        'main_goal',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'int',
            'settings' => 'array',
            'goal_date' => 'date',
        ];
    }

    /** @return HasMany<Task, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class, 'chat_id', 'chat_id');
    }

    /** @return HasOne<DialogState, $this> */
    public function dialogState(): HasOne
    {
        return $this->hasOne(DialogState::class, 'chat_id', 'chat_id');
    }
}
