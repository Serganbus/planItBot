<?php

declare(strict_types=1);

namespace PlannerBot\State;

/**
 * Хранит состояние многошагового диалога и контекст чата
 * в файле data/chats/{chatId}/_state.json.
 */
final class StateStore
{
    private const FILE_NAME = '_state.json';

    public function __construct(private readonly string $chatDir)
    {
    }

    /**
     * @return array{flow:?string,step:?string,payload:array<string,mixed>,day:string}
     */
    public function get(): array
    {
        $path = $this->path();
        if (!is_file($path)) {
            return $this->defaults();
        }

        $data = json_decode((string) file_get_contents($path), true);
        if (!is_array($data)) {
            return $this->defaults();
        }

        return array_merge($this->defaults(), $data);
    }

    public function set(array $state): void
    {
        $this->ensureDir();
        file_put_contents(
            $this->path(),
            json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
    }

    public function clearFlow(): void
    {
        $state = $this->get();
        $state['flow'] = null;
        $state['step'] = null;
        $state['payload'] = [];
        $this->set($state);
    }

    public function startFlow(string $flow, string $step, array $payload = []): void
    {
        $state = $this->get();
        $state['flow'] = $flow;
        $state['step'] = $step;
        $state['payload'] = $payload;
        $this->set($state);
    }

    public function getDay(): ?string
    {
        $day = $this->get()['day'];

        return $day !== '' ? $day : null;
    }

    public function setDay(string $date): void
    {
        $state = $this->get();
        $state['day'] = $date;
        $this->set($state);
    }

    /**
     * @return array{flow:?string,step:?string,payload:array<string,mixed>,day:string}
     */
    private function defaults(): array
    {
        return [
            'flow' => null,
            'step' => null,
            'payload' => [],
            'day' => '',
        ];
    }

    private function path(): string
    {
        return rtrim($this->chatDir, '/\\') . '/' . self::FILE_NAME;
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->chatDir)) {
            mkdir($this->chatDir, 0755, true);
        }
    }
}
