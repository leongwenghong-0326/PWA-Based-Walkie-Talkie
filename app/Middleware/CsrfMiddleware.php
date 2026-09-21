<?php

declare(strict_types=1);

namespace App\Middleware;

use App\Core\Request;
use App\Core\Session;

final class CsrfMiddleware
{
    public function check(Request $request): bool
    {
        $token = $request->string('_csrf');
        if ($token === '') {
            $token = (string) ($request->header('X-CSRF-Token') ?? '');
        }

        return Session::csrfMatches($token);
    }
}
