<?php

declare(strict_types=1);

namespace App\Core;

final class Router
{
    /** @var array<int, array{method:string, path:string, handler:callable|array{0:class-string,1:string}}> */
    private array $routes = [];

    public function get(string $path, callable|array $handler): void
    {
        $this->add('GET', $path, $handler);
    }

    public function post(string $path, callable|array $handler): void
    {
        $this->add('POST', $path, $handler);
    }

    public function add(string $method, string $path, callable|array $handler): void
    {
        $normalized = $path === '/' ? '/' : '/' . trim($path, '/');
        $this->routes[] = [
            'method' => strtoupper($method),
            'path' => $normalized,
            'handler' => $handler,
        ];
    }

    public function dispatch(Request $request): void
    {
        $method = $request->method();
        $path = $request->path();

        foreach ($this->routes as $route) {
            if ($route['method'] !== $method || $route['path'] !== $path) {
                continue;
            }

            $handler = $route['handler'];
            if (is_array($handler)) {
                [$class, $action] = $handler;
                $controller = new $class();
                $controller->{$action}($request);
                return;
            }

            $handler($request);
            return;
        }

        $error = new \App\Controllers\ErrorController();
        $error->notFound($request);
    }
}
