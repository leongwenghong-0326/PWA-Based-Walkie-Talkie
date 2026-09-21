<?php

declare(strict_types=1);

namespace App\Services;

use App\Helpers\Sanitize;

final class GuestService
{
    public function createId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public function prepareNickname(string $raw): string
    {
        return Sanitize::nickname($raw);
    }

    public function prepareChannel(string $raw): string
    {
        return Sanitize::channel($raw);
    }
}
