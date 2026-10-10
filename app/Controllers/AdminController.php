<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Services\AdminService;

/** Кабинет администратора: пользователи, подразделения, автомобили. */
final class AdminController extends Controller
{
    public function index(Request $request): Response
    {
        $this->app->auth->require('manage_users');
        return $this->render('admin/index', [
            'users' => $this->app->admin->users(),
            'departments' => $this->app->admin->departments(),
            'vehicles' => $this->app->admin->vehicles(),
            'roles' => AdminService::ROLE_LABELS,
        ]);
    }

    public function createUser(Request $request): Response
    {
        $admin = $this->app->auth->require('manage_users');
        return $this->attempt(function () use ($admin, $request) {
            $r = $this->app->admin->createUser((int) $admin['id'], $request->post);
            return 'Пользователь «' . $request->input('login') . '» создан. Временный пароль: ' . $r['password'] . ' — передайте его сотруднику, при первом входе пароль нужно сменить.';
        }, '/admin');
    }

    public function resetPassword(Request $request): Response
    {
        $admin = $this->app->auth->require('manage_users');
        return $this->attempt(function () use ($admin, $request) {
            $password = $this->app->admin->resetPassword((int) $admin['id'], $this->id($request));
            return "Пароль сброшен. Новый временный пароль: $password";
        }, '/admin');
    }

    public function toggleUser(Request $request): Response
    {
        $admin = $this->app->auth->require('manage_users');
        return $this->attempt(function () use ($admin, $request) {
            $this->app->admin->toggleUser((int) $admin['id'], $this->id($request));
            return 'Статус пользователя изменён.';
        }, '/admin');
    }

    public function createDepartment(Request $request): Response
    {
        $admin = $this->app->auth->require('manage_departments');
        return $this->attempt(function () use ($admin, $request) {
            $this->app->admin->createDepartment((int) $admin['id'], (string) $request->input('name', ''));
            return 'Подразделение добавлено.';
        }, '/admin');
    }

    public function createVehicle(Request $request): Response
    {
        $admin = $this->app->auth->require('manage_vehicles');
        return $this->attempt(function () use ($admin, $request) {
            $this->app->admin->createVehicle((int) $admin['id'], $request->post);
            return 'Автомобиль добавлен.';
        }, '/admin');
    }
}
