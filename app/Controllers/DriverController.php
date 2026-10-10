<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

/** Кабинет водителя. */
final class DriverController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->app->auth->require('view_own_assignments');
        return $this->render('driver/index', [
            'current' => $this->app->trips->assignmentsForUser((int) $user['id']),
            'history' => $this->app->trips->assignmentsForUser((int) $user['id'], true),
        ]);
    }

    public function acknowledge(Request $request): Response
    {
        $user = $this->app->auth->require('acknowledge_assignment');
        return $this->attempt(function () use ($user, $request) {
            $this->app->trips->acknowledge((int) $user['id'], $this->id($request));
            return 'Получение рейса подтверждено.';
        }, '/driver');
    }

    public function start(Request $request): Response
    {
        $user = $this->app->auth->require('start_own_trip');
        return $this->attempt(function () use ($user, $request) {
            $this->app->trips->start((int) $user['id'], $this->id($request));
            return 'Рейс начат.';
        }, '/driver');
    }

    public function finish(Request $request): Response
    {
        $user = $this->app->auth->require('finish_own_trip');
        return $this->attempt(function () use ($user, $request) {
            $this->app->trips->finish((int) $user['id'], $this->id($request));
            return 'Рейс завершён.';
        }, '/driver');
    }
}
