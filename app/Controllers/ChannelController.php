<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;
use App\Services\JoinTicketService;
use App\Services\RoomService;
use App\Services\TokenService;

final class ChannelController
{
    public function show(Request $request): void
    {
        $this->restoreFromTicket($request->query('j'));

        $session = $this->activeSession();
        if ($session === null) {
            Response::redirect('/?error=expired');
            return;
        }

        $config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'app.php';

        $html = View::render('channel', [
            'title' => 'Channel #' . strtoupper($session['channel']),
            'csrf' => Session::csrfToken(),
            'nickname' => $session['nickname'],
            'channel' => $session['channel'],
            'channelDisplay' => strtoupper($session['channel']),
            'peerId' => $session['peer_id'],
            'token' => $session['token'],
            'iceServers' => $config['ice_servers'],
            'floorTimeoutMs' => $config['floor_timeout_ms'],
            'pollIntervalMs' => $config['poll_interval_ms'],
            'maxPeers' => $config['max_peers'],
        ], 'layouts/channel');

        Response::html($html);
    }

    public function leave(Request $request): void
    {
        $token = $request->string('token');
        if ($token === '') {
            $token = (string) Session::get('token', '');
        }

        $claims = (new TokenService())->verify($token);
        if ($claims !== null) {
            (new RoomService())->leave($claims['channel'], $claims['peerId']);
        }

        Session::remove('peer_id');
        Session::remove('nickname');
        Session::remove('channel');
        Session::remove('channel_raw');
        Session::remove('token');
        Session::remove('joined_at');

        $wantsJson = str_contains(strtolower($request->header('Accept') ?? ''), 'application/json')
            || $request->method() === 'POST';

        if ($wantsJson && $request->method() === 'POST') {
            Response::json(['ok' => true]);
            return;
        }

        Response::redirect('/?error=left');
    }

    private function restoreFromTicket(?string $ticketId): void
    {
        if (!is_string($ticketId) || $ticketId === '') {
            return;
        }

        // Already have a valid session — consume ticket so it cannot be reused.
        if ($this->activeSession() !== null) {
            (new JoinTicketService())->consume($ticketId);
            return;
        }

        $payload = (new JoinTicketService())->consume($ticketId);
        if ($payload === null) {
            return;
        }

        $claims = (new TokenService())->verify($payload['token']);
        if ($claims === null) {
            return;
        }

        Session::set('peer_id', $payload['peer_id']);
        Session::set('nickname', $payload['nickname']);
        Session::set('channel', $payload['channel']);
        Session::set('channel_raw', $payload['channel_raw']);
        Session::set('token', $payload['token']);
        Session::set('joined_at', time());
    }

    /** @return array{peer_id:string,nickname:string,channel:string,token:string}|null */
    private function activeSession(): ?array
    {
        $peerId = Session::get('peer_id');
        $nickname = Session::get('nickname');
        $channel = Session::get('channel');
        $token = Session::get('token');

        if (!is_string($peerId) || !is_string($nickname) || !is_string($channel) || !is_string($token)) {
            return null;
        }

        $claims = (new TokenService())->verify($token);
        if ($claims === null) {
            return null;
        }

        return [
            'peer_id' => $peerId,
            'nickname' => $nickname,
            'channel' => $channel,
            'token' => $token,
        ];
    }
}
