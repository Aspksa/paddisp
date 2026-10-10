<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\Request;
use App\Core\Response;

/** Автопарк и занятость на день — доступно всем авторизованным, только просмотр. */
final class FleetController extends Controller
{
    public function index(Request $request): Response
    {
        $this->app->auth->require('view_fleet');
        $date = (string) $request->input('date', '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int) substr($date, 5, 2), (int) substr($date, 8, 2), (int) substr($date, 0, 4))) {
            $date = Clock::now()->format('Y-m-d');
        }
        return $this->render('fleet/index', [
            'date' => $date,
            'vehicles' => $this->app->fleet->overview($date),
            'canManage' => $this->app->auth->can('manage_vehicle_status'),
        ]);
    }
}
