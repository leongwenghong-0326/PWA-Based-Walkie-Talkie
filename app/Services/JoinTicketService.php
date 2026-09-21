<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;

final class JoinTicketService
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (App::root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'tickets');
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0775, true);
        }
    }

    /** @param array{peer_id:string,nickname:string,channel:string,channel_raw:string,token:string} $payload */
    public function issue(array $payload): string
    {
        $this->gc();
        $id = bin2hex(random_bytes(16));
        $data = [
            'peer_id' => $payload['peer_id'],
            'nickname' => $payload['nickname'],
            'channel' => $payload['channel'],
            'channel_raw' => $payload['channel_raw'],
            'token' => $payload['token'],
            'exp' => time() + 120,
        ];
        $file = $this->file($id);
        file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
        return $id;
    }

    /** @return array{peer_id:string,nickname:string,channel:string,channel_raw:string,token:string}|null */
    public function consume(string $id): ?array
    {
        if (!preg_match('/^[a-f0-9]{32}$/', $id)) {
            return null;
        }

        $file = $this->file($id);
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        @unlink($file);
        if ($raw === false || $raw === '') {
            return null;
        }

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return null;
        }

        if ((int) ($data['exp'] ?? 0) < time()) {
            return null;
        }

        $peerId = is_string($data['peer_id'] ?? null) ? $data['peer_id'] : '';
        $nickname = is_string($data['nickname'] ?? null) ? $data['nickname'] : '';
        $channel = is_string($data['channel'] ?? null) ? $data['channel'] : '';
        $channelRaw = is_string($data['channel_raw'] ?? null) ? $data['channel_raw'] : $channel;
        $token = is_string($data['token'] ?? null) ? $data['token'] : '';

        if ($peerId === '' || $nickname === '' || $channel === '' || $token === '') {
            return null;
        }

        return [
            'peer_id' => $peerId,
            'nickname' => $nickname,
            'channel' => $channel,
            'channel_raw' => $channelRaw,
            'token' => $token,
        ];
    }

    private function file(string $id): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $id . '.json';
    }

    private function gc(): void
    {
        $files = glob($this->dir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $now = time();
        foreach ($files as $file) {
            if (!is_file($file)) {
                continue;
            }
            if (($now - (int) filemtime($file)) > 300) {
                @unlink($file);
            }
        }
    }
}
