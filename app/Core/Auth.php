<?php

declare(strict_types=1);

namespace App\Core;

/** Текущий пользователь и проверка прав на сервере. */
final class Auth
{
    /** @var array<string,mixed>|null */
    private ?array $user = null;
    private bool $loaded = false;

    /** @param array<string,list<string>> $permissions */
    public function __construct(
        private Database $db,
        private Session $session,
        private array $permissions,
    ) {
    }

    /** @return array<string,mixed>|null */
    public function user(): ?array
    {
        if (!$this->loaded) {
            $this->loaded = true;
            $id = $this->session->get('user_id');
            if ($id !== null) {
                $user = $this->db->one('SELECT * FROM users WHERE id = ? AND active = 1', [(int) $id]);
                if ($user === null) {
                    $this->session->clear();
                }
                $this->user = $user;
            }
        }
        return $this->user;
    }

    public function id(): int
    {
        return (int) ($this->user()['id'] ?? 0);
    }

    public function role(): ?string
    {
        return $this->user()['role'] ?? null;
    }

    public function can(string $permission): bool
    {
        $role = $this->role();
        return $role !== null && in_array($permission, $this->permissions[$role] ?? [], true);
    }

    /** @return array<string,mixed> */
    public function requireUser(): array
    {
        $user = $this->user();
        if ($user === null) {
            throw new HttpException(401);
        }
        return $user;
    }

    /** @return array<string,mixed> */
    public function require(string $permission): array
    {
        $user = $this->requireUser();
        if (!$this->can($permission)) {
            throw new HttpException(403);
        }
        return $user;
    }

    /** @param array<string,mixed> $user */
    public function login(array $user): void
    {
        // Новый id сессии и новый CSRF-токен после входа.
        $this->session->regenerate();
        $this->session->clear();
        $this->session->set('user_id', (int) $user['id']);
        $this->user = $user;
        $this->loaded = true;
    }

    public function logout(): void
    {
        $this->session->clear();
        $this->session->regenerate();
        $this->user = null;
        $this->loaded = true;
    }
}
