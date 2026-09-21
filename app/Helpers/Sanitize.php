<?php

declare(strict_types=1);

namespace App\Helpers;

final class Sanitize
{
    public static function nickname(string $value): string
    {
        $value = trim($value);
        $value = preg_replace('/\s+/u', ' ', $value) ?? '';
        $value = strip_tags($value);
        $value = preg_replace('/[^\p{L}\p{N} _\-.]/u', '', $value) ?? '';
        return mb_substr($value, 0, 24);
    }

    public static function channel(string $value): string
    {
        $value = trim($value);
        $value = mb_strtolower($value);
        $value = preg_replace('/\s+/u', '-', $value) ?? '';
        $value = preg_replace('/[^a-z0-9\-_]/', '', $value) ?? '';
        $value = preg_replace('/-+/', '-', $value) ?? '';
        $value = trim($value, '-_');
        return mb_substr($value, 0, 64);
    }

    public static function peerId(string $value): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-f0-9]{16,32}$/', $value)) {
            return '';
        }
        return $value;
    }
}
