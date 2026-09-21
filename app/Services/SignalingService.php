<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Env;
use App\Helpers\Sanitize;

final class SignalingService
{
    public function __construct(
        private RoomService $rooms,
        private TokenService $tokens
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function handle(array $payload, int $bodyBytes): array
    {
        $maxBody = Env::int('SIGNAL_MAX_BODY', 32768);
        if ($bodyBytes > $maxBody) {
            return $this->fail('payload_too_large', 'The signaling request is too large.', 413);
        }

        $type = is_string($payload['type'] ?? null) ? $payload['type'] : '';
        $token = is_string($payload['token'] ?? null) ? $payload['token'] : '';
        $claims = $this->tokens->verify($token);

        if ($claims === null) {
            return $this->fail('expired', 'The communication session has expired.', 401);
        }

        $channel = $claims['channel'];
        $peerId = $claims['peerId'];
        $nickname = $claims['nickname'];

        return match ($type) {
            'hello' => $this->hello($channel, $peerId, $nickname),
            'poll' => $this->poll($channel, $peerId, (int) ($payload['lastSeq'] ?? 0)),
            'ptt_request' => $this->pttRequest($channel, $peerId),
            'ptt_release' => $this->pttRelease($channel, $peerId),
            'signal' => $this->signal($channel, $peerId, $payload),
            'leave' => $this->leave($channel, $peerId),
            default => $this->fail('bad_type', 'Unknown signaling message type.', 400),
        };
    }

    /** @return array<string, mixed> */
    private function hello(string $channel, string $peerId, string $nickname): array
    {
        $config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $joined = $this->rooms->join($channel, $peerId, $nickname);

        if (!$joined['ok']) {
            return [
                'ok' => false,
                'error' => $joined['code'] ?? 'join_failed',
                'message' => $joined['message'] ?? 'Unable to join this channel.',
            ];
        }

        return [
            'ok' => true,
            'peerId' => $peerId,
            'nickname' => $nickname,
            'channel' => $channel,
            'seq' => $joined['room']['seq'] ?? 0,
            'state' => $joined['room'],
            'iceServers' => $config['ice_servers'],
            'floorTimeoutMs' => $config['floor_timeout_ms'],
            'pollIntervalMs' => $config['poll_interval_ms'],
        ];
    }

    /** @return array<string, mixed> */
    private function poll(string $channel, string $peerId, int $lastSeq): array
    {
        $result = $this->rooms->poll($channel, $peerId, max(0, $lastSeq));
        $result['ok'] = true;
        return $result;
    }

    /** @return array<string, mixed> */
    private function pttRequest(string $channel, string $peerId): array
    {
        $result = $this->rooms->requestFloor($channel, $peerId);
        if ($result['granted']) {
            return [
                'ok' => true,
                'granted' => true,
                'speaker' => $result['speaker'],
                'timeoutMs' => $result['timeoutMs'],
                'message' => 'You have ' . (int) round(($result['timeoutMs'] ?? 30000) / 1000) . ' seconds to talk.',
            ];
        }

        $speaker = $result['speaker'];
        $name = is_array($speaker) ? (string) ($speaker['nickname'] ?? 'Someone') : 'Someone';

        return [
            'ok' => true,
            'granted' => false,
            'speaker' => $speaker,
            'message' => $result['message'] ?? ($name . ' is currently speaking'),
            'busy' => true,
        ];
    }

    /** @return array<string, mixed> */
    private function pttRelease(string $channel, string $peerId): array
    {
        $this->rooms->releaseFloor($channel, $peerId, 'released');
        return ['ok' => true, 'released' => true];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function signal(string $channel, string $peerId, array $payload): array
    {
        $to = Sanitize::peerId((string) ($payload['to'] ?? ''));
        $data = $payload['payload'] ?? null;
        if ($to === '' || !is_array($data)) {
            return $this->fail('bad_signal', 'Invalid WebRTC signaling data.', 400);
        }

        $ok = $this->rooms->addSignal($channel, $peerId, $to, $data);
        if (!$ok) {
            return $this->fail('payload_too_large', 'WebRTC signaling data is too large.', 413);
        }

        return ['ok' => true];
    }

    /** @return array<string, mixed> */
    private function leave(string $channel, string $peerId): array
    {
        $this->rooms->leave($channel, $peerId);
        return ['ok' => true, 'left' => true];
    }

    /** @return array<string, mixed> */
    private function fail(string $code, string $message, int $status): array
    {
        return [
            'ok' => false,
            'error' => $code,
            'message' => $message,
            '_status' => $status,
        ];
    }
}
