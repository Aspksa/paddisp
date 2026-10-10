<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Clock;
use App\Core\Database;
use App\Core\DomainError;

/** Вход по логину и паролю с ограничением числа попыток. */
final class AuthService
{
    /** @param array{max_failures:int,max_ip_failures:int,window_minutes:int} $limits */
    public function __construct(private Database $db, private array $limits)
    {
    }

    public static function hash(string $password): string
    {
        return password_hash($password, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /**
     * Возвращает пользователя при успехе, null при неверных данных.
     * Бросает DomainError, если вход временно заблокирован.
     *
     * @return array<string,mixed>|null
     */
    public function attempt(string $login, string $password, string $ip): ?array
    {
        $login = mb_strtolower(trim($login));
        $since = Clock::now()->modify('-' . $this->limits['window_minutes'] . ' minutes')->format('Y-m-d H:i:s');

        $loginFailures = (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE login = ? AND success = 0 AND attempted_at >= ?',
            [$login, $since]
        );
        $ipFailures = (int) $this->db->value(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND success = 0 AND attempted_at >= ?',
            [$ip, $since]
        );
        if ($loginFailures >= $this->limits['max_failures'] || $ipFailures >= $this->limits['max_ip_failures']) {
            throw new DomainError('Слишком много неудачных попыток входа. Попробуйте через ' . $this->limits['window_minutes'] . ' мин.');
        }

        $user = $this->db->one('SELECT * FROM users WHERE login = ? AND active = 1', [$login]);
        // Проверка пароля выполняется и для несуществующего логина, чтобы время ответа не выдавало его.
        $hash = $user['password_hash'] ?? self::dummyHash();
        $ok = password_verify($password, $hash) && $user !== null;

        $this->db->insert('login_attempts', [
            'login' => mb_substr($login, 0, 64),
            'ip' => mb_substr($ip, 0, 45),
            'success' => $ok ? 1 : 0,
            'attempted_at' => Clock::nowSql(),
        ]);

        if (!$ok) {
            return null;
        }
        $algo = defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT;
        if (password_needs_rehash($hash, $algo)) {
            $this->db->update('users', (int) $user['id'], ['password_hash' => self::hash($password)]);
        }
        return $user;
    }

    public function changePassword(int $userId, string $current, string $new, string $confirm): void
    {
        $user = $this->db->one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null || !password_verify($current, $user['password_hash'])) {
            throw new DomainError('Текущий пароль указан неверно.');
        }
        self::assertStrong($new);
        if ($new !== $confirm) {
            throw new DomainError('Пароли не совпадают.');
        }
        if ($new === $current) {
            throw new DomainError('Новый пароль должен отличаться от текущего.');
        }
        $this->db->update('users', $userId, ['password_hash' => self::hash($new), 'must_change_password' => 0]);
    }

    private static function dummyHash(): string
    {
        static $hash = null;
        return $hash ??= self::hash(bin2hex(random_bytes(16)));
    }

    public static function assertStrong(string $password): void
    {
        if (mb_strlen($password) < 10) {
            throw new DomainError('Пароль должен содержать не менее 10 символов.');
        }
    }
}
