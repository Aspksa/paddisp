<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DomainError;
use App\Core\Request;
use App\Core\Response;

final class AuthController extends Controller
{
    /** Стартовая страница — кабинет по роли. */
    public function home(Request $request): Response
    {
        $user = $this->app->auth->requireUser();
        return Response::redirect(match ($user['role']) {
            'dispatcher' => '/dispatch',
            'driver' => '/driver',
            'admin' => '/admin',
            default => '/requests',
        });
    }

    public function loginForm(Request $request): Response
    {
        if ($this->app->auth->user() !== null) {
            return Response::redirect('/');
        }
        return $this->render('auth/login', ['login' => '', 'error' => null]);
    }

    public function login(Request $request): Response
    {
        $login = (string) $request->input('login', '');
        $password = (string) ($request->post['password'] ?? '');
        try {
            $user = $this->app->authService->attempt($login, $password, $request->ip);
        } catch (DomainError $e) {
            return $this->render('auth/login', ['login' => $login, 'error' => $e->getMessage()], 429);
        }
        if ($user === null) {
            return $this->render('auth/login', ['login' => $login, 'error' => 'Неверный логин или пароль.'], 422);
        }
        $this->app->auth->login($user);
        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        $this->app->auth->logout();
        return Response::redirect('/login');
    }

    public function passwordForm(Request $request): Response
    {
        $user = $this->app->auth->requireUser();
        return $this->render('auth/password', ['forced' => (bool) (int) $user['must_change_password'], 'error' => null]);
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->app->auth->requireUser();
        try {
            $this->app->authService->changePassword(
                (int) $user['id'],
                (string) ($request->post['current_password'] ?? ''),
                (string) ($request->post['new_password'] ?? ''),
                (string) ($request->post['new_password_confirm'] ?? ''),
            );
        } catch (DomainError $e) {
            return $this->render('auth/password', ['forced' => (bool) (int) $user['must_change_password'], 'error' => $e->getMessage()], 422);
        }
        return $this->redirect('/', 'Пароль изменён.');
    }
}
