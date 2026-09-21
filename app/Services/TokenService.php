<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;

final class TokenService
{
    public function issue(string $peerId, string $nickname, string $channel): string
    {
        $ttl = Env::int('SIGNAL_TOKEN_TTL', 14400);
        $payload = [
            'peerId' => $peerId,
            'nickname' => $nickname,
            'channel' => $channel,
            'exp' => time() + $ttl,
        ];

        $encoded = self::b64(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        return $encoded . '.' . self::sign($encoded);
    }

    /** @return array{peerId:string,nickname:string,channel:string,exp:int}|null */
    public function verify(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 2) {
            return null;
        }

        [$encoded, $signature] = $parts;
        if (!hash_equals(self::sign($encoded), $signature)) {
            return null;
        }

        $json = self::unb64($encoded);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return null;
        }

        $peerId = is_string($data['peerId'] ?? null) ? $data['peerId'] : '';
        $nickname = is_string($data['nickname'] ?? null) ? $data['nickname'] : '';
        $channel = is_string($data['channel'] ?? null) ? $data['channel'] : '';
        $exp = isset($data['exp']) ? (int) $data['exp'] : 0;

        if ($peerId === '' || $nickname === '' || $channel === '') {
            return null;
        }

        if ($exp < time()) {
            return null;
        }

        return [
            'peerId' => $peerId,
            'nickname' => $nickname,
            'channel' => $channel,
            'exp' => $exp,
        ];
    }

    private static function sign(string $encoded): string
    {
        $secret = (string) Env::get('SIGNAL_SECRET', '');
        if ($secret === '' || $secret === 'CHANGE_THIS_SECRET') {
            LoggerService::error('SIGNAL_SECRET is missing or still using the example value.');
        }

        return self::b64(hash_hmac('sha256', $encoded, $secret, true));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $encoded): string
    {
        $remainder = strlen($encoded) % 4;
        if ($remainder > 0) {
            $encoded .= str_repeat('=', 4 - $remainder);
        }

        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
        return $decoded === false ? '' : $decoded;
    }
}
