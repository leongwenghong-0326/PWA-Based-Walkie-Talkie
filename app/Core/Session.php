<?php

declare(strict_types=1);

namespace App\Core;

final class Session
{
    public static function start(bool $https): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.cookie_httponly', '1');
        ini_set('session.cookie_samesite', 'Lax');

        $base = App::basePath();
        // Prefer app base path so subfolder installs keep cookies; fall back to "/".
        $cookiePath = ($base !== '' ? $base : '') . '/';

        session_set_cookie_params([
            'lifetime' => 0,
            'path' => $cookiePath,
            'secure' => $https,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        session_name('walkie_sid');
        session_start();
    }

    public static function save(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
            // Re-open read-only is unnecessary; request is ending after redirect.
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function remove(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function has(string $key): bool
    {
        return array_key_exists($key, $_SESSION);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'] ?? '/', $params['domain'] ?? '', (bool) $params['secure'], (bool) $params['httponly']);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        $token = self::get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(16));
            self::set('_csrf', $token);
        }

        return $token;
    }

    public static function csrfMatches(?string $provided): bool
    {
        $stored = self::get('_csrf');
        if (!is_string($stored) || !is_string($provided) || $provided === '') {
            return false;
        }

        return hash_equals($stored, $provided);
    }
}
