<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\App;
use App\Core\Env;

final class RoomService
{
    /** @return array<string, mixed> */
    public function snapshot(string $channel): array
    {
        return $this->mutate($channel, static function (array &$room): array {
            return $room;
        });
    }

    /**
     * @return array{ok:bool,code?:string,message?:string,room?:array<string,mixed>,isNew?:bool}
     */
    public function join(string $channel, string $peerId, string $nickname): array
    {
        $maxPeers = Env::int('SIGNAL_MAX_PEERS', 8);

        return $this->mutate($channel, function (array &$room) use ($channel, $peerId, $nickname, $maxPeers): array {
            $this->cleanup($room);

            $isNew = !isset($room['peers'][$peerId]);
            if ($isNew && count($room['peers']) >= $maxPeers) {
                return [
                    'ok' => false,
                    'code' => 'room_full',
                    'message' => 'This channel is currently full. Maximum ' . $maxPeers . ' users are allowed.',
                ];
            }

            $room['peers'][$peerId] = [
                'nickname' => $nickname,
                'joinedAt' => $room['peers'][$peerId]['joinedAt'] ?? time(),
                'lastSeen' => time(),
            ];

            if ($isNew) {
                $this->pushEvent($room, 'peer_joined', [
                    'peerId' => $peerId,
                    'nickname' => $nickname,
                    'channel' => $channel,
                ]);
            }

            return [
                'ok' => true,
                'isNew' => $isNew,
                'room' => $this->publicState($room),
            ];
        });
    }

    public function heartbeat(string $channel, string $peerId): void
    {
        $this->mutate($channel, function (array &$room) use ($peerId): void {
            $this->cleanup($room);
            if (isset($room['peers'][$peerId])) {
                $room['peers'][$peerId]['lastSeen'] = time();
            }
        });
    }

    /**
     * @return array{ok:bool,granted:bool,speaker:?array<string,mixed>,message?:string,timeoutMs?:int}
     */
    public function requestFloor(string $channel, string $peerId): array
    {
        $timeoutMs = Env::int('SIGNAL_FLOOR_TIMEOUT_MS', 30000);

        return $this->mutate($channel, function (array &$room) use ($peerId, $timeoutMs): array {
            $this->cleanup($room);

            if (!isset($room['peers'][$peerId])) {
                return [
                    'ok' => false,
                    'granted' => false,
                    'speaker' => $this->currentSpeaker($room),
                    'message' => 'You are not in this channel.',
                ];
            }

            $nowMs = $this->nowMs();
            $speaker = $this->currentSpeaker($room);

            if ($speaker !== null && $speaker['peerId'] !== $peerId) {
                $this->pushEvent($room, 'ptt_denied', [
                    'peerId' => $peerId,
                    'speakerId' => $speaker['peerId'],
                    'speakerName' => $speaker['nickname'],
                ]);

                return [
                    'ok' => true,
                    'granted' => false,
                    'speaker' => $speaker,
                    'message' => $speaker['nickname'] . ' is currently speaking',
                ];
            }

            $nickname = (string) $room['peers'][$peerId]['nickname'];
            $room['speaker'] = [
                'peerId' => $peerId,
                'nickname' => $nickname,
                'grantedAt' => $nowMs,
                'expiresAt' => $nowMs + $timeoutMs,
            ];

            $this->pushEvent($room, 'ptt_granted', [
                'peerId' => $peerId,
                'nickname' => $nickname,
                'expiresAt' => $room['speaker']['expiresAt'],
                'timeoutMs' => $timeoutMs,
            ]);
            $this->pushEvent($room, 'speaker_started', [
                'peerId' => $peerId,
                'nickname' => $nickname,
                'expiresAt' => $room['speaker']['expiresAt'],
            ]);

            return [
                'ok' => true,
                'granted' => true,
                'speaker' => $room['speaker'],
                'timeoutMs' => $timeoutMs,
            ];
        });
    }

