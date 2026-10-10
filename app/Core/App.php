<?php

declare(strict_types=1);

namespace App\Core;

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\DispatchController;
use App\Controllers\DriverController;
use App\Controllers\FleetController;
use App\Controllers\NotificationController;
use App\Controllers\RequestController;
use App\Services\AdminService;
use App\Services\AuditLog;
use App\Services\AuthService;
use App\Services\AvailabilityService;
use App\Services\FleetService;
use App\Services\NotificationService;
use App\Services\RequestService;
use App\Services\TripService;
use Throwable;

/** Сборка зависимостей и обработка одного HTTP-запроса. */
final class App
{
    public readonly AuditLog $audit;
    public readonly NotificationService $notifications;
    public readonly AvailabilityService $availability;
    public readonly RequestService $requests;
    public readonly TripService $trips;
    public readonly FleetService $fleet;
    public readonly AdminService $admin;
    public readonly AuthService $authService;
    public readonly View $view;

    public Auth $auth;
    public Session $session;

    public function __construct(public readonly Config $config, public readonly Database $db)
    {
        $this->audit = new AuditLog($db);
        $this->notifications = new NotificationService($db);
        $this->availability = new AvailabilityService($db);
        $this->requests = new RequestService($db, $this->availability, $this->notifications, $this->audit);
        $this->trips = new TripService($db, $this->notifications, $this->audit);
        $this->fleet = new FleetService($db, $this->audit);
        $this->admin = new AdminService($db, $this->audit);
        $this->authService = new AuthService($db, $config->get('app.login'));
        $this->view = new View(BASE_PATH . '/app/Views');
    }

    public function handle(Request $request): Response
    {
        $this->session = $request->session;
        $this->auth = new Auth($this->db, $request->session, $this->config->get('permissions'));

        try {
            if ($request->method === 'POST' && !hash_equals($request->session->csrfToken(), (string) ($request->post['_csrf'] ?? ''))) {
                throw new HttpException(419);
            }
            $user = $this->auth->user();
            if ($user !== null && (int) $user['must_change_password'] && !in_array($request->path, ['/password', '/logout'], true)) {
                return Response::redirect('/password');
            }
            return $this->router()->dispatch($request);
        } catch (HttpException $e) {
            if ($e->status === 401) {
                return Response::redirect('/login');
            }
            return $this->errorPage($e->status);
        } catch (Throwable $e) {
            error_log((string) $e);
            if ($this->config->get('app.debug')) {
                return new Response('<pre>' . e((string) $e) . '</pre>', 500);
            }
            return $this->errorPage(500);
        }
    }

    /** @param array<string,mixed> $data */
    public function render(string $template, array $data = [], int $status = 200): Response
    {
        $user = $this->auth->user();
        $data += [
            'user' => $user,
            'auth' => $this->auth,
            'csrf' => $this->session->csrfToken(),
            'flashes' => $this->session->takeFlashes(),
            'unread' => $user ? $this->notifications->unreadCount((int) $user['id']) : 0,
            'appName' => $this->config->get('app.name'),
        ];
        return new Response($this->view->render($template, $data), $status);
    }

    private function errorPage(int $status): Response
    {
        $titles = [
            403 => 'Недостаточно прав',
            404 => 'Страница не найдена',
            405 => 'Метод не поддерживается',
            419 => 'Страница устарела — обновите её и повторите действие',
            500 => 'Внутренняя ошибка',
        ];
        try {
            return $this->render('errors/error', ['status' => $status, 'title' => $titles[$status] ?? 'Ошибка'], $status);
        } catch (Throwable) {
            return new Response(e($titles[$status] ?? 'Ошибка'), $status);
        }
    }

    private function router(): Router
    {
        $r = new Router();
        $auth = new AuthController($this);
        $fleet = new FleetController($this);
        $req = new RequestController($this);
        $disp = new DispatchController($this);
        $drv = new DriverController($this);
        $notif = new NotificationController($this);
        $adm = new AdminController($this);

        $r->get('/', [$auth, 'home']);
        $r->get('/login', [$auth, 'loginForm']);
        $r->post('/login', [$auth, 'login']);
        $r->post('/logout', [$auth, 'logout']);
        $r->get('/password', [$auth, 'passwordForm']);
        $r->post('/password', [$auth, 'changePassword']);

        $r->get('/fleet', [$fleet, 'index']);

        $r->get('/requests', [$req, 'index']);
        $r->get('/requests/new', [$req, 'create']);
        $r->post('/requests', [$req, 'store']);
        $r->get('/requests/{id}', [$req, 'show']);
        $r->get('/requests/{id}/edit', [$req, 'edit']);
        $r->post('/requests/{id}', [$req, 'update']);
        $r->post('/requests/{id}/submit', [$req, 'submit']);
        $r->post('/requests/{id}/cancel', [$req, 'cancel']);

        $r->get('/dispatch', [$disp, 'index']);
        $r->get('/dispatch/requests/{id}', [$disp, 'show']);
        $r->post('/dispatch/requests/{id}/review', [$disp, 'review']);
        $r->post('/dispatch/requests/{id}/approve', [$disp, 'approve']);
        $r->post('/dispatch/requests/{id}/reject', [$disp, 'reject']);
        $r->post('/dispatch/requests/{id}/reassign', [$disp, 'reassign']);
        $r->post('/dispatch/requests/{id}/cancel', [$disp, 'cancel']);
        $r->get('/dispatch/vehicles/{id}', [$disp, 'vehicle']);
        $r->post('/dispatch/vehicles/{id}/status', [$disp, 'vehicleStatus']);
        $r->post('/dispatch/vehicles/{id}/maintenance', [$disp, 'addMaintenance']);
        $r->post('/dispatch/vehicles/{id}/maintenance/{mid}/delete', [$disp, 'deleteMaintenance']);
        $r->get('/dispatch/audit', [$disp, 'audit']);

        $r->get('/driver', [$drv, 'index']);
        $r->post('/driver/assignments/{id}/ack', [$drv, 'acknowledge']);
        $r->post('/driver/assignments/{id}/start', [$drv, 'start']);
        $r->post('/driver/assignments/{id}/finish', [$drv, 'finish']);

        $r->get('/notifications', [$notif, 'index']);
        $r->post('/notifications/read-all', [$notif, 'readAll']);
        $r->post('/notifications/{id}/read', [$notif, 'read']);

        $r->get('/admin', [$adm, 'index']);
        $r->post('/admin/users', [$adm, 'createUser']);
        $r->post('/admin/users/{id}/reset-password', [$adm, 'resetPassword']);
        $r->post('/admin/users/{id}/toggle', [$adm, 'toggleUser']);
        $r->post('/admin/departments', [$adm, 'createDepartment']);
        $r->post('/admin/vehicles', [$adm, 'createVehicle']);

        return $r;
    }
}
