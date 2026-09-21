<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Services\RateLimitService;

final class RateLimitMiddleware
{
    public function __construct(private RateLimitService $limiter)
    {
    }

    public function check(Request $request, string $bucket, int $max, int $windowSeconds): bool
    {
        return $this->limiter->allow($bucket, $request->ip(), $max, $windowSeconds);
    }
}
