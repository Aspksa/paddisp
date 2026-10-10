<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    /** @var array<string,string> */
    public array $params = [];

    /**
     * @param array<string,mixed> $query
     * @param array<string,mixed> $post
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        public readonly array $post,
        public readonly string $ip,
        public readonly Session $session,
    ) {
    }

    public static function fromGlobals(Session $session): self
    {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $path = '/' . trim($path, '/');
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            $path,
            $_GET,
            $_POST,
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            $session,
        );
    }

    public function input(string $key, ?string $default = null): ?string
    {
        $v = $this->post[$key] ?? $this->query[$key] ?? $default;
        return is_scalar($v) ? trim((string) $v) : $default;
    }

    public function int(string $key): ?int
    {
        $v = $this->input($key);
        return $v !== null && $v !== '' && ctype_digit($v) ? (int) $v : null;
    }

    public function param(string $key): int
    {
        return (int) ($this->params[$key] ?? 0);
    }
}
