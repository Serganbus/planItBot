<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Журнал доставленных webhook-апдейтов — идемпотентность.
 *
 * Ключ — составной (chat_id, update_id), на уровне БД это PK.
 * Eloquent не умеет составные PK, поэтому записи создаются через
 * DB::table()->upsert()/инсерт (Шаг 5), модель — для чтения.
 */
class TelegramUpdate extends Model
{
    protected $table = 'telegram_updates';

    protected $primaryKey = 'chat_id';

    public $incrementing = false;

    public $timestamps = false;

    protected $fillable = [
        'chat_id',
        'update_id',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'chat_id' => 'int',
            'update_id' => 'int',
            'processed_at' => 'datetime',
        ];
    }
}
