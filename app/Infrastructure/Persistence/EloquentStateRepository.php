<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Contracts\StateRepositoryInterface;
use App\Domain\DialogState as DialogStateDto;
use App\Models\DialogState as DialogStateModel;
use DateTimeImmutable;

/**
 * Хранилище состояний диалогов на Eloquent (upsert по chat_id).
 */
final class EloquentStateRepository implements StateRepositoryInterface
{
    public function find(int $chatId): ?DialogStateDto
    {
        $model = DialogStateModel::query()->find($chatId);

        if ($model === null) {
            return null;
        }

        // Читаем сырые значения колонок — не зависим от Carbon-кастов Eloquent.
        $expiresRaw = $model->getRawOriginal('expires_at');
        $updatedRaw = $model->getRawOriginal('updated_at');

        return new DialogStateDto(
            chatId: $model->chat_id,
            flow: $model->flow,
            step: $model->step,
            payload: $model->payload ?? [],
            expiresAt: $expiresRaw === null ? null : new DateTimeImmutable((string) $expiresRaw),
            updatedAt: $updatedRaw === null ? null : new DateTimeImmutable((string) $updatedRaw),
        );
    }

    public function save(DialogStateDto $state): void
    {
        DialogStateModel::query()->updateOrCreate(
            ['chat_id' => $state->chatId],
            [
                'flow' => $state->flow,
                'step' => $state->step,
                'payload' => $state->payload,
                'expires_at' => $state->expiresAt,
            ],
        );
    }

    public function clear(int $chatId): void
    {
        DialogStateModel::query()->where('chat_id', $chatId)->delete();
    }
}
