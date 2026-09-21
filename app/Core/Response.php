<?php

declare(strict_types=1);

namespace App\Core;

final class Response
{
    public static function html(string $html, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        echo $html;
    }

    /** @param array<string, mixed> $data */
    public static function json(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function redirect(string $path, int $status = 302): void
    {
        $location = str_starts_with($path, 'http') ? $path : App::url($path);
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        http_response_code($status);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Location: ' . $location);
        exit;
    }

    public static function text(string $body, string $contentType, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: ' . $contentType);
        echo $body;
    }

    public static function deny(int $status = 403): void
    {
        http_response_code($status);
        echo 'Forbidden';
    }
}
