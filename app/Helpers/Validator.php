<?php

declare(strict_types=1);

namespace App\Helpers;

final class Validator
{
    public static function nickname(string $value): ?string
    {
        if ($value === '') {
            return 'Please enter a nickname.';
        }
        if (mb_strlen($value) > 24) {
            return 'Nickname must be 24 characters or fewer.';
        }
        return null;
    }

    public static function channel(string $value): ?string
    {
        if ($value === '') {
            return 'Please enter a channel name.';
        }
        if (mb_strlen($value) > 64) {
            return 'Channel name must be 64 characters or fewer.';
        }
        return null;
    }
}
