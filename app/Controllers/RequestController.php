<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\DomainError;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

/** Кабинет сотрудника: свои заявки. */
final class RequestController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->app->auth->require('view_own_requests');
        return $this->render('requests/index', ['requests' => $this->app->requests->listForRequester((int) $user['id'])]);
    }

    public function create(Request $request): Response
    {
        $user = $this->app->auth->require('create_own_request');
        return $this->form(['department_id' => $user['department_id']], [], null);
    }

    public function store(Request $request): Response
    {
        $user = $this->app->auth->require('create_own_request');
        $submit = $request->input('action') !== 'draft';
        try {
            $result = $this->app->requests->create($user, $request->post, $submit);
        } catch (ValidationException $e) {
            return $this->form($request->post, $e->errors, null, 422);
        }
        foreach ($result['warnings'] as $w) {
            $this->app->session->flash('warning', $w);
        }
        return $this->redirect(
            '/requests/' . $result['id'],
            $submit ? 'Заявка №' . $result['id'] . ' подана. Машина будет закреплена после подтверждения диспетчером.' : 'Черновик сохранён.'
        );
    }

    public function show(Request $request): Response
    {
        $user = $this->app->auth->requireUser();
        $id = $this->id($request);
        $r = $this->app->requests->find($id);
        if ($r === null) {
            throw new HttpException(404);
        }
        $assignment = $this->app->requests->currentAssignment($id);
        $isOwner = (int) $r['requester_id'] === (int) $user['id'];
        $isDriver = $assignment !== null && (int) $assignment['driver_user_id'] === (int) $user['id'];
        if (!$isOwner && !$isDriver && !$this->app->auth->can('view_all_requests')) {
            throw new HttpException(404);
        }
        return $this->render('requests/show', [
            'r' => $r,
            'assignment' => $assignment,
            'isOwner' => $isOwner,
            'history' => $this->app->auth->can('view_audit_log') || $isOwner ? $this->app->audit->latest(50, 'request', $id) : [],
        ]);
    }

    public function edit(Request $request): Response
    {
        $user = $this->app->auth->require('create_own_request');
        $r = $this->ownDraft($user, $this->id($request));
        $values = $r + [
            'start_time' => substr($r['starts_at'], 11, 5),
            'end_time' => substr($r['ends_at'], 11, 5),
            'end_date' => substr($r['ends_at'], 0, 10),
        ];
        return $this->form($values, [], (int) $r['id']);
    }

    public function update(Request $request): Response
    {
        $user = $this->app->auth->require('create_own_request');
        $id = $this->id($request);
        $this->ownDraft($user, $id);
        try {
            $warnings = $this->app->requests->updateDraft($user, $id, $request->post);
            if ($request->input('action') !== 'draft') {
                $warnings = $this->app->requests->submit($user, $id);
            }
        } catch (ValidationException $e) {
            return $this->form($request->post, $e->errors, $id, 422);
        } catch (DomainError $e) {
            return $this->redirect("/requests/$id", $e->getMessage(), 'error');
        }
        foreach ($warnings as $w) {
            $this->app->session->flash('warning', $w);
        }
        return $this->redirect("/requests/$id", $request->input('action') !== 'draft' ? "Заявка №$id подана." : 'Черновик сохранён.');
    }

    public function submit(Request $request): Response
    {
        $user = $this->app->auth->require('create_own_request');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id) {
            foreach ($this->app->requests->submit($user, $id) as $w) {
                $this->app->session->flash('warning', $w);
            }
            return "Заявка №$id подана.";
        }, "/requests/$id");
    }

    public function cancel(Request $request): Response
    {
        $user = $this->app->auth->require('view_own_requests');
        $id = $this->id($request);
        return $this->attempt(function () use ($user, $id) {
            $this->app->requests->cancelOwn($user, $id);
            return "Заявка №$id отменена.";
        }, "/requests/$id");
    }

    /** @param array<string,mixed> $user @return array<string,mixed> */
    private function ownDraft(array $user, int $id): array
    {
        $r = $this->app->requests->find($id);
        if ($r === null || (int) $r['requester_id'] !== (int) $user['id']) {
            throw new HttpException(404);
        }
        if ($r['status'] !== 'draft') {
            throw new HttpException(403);
        }
        return $r;
    }

    /**
     * @param array<string,mixed> $values
     * @param array<string,string> $errors
     */
    private function form(array $values, array $errors, ?int $id, int $status = 200): Response
    {
        return $this->render('requests/form', [
            'values' => $values,
            'errors' => $errors,
            'id' => $id,
            'departments' => $this->app->admin->departments(true),
            'vehicles' => $this->app->fleet->activeVehicles(),
        ], $status);
    }
}
