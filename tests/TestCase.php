<?php

declare(strict_types=1);

namespace Tests;

use App\Core\App;
use App\Core\Clock;
use App\Core\Database;
use App\Core\DomainError;
use App\Core\Response;
use App\Core\Session;
use App\Core\ValidationException;
use DateTimeImmutable;
use Throwable;

/** Минимальный тестовый каркас без внешних зависимостей. */
abstract class TestCase
{
    public static Database $db;
    public static \App\Core\Config $config;
    public static string $passwordHash;

    protected App $app;
    /** @var array<string,int> */
    protected array $users = [];
    /** @var array<string,int> */
    protected array $drivers = [];
    /** @var array<string,int> */
    protected array $vehicles = [];
    protected int $department;

    public const NOW = '2026-10-10 09:00:00';
    public const PASSWORD = 'correct-horse-battery';

    public function setUp(): void
    {
        Clock::freeze(new DateTimeImmutable(self::NOW, Clock::timezone()));
        $db = self::$db;
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($db->all('SHOW TABLES') as $row) {
            $t = (string) array_values($row)[0];
            if ($t !== 'schema_migrations') {
                $db->pdo->exec("TRUNCATE TABLE `$t`");
            }
        }
        $db->pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

        $this->department = $db->insert('departments', ['name' => 'ПТО', 'active' => 1]);
        foreach ([
            'employee' => 'employee', 'employee2' => 'employee', 'dispatcher' => 'dispatcher',
            'driver1' => 'driver', 'driver2' => 'driver', 'admin' => 'admin',
        ] as $login => $role) {
            $this->users[$login] = $db->insert('users', [
                'login' => $login, 'password_hash' => self::$passwordHash, 'full_name' => ucfirst($login),
                'department_id' => $this->department, 'role' => $role, 'active' => 1,
                'must_change_password' => 0, 'created_at' => self::NOW,
            ]);
            if ($role === 'driver') {
                $this->drivers[$login] = $db->insert('drivers', ['user_id' => $this->users[$login], 'active' => 1]);
            }
        }
        foreach (['car1' => 'А001АА125', 'car2' => 'В002ВВ125'] as $key => $reg) {
            $this->vehicles[$key] = $db->insert('vehicles', [
                'brand' => 'Toyota', 'model' => 'Camry', 'registration_number' => $reg,
                'seat_count' => 4, 'status' => 'in_service', 'active' => 1,
            ]);
        }
        $this->app = new App(self::$config, $db);
    }

    // --- данные ------------------------------------------------------------

    /** @return array<string,mixed> */
    protected function user(string $login): array
    {
        return self::$db->one('SELECT * FROM users WHERE id = ?', [$this->users[$login]]);
    }

    /** @return array<string,mixed> */
    protected function requestInput(string $date = '2026-10-15', string $start = '10:00', string $end = '12:00', array $extra = []): array
    {
        return $extra + [
            'requested_date' => $date, 'start_time' => $start, 'end_time' => $end,
            'department_id' => (string) $this->department, 'pickup_location' => 'Офис',
            'destination' => 'Объект №3', 'purpose' => 'Выезд на объект',
        ];
    }

    protected function submitRequest(string $login = 'employee', string $date = '2026-10-15', string $start = '10:00', string $end = '12:00', array $extra = []): int
    {
        return $this->app->requests->create($this->user($login), $this->requestInput($date, $start, $end, $extra), true)['id'];
    }

    protected function approve(int $requestId, string $car = 'car1', string $driver = 'driver1'): int
    {
        return $this->app->requests->approve($this->user('dispatcher'), $requestId, $this->vehicles[$car], $this->drivers[$driver]);
    }

    // --- HTTP --------------------------------------------------------------

    protected function client(?string $login = null): TestClient
    {
        $client = new TestClient($this->app);
        if ($login !== null) {
            $client->session->set('user_id', $this->users[$login]);
        }
        return $client;
    }

    // --- проверки ----------------------------------------------------------

    protected function assertTrue(bool $cond, string $message = 'Ожидалось true'): void
    {
        if (!$cond) {
            throw new AssertionFailed($message);
        }
    }

    protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void
    {
        if ($expected !== $actual) {
            throw new AssertionFailed(($message ? "$message: " : '') . 'ожидалось ' . var_export($expected, true) . ', получено ' . var_export($actual, true));
        }
    }

    protected function assertContains(string $needle, string $haystack, string $message = ''): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new AssertionFailed(($message ? "$message: " : '') . "строка не содержит «{$needle}»");
        }
    }

    protected function assertRedirect(Response $r, string $to): void
    {
        $this->assertTrue(in_array($r->status, [301, 302, 303], true), "Ожидался редирект, статус {$r->status}");
        $this->assertSame($to, $r->headers['Location'] ?? null, 'Location');
    }

    /** @param class-string<Throwable> $class */
    protected function assertThrows(string $class, callable $fn, ?string $messagePart = null): Throwable
    {
        try {
            $fn();
        } catch (Throwable $e) {
            if (!$e instanceof $class) {
                throw new AssertionFailed("Ожидалось исключение $class, получено " . get_class($e) . ': ' . $e->getMessage());
            }
            if ($messagePart !== null) {
                $text = $e instanceof ValidationException ? implode(' ', $e->errors) : $e->getMessage();
                $this->assertContains($messagePart, $text, 'Текст ошибки');
            }
            return $e;
        }
        throw new AssertionFailed("Ожидалось исключение $class");
    }

    protected function assertDomainError(callable $fn, ?string $messagePart = null): void
    {
        $this->assertThrows(DomainError::class, $fn, $messagePart);
    }

    protected function count(string $sql, array $params = []): int
    {
        return (int) self::$db->value($sql, $params);
    }
}

final class AssertionFailed extends \RuntimeException
{
}

final class TestClient
{
    public Session $session;

    public function __construct(private App $app)
    {
        $this->session = Session::inMemory();
    }

    public function get(string $path, array $query = []): Response
    {
        return $this->app->handle(new \App\Core\Request('GET', $path, $query, [], '127.0.0.1', $this->session));
    }

    /** CSRF-токен подставляется автоматически, если не передан явно. */
    public function post(string $path, array $data = [], bool $withCsrf = true): Response
    {
        if ($withCsrf && !isset($data['_csrf'])) {
            $data['_csrf'] = $this->session->csrfToken();
        }
        return $this->app->handle(new \App\Core\Request('POST', $path, [], $data, '127.0.0.1', $this->session));
    }
}