    public function releaseFloor(string $channel, string $peerId, string $reason = 'released'): void
    {
        $this->mutate($channel, function (array &$room) use ($peerId, $reason): void {
            $this->cleanup($room);
            $speaker = $this->currentSpeaker($room);
            if ($speaker === null) {
                return;
            }
            if ($speaker['peerId'] !== $peerId && $reason !== 'timeout' && $reason !== 'left') {
                return;
            }

            $room['speaker'] = null;
            $this->pushEvent($room, 'speaker_stopped', [
                'peerId' => $speaker['peerId'],
                'nickname' => $speaker['nickname'],
                'reason' => $reason,
            ]);
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function addSignal(string $channel, string $from, string $to, array $payload): bool
    {
        $maxData = Env::int('SIGNAL_MAX_DATA', 12288);
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES);
        if (!is_string($encoded) || strlen($encoded) > $maxData) {
            return false;
        }

        $this->mutate($channel, function (array &$room) use ($from, $to, $payload): void {
            $this->cleanup($room);
            if (!isset($room['peers'][$from])) {
                return;
            }
            if ($to !== '*' && !isset($room['peers'][$to])) {
                return;
            }

            // Per-peer mailboxes keep WebRTC offers/answers/ICE from being dropped
            // when many users negotiate at once (shared event ring is too small).
            $this->enqueueMailbox($room, $from, $to, $payload);
        });

        return true;
    }

    /**
     * @return array{ok:bool,seq:int,events:array<int,array<string,mixed>>,state:array<string,mixed>}
     */
    public function poll(string $channel, string $peerId, int $lastSeq): array
    {
        return $this->mutate($channel, function (array &$room) use ($peerId, $lastSeq): array {
            $this->cleanup($room);
            if (isset($room['peers'][$peerId])) {
                $room['peers'][$peerId]['lastSeen'] = time();
            }

            $events = [];
            foreach ($room['events'] as $event) {
                if ((int) $event['seq'] <= $lastSeq) {
                    continue;
                }
                // Legacy signal events may still exist in older room files.
                if (($event['type'] ?? '') === 'signal') {
                    $to = (string) ($event['to'] ?? '');
                    $from = (string) ($event['from'] ?? '');
                    if ($to !== '*' && $to !== $peerId) {
                        continue;
                    }
                    if ($from === $peerId) {
                        continue;
                    }
                }
                if (($event['type'] ?? '') === 'ptt_denied' && ($event['peerId'] ?? '') !== $peerId) {
                    continue;
                }
                if (($event['type'] ?? '') === 'ptt_granted' && ($event['peerId'] ?? '') !== $peerId) {
                    continue;
                }
                $events[] = $event;
            }

            foreach ($this->drainMailbox($room, $peerId) as $signal) {
                $events[] = $signal;
            }

            return [
                'ok' => true,
                'seq' => (int) $room['seq'],
                'events' => $events,
                'state' => $this->publicState($room),
            ];
        });
    }

    public function leave(string $channel, string $peerId): void
    {
        $this->mutate($channel, function (array &$room) use ($peerId): void {
            $this->cleanup($room);
            if (!isset($room['peers'][$peerId])) {
                return;
            }

            $nickname = (string) $room['peers'][$peerId]['nickname'];
            unset($room['peers'][$peerId]);
            if (isset($room['mailboxes'][$peerId])) {
                unset($room['mailboxes'][$peerId]);
            }

            $speaker = $this->currentSpeaker($room);
            if ($speaker !== null && $speaker['peerId'] === $peerId) {
                $room['speaker'] = null;
                $this->pushEvent($room, 'speaker_stopped', [
                    'peerId' => $peerId,
                    'nickname' => $nickname,
                    'reason' => 'left',
                ]);
            }

            $this->pushEvent($room, 'peer_left', [
                'peerId' => $peerId,
                'nickname' => $nickname,
            ]);
        });
    }

    public function occupancy(string $channel): int
    {
        $room = $this->snapshot($channel);
        return count($room['peers'] ?? []);
    }

    /**
     * @param callable(array<string,mixed>):mixed $callback
     */
    private function mutate(string $channel, callable $callback): mixed
    {
        $path = $this->filePath($channel);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open room storage.');
        }

        flock($handle, LOCK_EX);
        $raw = stream_get_contents($handle);
        $room = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($room)) {
            $room = $this->emptyRoom($channel);
        }

        $result = $callback($room);

        $json = json_encode($room, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, (string) $json);
        fflush($handle);
        flock($handle, LOCK_UN);
        fclose($handle);

        return $result;
    }

