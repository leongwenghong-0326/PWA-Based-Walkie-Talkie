<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

final class RateLimitService
{
    public function allow(string $bucket, string $ip, int $max, int $windowSeconds): bool
    {
        $dir = App::root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $key = hash('sha256', $bucket . '|' . $ip);
        $path = $dir . DIRECTORY_SEPARATOR . $key . '.json';
        $now = time();

        $handle = @fopen($path, 'c+');
        if ($handle === false) {
            return true;
        }

        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $data = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        $windowStart = (int) ($data['windowStart'] ?? $now);
        $count = (int) ($data['count'] ?? 0);

        if (($now - $windowStart) >= $windowSeconds) {
            $windowStart = $now;
            $count = 0;
        }

        $count++;
        $allowed = $count <= $max;

        $payload = json_encode([
            'windowStart' => $windowStart,
            'count' => $count,
        ], JSON_UNESCAPED_SLASHES);

        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) $payload);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $allowed;
    }
}
