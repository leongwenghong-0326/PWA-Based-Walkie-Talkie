<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Helpers\Validator;
use App\Middleware\CsrfMiddleware;
use App\Middleware\RateLimitMiddleware;
use App\Services\GuestService;
use App\Services\JoinTicketService;
use App\Services\RateLimitService;
use App\Services\RoomService;
use App\Services\TokenService;

final class JoinController
{
    public function show(Request $request): void
    {
        $error = $request->query('error');
        $html = View::render('join', [
            'title' => 'Walkie Talkie',
            'csrf' => Session::csrfToken(),
            'error' => $this->friendlyError($error),
            'publicUrl' => $request->publicUrl(),
            'nickname' => Session::get('nickname', ''),
            'channel' => Session::get('channel_raw', ''),
        ]);
        Response::html($html);
    }

    public function join(Request $request): void
    {
        $csrf = new CsrfMiddleware();
        if (!$csrf->check($request)) {
            Response::redirect('/?error=csrf');
            return;
        }

        $limiter = new RateLimitMiddleware(new RateLimitService());
        if (!$limiter->check($request, 'join', 20, 60)) {
            Response::redirect('/?error=rate');
            return;
        }

        $guest = new GuestService();
        $nickname = $guest->prepareNickname($request->string('nickname'));
        $channelRaw = $request->string('channel');
        $channel = $guest->prepareChannel($channelRaw);

        $nickError = Validator::nickname($nickname);
        $channelError = Validator::channel($channel);
        if ($nickError !== null || $channelError !== null) {
            $code = $nickError !== null ? 'nickname' : 'channel';
            Response::redirect('/?error=' . $code);
            return;
        }

        $rooms = new RoomService();
        $config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';
        $max = (int) $config['max_peers'];
        if ($rooms->occupancy($channel) >= $max) {
            Response::redirect('/?error=full');
            return;
        }

        $peerId = $guest->createId();
        $token = (new TokenService())->issue($peerId, $nickname, $channel);

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }

        Session::set('peer_id', $peerId);
        Session::set('nickname', $nickname);
        Session::set('channel', $channel);
        Session::set('channel_raw', $channelRaw);
        Session::set('token', $token);
        Session::set('joined_at', time());

        // One-time ticket survives flaky mobile cookies after the POST→redirect.
        $ticket = (new JoinTicketService())->issue([
            'peer_id' => $peerId,
            'nickname' => $nickname,
            'channel' => $channel,
            'channel_raw' => $channelRaw,
            'token' => $token,
        ]);

        Response::redirect('/channel?j=' . rawurlencode($ticket), 303);
    }

    private function friendlyError(?string $code): ?string
    {
        return match ($code) {
            'csrf' => 'Session expired. Refresh this page, then join again.',
            'rate' => 'Too many join attempts. Please wait a moment and try again.',
            'nickname' => 'Please enter a nickname (maximum 24 characters).',
            'channel' => 'Please enter a valid channel name.',
            'full' => 'This channel is currently full. Maximum ' . (string) \App\Core\Env::int('SIGNAL_MAX_PEERS', 8) . ' users are allowed.',
            'expired' => 'Session expired. Join the channel again from this page.',
            'left' => null,
            default => null,
        };
    }
}
