<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Состояние многошагового диалога (FSM) чата — одна запись на чат.
 */
class DialogState extends Model
{
    protected $table = 'dialog_states';

    protected $primaryKey = 'chat_id';

    public $incrementing = false;

    protected $keyType = 'int';

    /** В таблице есть только updated_at (миграция create_dialog_states). */
    public const CREATED_AT = null;

    protected $fillable = [
        'chat_id',
        'flow',
        'step',
        'payload',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'int',
            'payload' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Chat, $this> */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class, 'chat_id', 'chat_id');
    }
}
