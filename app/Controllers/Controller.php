<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\App;
use App\Core\DomainError;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

abstract class Controller
{
    public function __construct(protected App $app)
    {
    }

    /** @param array<string,mixed> $data */
    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return $this->app->render($template, $data, $status);
    }

    protected function redirect(string $to, ?string $message = null, string $type = 'success'): Response
    {
        if ($message !== null) {
            $this->app->session->flash($type, $message);
        }
        return Response::redirect($to);
    }

    /**
     * Выполняет действие и превращает ошибки бизнес-правил в сообщение на странице.
     *
     * @param callable():(string|null) $action возвращает текст об успехе
     */
    protected function attempt(callable $action, string $redirectTo): Response
    {
        try {
            $message = $action();
            return $this->redirect($redirectTo, $message);
        } catch (DomainError $e) {
            return $this->redirect($redirectTo, $e->getMessage(), 'error');
        } catch (ValidationException $e) {
            return $this->redirect($redirectTo, implode(' ', $e->errors), 'error');
        }
    }

    protected function id(Request $request, string $key = 'id'): int
    {
        return $request->param($key);
    }
}
