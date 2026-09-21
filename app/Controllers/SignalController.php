<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Middleware\RateLimitMiddleware;
use App\Services\RateLimitService;
use App\Services\RoomService;
use App\Services\SignalingService;
use App\Services\TokenService;

final class SignalController
{
    public function handle(Request $request): void
    {
        $limiter = new RateLimitMiddleware(new RateLimitService());
        if (!$limiter->check($request, 'signal', 2400, 60)) {
            Response::json([
                'ok' => false,
                'error' => 'rate',
                'message' => 'Too many signaling requests. Please wait a moment.',
            ], 429);
            return;
        }

        $service = new SignalingService(new RoomService(), new TokenService());
        $result = $service->handle($request->json(), strlen($request->rawBody()));
        $status = (int) ($result['_status'] ?? 200);
        unset($result['_status']);

        Response::json($result, $status);
    }
}
