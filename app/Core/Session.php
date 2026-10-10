<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Сессия поверх массива. В вебе это $_SESSION (по ссылке),
 * в тестах — обычный массив.
 */
class Session
{
    /** @var array<string,mixed> */
    protected array $data;

    /** @param array<string,mixed> $storage */
    public function __construct(array &$storage)
    {
        $this->data = &$storage;
    }

    public static function inMemory(): self
    {
        $storage = [];
        return new self($storage);
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
    }

    public function remove(string $key): void
    {
        unset($this->data[$key]);
    }

    /** Новый идентификатор сессии после входа (защита от фиксации сессии). */
    public function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function clear(): void
    {
        foreach (array_keys($this->data) as $k) {
            unset($this->data[$k]);
        }
    }

    public function flash(string $type, string $message): void
    {
        $this->data['_flash'][] = ['type' => $type, 'message' => $message];
    }

    /** @return list<array{type:string,message:string}> */
    public function takeFlashes(): array
    {
        $f = $this->data['_flash'] ?? [];
        unset($this->data['_flash']);
        return $f;
    }

    public function csrfToken(): string
    {
        if (empty($this->data['_csrf'])) {
            $this->data['_csrf'] = bin2hex(random_bytes(32));
        }
        return $this->data['_csrf'];
    }
}
