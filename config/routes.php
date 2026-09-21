<?php

declare(strict_types=1);

use App\Controllers\AssetController;
use App\Controllers\ChannelController;
use App\Controllers\JoinController;
use App\Controllers\SignalController;
use App\Core\Router;

return static function (Router $router): void {
    $router->get('/', [JoinController::class, 'show']);
    $router->post('/join', [JoinController::class, 'join']);

    $router->get('/channel', [ChannelController::class, 'show']);
    $router->get('/leave', [ChannelController::class, 'leave']);
    $router->post('/leave', [ChannelController::class, 'leave']);

    $router->post('/signal', [SignalController::class, 'handle']);

    $router->get('/manifest.webmanifest', [AssetController::class, 'manifest']);
    $router->get('/sw.js', [AssetController::class, 'serviceWorker']);
    $router->get('/qr.svg', [AssetController::class, 'qr']);
};
