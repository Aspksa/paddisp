<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Clock;
use App\Core\DomainError;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;
use DateTimeImmutable;

/** Кабинет диспетчера: очередь заявок, подтверждение, переназначение, ремонты. */
final class DispatchController extends Controller
{
    public function index(Request $request): Response
    {
        $this->app->auth->require('view_all_requests');
        $tab = (string) $request->input('tab', 'queue');
        $statuses = match ($tab) {
            'approved' => ['approved'],
            'closed' => ['rejected', 'cancelled', 'completed'],
            default => ['submitted', 'under_review'],
        };
        return $this->render('dispatch/index', [
            'tab' => $tab,
            'requests' => $this->app->requests->listByStatus($statuses, $tab === 'closed' ? 'DESC' : 'ASC'),
        ]);
    }

    public function show(Request $request): Response
    {
        $this->app->auth->require('view_all_requests');
        $id = $this->id($request);
        $r = $this->app->requests->find($id);
        if ($r === null) {
            throw new HttpException(404);
        }
        $passengers = $r['passenger_count'] !== null ? (int) $r['passenger_count'] : null;
        return $this->render('dispatch/show', [
            'r' => $r,
            'assignment' => $this->app->requests->currentAssignment($id),
            'vehicles' => $this->app->availability->vehicleOptions($r['starts_at'], $r['ends_at'], $id, $passengers),
            'drivers' => $this->app->availability->driverOptions($r['starts_at'], $r['ends_at'], $id),
            'history' => $this->app->audit->latest(50, 'request', $id),
        ]);
    }

    public function review(Request $request): Response
    {
        $user = $this->app->auth->require('approve_request');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id) {
            $this->app->requests->takeForReview($user, $id);
            return "Заявка №$id взята в работу.";
        }, "/dispatch/requests/$id");
    }

    public function approve(Request $request): Response
    {
        $user = $this->app->auth->require('approve_request');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id, $request) {
            $this->app->requests->approve($user, $id, (int) $request->int('vehicle_id'), (int) $request->int('driver_id'));
            return "Заявка №$id подтверждена. Заказчик и водитель получили уведомления.";
        }, "/dispatch/requests/$id");
    }

    public function reject(Request $request): Response
    {
        $user = $this->app->auth->require('reject_request');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id, $request) {
            $this->app->requests->reject($user, $id, (string) $request->input('decision_reason', ''));
            return "Заявка №$id отклонена.";
        }, "/dispatch/requests/$id");
    }

    public function reassign(Request $request): Response
    {
        $user = $this->app->auth->require('reschedule_request');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id, $request) {
            $start = $this->dateTimeInput((string) $request->input('starts_at', ''));
            $end = $this->dateTimeInput((string) $request->input('ends_at', ''));
            $this->app->requests->reassign($user, $id, (int) $request->int('vehicle_id'), (int) $request->int('driver_id'), $start, $end);
            return "Назначение по заявке №$id изменено. Заказчик и водитель уведомлены.";
        }, "/dispatch/requests/$id");
    }

    public function cancel(Request $request): Response
    {
        $user = $this->app->auth->require('reschedule_request');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id, $request) {
            $this->app->requests->cancelByDispatcher($user, $id, (string) $request->input('decision_reason', ''));
            return "Заявка №$id отменена.";
        }, "/dispatch/requests/$id");
    }

    public function vehicle(Request $request): Response
    {
        $this->app->auth->require('manage_vehicle_status');
        $v = $this->app->fleet->find($this->id($request));
        if ($v === null) {
            throw new HttpException(404);
        }
        return $this->render('dispatch/vehicle', [
            'v' => $v,
            'maintenance' => $this->app->fleet->maintenanceFor((int) $v['id']),
            'history' => $this->app->audit->latest(30, 'vehicle', (int) $v['id']),
        ]);
    }

    public function vehicleStatus(Request $request): Response
    {
        $user = $this->app->auth->require('manage_vehicle_status');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id, $request) {
            $this->app->fleet->setStatus((int) $user['id'], $id, (string) $request->input('status', ''));
            return 'Статус автомобиля обновлён.';
        }, "/dispatch/vehicles/$id");
    }

    public function addMaintenance(Request $request): Response
    {
        $user = $this->app->auth->require('manage_vehicle_status');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id, $request) {
            $this->app->fleet->addMaintenance(
                (int) $user['id'],
                $id,
                (string) $request->input('starts_at', ''),
                (string) $request->input('ends_at', ''),
                (string) $request->input('reason', ''),
            );
            return 'Период ремонта добавлен.';
        }, "/dispatch/vehicles/$id");
    }

    public function deleteMaintenance(Request $request): Response
    {
        $user = $this->app->auth->require('manage_vehicle_status');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id, $request) {
            $this->app->fleet->deleteMaintenance((int) $user['id'], $id, $this->id($request, 'mid'));
            return 'Период ремонта удалён.';
        }, "/dispatch/vehicles/$id");
    }

    public function audit(Request $request): Response
    {
        $this->app->auth->require('view_audit_log');
        return $this->render('dispatch/audit', ['entries' => $this->app->audit->latest(300)]);
    }

    /** Значение input[type=datetime-local] → DATETIME; пустое — без изменений. */
    private function dateTimeInput(string $value): ?string
    {
        if ($value === '') {
            return null;
        }
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $value, Clock::timezone());
        if ($dt === false) {
            throw new ValidationException(['starts_at' => 'Неверный формат даты и времени.']);
        }
        return $dt->format('Y-m-d H:i:s');
    }
}