    /**
     * @param array<string, mixed> $room
     */
    private function cleanup(array &$room): void
    {
        $staleAfter = Env::int('SIGNAL_PEER_STALE_SECONDS', 20);
        $now = time();
        $removed = [];

        foreach ($room['peers'] as $peerId => $peer) {
            $lastSeen = (int) ($peer['lastSeen'] ?? 0);
            if (($now - $lastSeen) > $staleAfter) {
                $removed[$peerId] = (string) ($peer['nickname'] ?? 'Guest');
                unset($room['peers'][$peerId]);
                if (isset($room['mailboxes'][$peerId])) {
                    unset($room['mailboxes'][$peerId]);
                }
            }
        }

        foreach ($removed as $peerId => $nickname) {
            $this->pushEvent($room, 'peer_left', [
                'peerId' => $peerId,
                'nickname' => $nickname,
                'reason' => 'timeout',
            ]);
        }

        $speaker = $this->currentSpeaker($room);
        if ($speaker === null) {
            $room['speaker'] = null;
            return;
        }

        if (!isset($room['peers'][$speaker['peerId']])) {
            $room['speaker'] = null;
            $this->pushEvent($room, 'speaker_stopped', [
                'peerId' => $speaker['peerId'],
                'nickname' => $speaker['nickname'],
                'reason' => 'left',
            ]);
            return;
        }

        if ($this->nowMs() >= (int) $speaker['expiresAt']) {
            $room['speaker'] = null;
            $this->pushEvent($room, 'speaker_stopped', [
                'peerId' => $speaker['peerId'],
                'nickname' => $speaker['nickname'],
                'reason' => 'timeout',
            ]);
        }
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, mixed> $data
     */
    private function pushEvent(array &$room, string $type, array $data): void
    {
        $room['seq'] = (int) $room['seq'] + 1;
        $event = array_merge($data, [
            'seq' => $room['seq'],
            'type' => $type,
            'ts' => $this->nowMs(),
        ]);
        $room['events'][] = $event;

        if (count($room['events']) > 200) {
            $room['events'] = array_slice($room['events'], -200);
        }
    }

    /**
     * @param array<string, mixed> $room
     * @param array<string, mixed> $payload
     */
    private function enqueueMailbox(array &$room, string $from, string $to, array $payload): void
    {
        if (!isset($room['mailboxes']) || !is_array($room['mailboxes'])) {
            $room['mailboxes'] = [];
        }

        $targets = [];
        if ($to === '*') {
            foreach (array_keys($room['peers']) as $peerId) {
                if ((string) $peerId !== $from) {
                    $targets[] = (string) $peerId;
                }
            }
        } else {
            $targets[] = $to;
        }

        foreach ($targets as $target) {
            if (!isset($room['mailboxes'][$target]) || !is_array($room['mailboxes'][$target])) {
                $room['mailboxes'][$target] = [];
            }

            $room['mailboxes'][$target][] = [
                'from' => $from,
                'to' => $target,
                'payload' => $payload,
                'ts' => $this->nowMs(),
            ];

            if (count($room['mailboxes'][$target]) > 200) {
                $room['mailboxes'][$target] = array_slice($room['mailboxes'][$target], -200);
            }
        }
    }

    /**
     * @param array<string, mixed> $room
     * @return array<int, array<string, mixed>>
     */
    private function drainMailbox(array &$room, string $peerId): array
    {
        if (!isset($room['mailboxes'][$peerId]) || !is_array($room['mailboxes'][$peerId])) {
            return [];
        }

        $queued = $room['mailboxes'][$peerId];
        $room['mailboxes'][$peerId] = [];
        $events = [];

        foreach ($queued as $item) {
            if (!is_array($item)) {
                continue;
            }
            $events[] = [
                'type' => 'signal',
                'from' => (string) ($item['from'] ?? ''),
                'to' => $peerId,
                'payload' => is_array($item['payload'] ?? null) ? $item['payload'] : [],
                'ts' => (int) ($item['ts'] ?? $this->nowMs()),
                'seq' => (int) $room['seq'],
            ];
        }

        return $events;
    }

    /**
     * @param array<string, mixed> $room
     * @return array<string, mixed>|null
     */
    private function currentSpeaker(array $room): ?array
    {
        $speaker = $room['speaker'] ?? null;
        if (!is_array($speaker) || empty($speaker['peerId'])) {
            return null;
        }

        return [
            'peerId' => (string) $speaker['peerId'],
            'nickname' => (string) ($speaker['nickname'] ?? 'Guest'),
            'grantedAt' => (int) ($speaker['grantedAt'] ?? 0),
            'expiresAt' => (int) ($speaker['expiresAt'] ?? 0),
        ];
    }

    /**
     * @param array<string, mixed> $room
     * @return array<string, mixed>
     */
    private function publicState(array $room): array
    {
        $peers = [];
        foreach ($room['peers'] as $peerId => $peer) {
            $peers[] = [
                'peerId' => (string) $peerId,
                'nickname' => (string) ($peer['nickname'] ?? 'Guest'),
            ];
        }

        return [
            'channel' => (string) $room['name'],
            'seq' => (int) $room['seq'],
            'peers' => $peers,
            'speaker' => $this->currentSpeaker($room),
            'userCount' => count($peers),
        ];
    }

    /** @return array<string, mixed> */
    private function emptyRoom(string $channel): array
    {
        return [
            'name' => $channel,
            'createdAt' => time(),
            'seq' => 0,
            'speaker' => null,
            'peers' => [],
            'events' => [],
            'mailboxes' => [],
        ];
    }

    private function filePath(string $channel): string
    {
        $safe = preg_replace('/[^a-z0-9\-_]/', '', strtolower($channel)) ?: 'room';
        return App::root() . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'rooms' . DIRECTORY_SEPARATOR . $safe . '.json';
    }

    private function nowMs(): int
    {
        return (int) floor(microtime(true) * 1000);
    }
}
