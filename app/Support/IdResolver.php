<?php

declare(strict_types=1);

namespace App\Support;

use App\Domain\Contracts\TaskRepositoryInterface;
use App\Domain\Exceptions\AmbiguousTaskIdException;
use App\Domain\Exceptions\TaskNotFoundException;

/**
 * Разрешает полный UUID задачи или его hex-префикс (в кнопках callback_data
 * используются первые 8 символов — лимит Telegram 64 байта).
 *
 * При неоднозначном префиксе кидает AmbiguousTaskIdException,
 * а не берёт «первый попавшийся».
 */
final class IdResolver
{
    public const PREFIX_LENGTH = 8;

    private const FULL_UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const PREFIX_PATTERN = '/^[0-9a-f]{4,32}$/i';

    public function __construct(private readonly TaskRepositoryInterface $tasks)
    {
    }

    public static function shorten(string $uuid): string
    {
        return substr($uuid, 0, self::PREFIX_LENGTH);
    }

    /**
     * @throws TaskNotFoundException если задача не найдена в чате (включая чужой chatId — IDOR)
     * @throws AmbiguousTaskIdException если префикс совпал с несколькими задачами
     */
    public function resolve(string $idOrPrefix, int $chatId): string
    {
        $input = mb_strtolower(trim($idOrPrefix));

        if (preg_match(self::FULL_UUID_PATTERN, $input)) {
            $task = $this->tasks->findForChat($input, $chatId);

            if ($task === null) {
                throw TaskNotFoundException::byId($idOrPrefix, $chatId);
            }

            return $task->id;
        }

        if (!preg_match(self::PREFIX_PATTERN, $input)) {
            throw TaskNotFoundException::byId($idOrPrefix, $chatId);
        }

        $ids = $this->tasks->findIdsByPrefix($chatId, $input);

        return match (count($ids)) {
            1 => $ids[0],
            0 => throw TaskNotFoundException::byId($idOrPrefix, $chatId),
            default => throw new AmbiguousTaskIdException($input, $ids),
        };
    }
}
