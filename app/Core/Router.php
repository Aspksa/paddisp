<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var list<array{string,string,callable}> */
    private array $routes = [];

    public function get(string $pattern, callable $handler): void
    {
        $this->routes[] = ['GET', $pattern, $handler];
    }

    public function post(string $pattern, callable $handler): void
    {
        $this->routes[] = ['POST', $pattern, $handler];
    }

    public function dispatch(Request $request): Response
    {
        $methodMismatch = false;
        foreach ($this->routes as [$method, $pattern, $handler]) {
            $regex = '#^' . preg_replace('#\{(\w+)\}#', '(?P<$1>\d+)', $pattern) . '$#';
            if (!preg_match($regex, $request->path, $m)) {
                continue;
            }
            if ($method !== $request->method) {
                $methodMismatch = true;
                continue;
            }
            $request->params = array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY);
            return $handler($request);
        }
        throw new HttpException($methodMismatch ? 405 : 404);
    }
}
