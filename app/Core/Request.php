<?php

declare(strict_types=1);

namespace App\Core;

final class Request
{
    private string $method;
    private string $path;
    /** @var array<string, mixed> */
    private array $query;
    /** @var array<string, mixed> */
    private array $body;
    private string $rawBody;

    public function __construct()
    {
        $this->method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $this->query = $_GET;
        $this->rawBody = file_get_contents('php://input') ?: '';
        $this->body = $_POST;

        $contentType = strtolower($_SERVER['CONTENT_TYPE'] ?? '');
        if (str_contains($contentType, 'application/json') && $this->rawBody !== '') {
            $decoded = json_decode($this->rawBody, true);
            if (is_array($decoded)) {
                $this->body = $decoded;
            }
        }

        $this->path = $this->resolvePath();
    }

    private function resolvePath(): string
    {
        if (isset($_GET['route']) && is_string($_GET['route']) && $_GET['route'] !== '') {
            return '/' . trim($_GET['route'], '/');
        }

        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if (!is_string($uri) || $uri === '') {
            $uri = '/';
        }

        $base = App::basePath();
        if ($base !== '' && str_starts_with($uri, $base)) {
            $uri = substr($uri, strlen($base)) ?: '/';
        }

        $uri = '/' . trim($uri, '/');

        // Direct hits like /index.php must map to the home route "/".
        if ($uri === '/index.php') {
            return '/';
        }

        return $uri === '/' ? '/' : rtrim($uri, '/');
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function query(string $key, ?string $default = null): ?string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? $value : $default;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $this->query[$key] ?? $default;
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->input($key, $default);
        return is_scalar($value) ? trim((string) $value) : $default;
    }

    public function rawBody(): string
    {
        return $this->rawBody;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        return is_array($this->body) ? $this->body : [];
    }

    public function header(string $name): ?string
    {
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $_SERVER[$serverKey] ?? null;
        return is_string($value) ? $value : null;
    }

    public function ip(): string
    {
        $candidates = [
            $_SERVER['HTTP_CF_CONNECTING_IP'] ?? null,
            $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            $_SERVER['REMOTE_ADDR'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (!is_string($candidate) || $candidate === '') {
                continue;
            }
            $first = trim(explode(',', $candidate)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) {
                return $first;
            }
        }

        return '0.0.0.0';
    }

    public function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }

        $forwarded = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        if ($forwarded === 'https') {
            return true;
        }

        return str_contains((string) ($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https');
    }

    public function publicUrl(): string
    {
        $scheme = $this->isHttps() ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
        $base = App::basePath();
        return $scheme . '://' . $host . $base . '/';
    }
}
