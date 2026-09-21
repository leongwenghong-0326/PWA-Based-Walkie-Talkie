<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Env;

return [
    'name' => 'Walkie Talkie',
    'short_name' => 'Walkie',
    'description' => 'Push-to-Talk instant voice communication for teams.',
    'ice_servers' => [
        ['urls' => 'stun:stun.l.google.com:19302'],
        ['urls' => 'stun:stun1.l.google.com:19302'],
        ['urls' => 'stun:stun2.l.google.com:19302'],
        // Public TURN so phones/PCs behind different NATs can all hear the speaker.
        [
            'urls' => 'turn:openrelay.metered.ca:80',
            'username' => 'openrelayproject',
            'credential' => 'openrelayproject',
        ],
        [
            'urls' => 'turn:openrelay.metered.ca:443',
            'username' => 'openrelayproject',
            'credential' => 'openrelayproject',
        ],
        [
            'urls' => 'turn:openrelay.metered.ca:443?transport=tcp',
            'username' => 'openrelayproject',
            'credential' => 'openrelayproject',
        ],
    ],
    'max_peers' => Env::int('SIGNAL_MAX_PEERS', 8),
    'floor_timeout_ms' => Env::int('SIGNAL_FLOOR_TIMEOUT_MS', 30000),
    'max_body' => Env::int('SIGNAL_MAX_BODY', 32768),
    'max_data' => Env::int('SIGNAL_MAX_DATA', 12288),
    'token_ttl' => Env::int('SIGNAL_TOKEN_TTL', 14400),
    'peer_stale_seconds' => Env::int('SIGNAL_PEER_STALE_SECONDS', 20),
    'poll_interval_ms' => 500,
    'storage' => App::root() . DIRECTORY_SEPARATOR . 'storage',
];
