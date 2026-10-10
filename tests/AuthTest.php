<?php

declare(strict_types=1);

namespace Tests;

final class AuthTest extends TestCase
{
    public function testAnonymousCannotAccessInternalScreens(): void
    {
        $client = $this->client();
        foreach (['/', '/requests', '/requests/new', '/dispatch', '/driver', '/fleet', '/notifications', '/admin', '/dispatch/audit'] as $path) {
            $this->assertRedirect($client->get($path), '/login');
        }
        $this->assertRedirect($client->post('/requests', $this->requestInput()), '/login');
        $this->assertSame(0, $this->count('SELECT COUNT(*) FROM requests'));
    }

    public function testLoginWithCorrectPasswordAndRoleHome(): void
    {
        $client = $this->client();
        $this->assertSame(200, $client->get('/login')->status);
        $r = $client->post('/login', ['login' => 'Dispatcher', 'password' => self::PASSWORD]);
        $this->assertRedirect($r, '/');
        $this->assertSame($this->users['dispatcher'], $client->session->get('user_id'));
        $this->assertRedirect($client->get('/'), '/dispatch');
    }

    public function testWrongPasswordIsRejected(): void
    {
        $client = $this->client();
        $r = $client->post('/login', ['login' => 'employee', 'password' => 'nope']);
        $this->assertSame(422, $r->status);
        $this->assertContains('Неверный логин или пароль', $r->body);
        $this->assertSame(null, $client->session->get('user_id'));
    }

    public function testLockoutAfterRepeatedFailures(): void
    {
        $client = $this->client();
        for ($i = 0; $i < 5; $i++) {
            $client->post('/login', ['login' => 'employee', 'password' => 'wrong']);
        }
        $r = $client->post('/login', ['login' => 'employee', 'password' => self::PASSWORD]);
        $this->assertSame(429, $r->status);
        $this->assertSame(null, $client->session->get('user_id'));
        // Через окно блокировки вход снова возможен.
        \App\Core\Clock::freeze(new \DateTimeImmutable('2026-10-10 09:16:00', \App\Core\Clock::timezone()));
        $this->assertRedirect($client->post('/login', ['login' => 'employee', 'password' => self::PASSWORD]), '/');
    }

    public function testInactiveUserCannotLogIn(): void
    {
        self::$db->update('users', $this->users['employee'], ['active' => 0]);
        $r = $this->client()->post('/login', ['login' => 'employee', 'password' => self::PASSWORD]);
        $this->assertSame(422, $r->status);
    }

    public function testPostWithoutCsrfTokenIsRejected(): void
    {
        $client = $this->client('employee');
        $r = $client->post('/requests', $this->requestInput(), false);
        $this->assertSame(419, $r->status);
        $this->assertSame(0, $this->count('SELECT COUNT(*) FROM requests'));
    }

    public function testTemporaryPasswordMustBeChanged(): void
    {
        self::$db->update('users', $this->users['employee'], ['must_change_password' => 1]);
        $client = $this->client('employee');
        $this->assertRedirect($client->get('/requests'), '/password');
        $r = $client->post('/password', ['current_password' => self::PASSWORD, 'new_password' => 'new-password-123', 'new_password_confirm' => 'new-password-123']);
        $this->assertRedirect($r, '/');
        $this->assertSame(200, $client->get('/requests')->status);
        $this->assertTrue(password_verify('new-password-123', (string) self::$db->value('SELECT password_hash FROM users WHERE id = ?', [$this->users['employee']])));
    }

    public function testRolesAreEnforcedServerSide(): void
    {
        $this->assertSame(403, $this->client('employee')->get('/dispatch')->status);
        $this->assertSame(403, $this->client('employee')->get('/admin')->status);
        $this->assertSame(403, $this->client('driver1')->get('/dispatch')->status);
        $this->assertSame(403, $this->client('dispatcher')->get('/admin')->status);
        $this->assertSame(403, $this->client('dispatcher')->get('/requests/new')->status);
        $this->assertSame(403, $this->client('employee')->post('/admin/users', ['login' => 'hacker', 'full_name' => 'X', 'role' => 'admin'])->status);
        $this->assertSame(0, $this->count("SELECT COUNT(*) FROM users WHERE login = 'hacker'"));
    }

    public function testAdminCreatesUserWithTemporaryPassword(): void
    {
        $result = $this->app->admin->createUser($this->users['admin'], ['login' => 'newdriver', 'full_name' => 'Новый водитель', 'role' => 'driver']);
        $u = self::$db->one('SELECT * FROM users WHERE id = ?', [$result['id']]);
        $this->assertSame(1, (int) $u['must_change_password']);
        $this->assertTrue(password_verify($result['password'], $u['password_hash']));
        $this->assertSame(1, $this->count('SELECT COUNT(*) FROM drivers WHERE user_id = ?', [$result['id']]));
    }
}
