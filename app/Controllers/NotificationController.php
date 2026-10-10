<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;

final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->app->auth->require('view_own_notifications');
        return $this->render('notifications/index', ['items' => $this->app->notifications->forUser((int) $user['id'])]);
    }

    public function read(Request $request): Response
    {
        $user = $this->app->auth->require('view_own_notifications');
        $link = (string) $this->app->notifications->markRead((int) $user['id'], $this->id($request));
        // Переход только по внутренним ссылкам.
        if ($link !== '' && preg_match('#^/[a-z0-9/_-]*$#i', $link) && !str_starts_with($link, '//')) {
            return Response::redirect($link);
        }
        return Response::redirect('/notifications');
    }

    public function readAll(Request $request): Response
    {
        $user = $this->app->auth->require('view_own_notifications');
        $this->app->notifications->markAllRead((int) $user['id']);
        return $this->redirect('/notifications', 'Все уведомления отмечены прочитанными.');
    }
}
