<?php

declare(strict_types=1);

namespace App\Core;

use App\Services\LoggerService;

final class App
{
    private static string $root = '';
    private static string $basePath = '';

    public static function boot(string $root): void
    {
        self::$root = $root;
        Env::load($root . DIRECTORY_SEPARATOR . '.env');
        self::$basePath = self::detectBasePath();

        $debug = Env::bool('APP_DEBUG', false);
        ini_set('display_errors', $debug ? '1' : '0');
        ini_set('log_errors', '1');
        error_reporting(E_ALL);

        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
            || str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https');

        self::registerHandlers($debug);
        Session::start($https);

        if (!headers_sent()) {
            header('X-Content-Type-Options: nosniff');
            header('Referrer-Policy: same-origin');
            header('X-Frame-Options: SAMEORIGIN');
        }
    }

    private static function detectBasePath(): string
    {
        $configured = trim((string) Env::get('APP_BASE_PATH', ''), '/');
        if ($configured !== '') {
            return '/' . $configured;
        }

        $script = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
        if ($script === '/' || $script === '\\' || $script === '.' || $script === '') {
            return '';
        }

        return rtrim($script, '/');
    }

    private static function registerHandlers(bool $debug): void
    {
        set_exception_handler(static function (\Throwable $e) use ($debug): void {
            LoggerService::error('Uncaught exception', [
                'message' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
            ]);

            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: text/html; charset=UTF-8');
            }

            $controller = new \App\Controllers\ErrorController();
            $controller->serverError($debug ? $e->getMessage() : null);
        });

        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            if (!(error_reporting() & $severity)) {
                return false;
            }
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });
    }

    public static function root(): string
    {
        return self::$root;
    }

    public static function basePath(): string
    {
        return self::$basePath;
    }

    public static function url(string $path = ''): string
    {
        $path = ltrim($path, '/');
        $base = self::$basePath;
        if ($path === '') {
            return $base !== '' ? $base . '/' : '/';
        }
        return ($base !== '' ? $base : '') . '/' . $path;
    }

    public static function asset(string $path): string
    {
        $relative = ltrim($path, '/');
        $url = self::url('public/assets/' . $relative);
        $file = self::$root . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $version = is_file($file) ? (string) filemtime($file) : '1';
        return $url . (str_contains($url, '?') ? '&' : '?') . 'v=' . $version;
    }

    public static function run(): void
    {
        $request = new Request();
        $router = new Router();

        $routes = require self::$root . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'routes.php';
        if (is_callable($routes)) {
            $routes($router);
        }

        $router->dispatch($request);
    }
}
