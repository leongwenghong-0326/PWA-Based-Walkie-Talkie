<?php

declare(strict_types=1);

namespace App\Models;

final class RoomState
{
    public function __construct(
        public string $name,
        public int $seq,
        public int $userCount,
        /** @var array<int, array{peerId:string,nickname:string}> */
        public array $peers,
        /** @var array{peerId:string,nickname:string,expiresAt:int}|null */
        public ?array $speaker
    ) {
    }
}
