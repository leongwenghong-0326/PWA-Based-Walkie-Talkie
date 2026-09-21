<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Core\View;

final class ErrorController
{
    public function notFound(Request $request): void
    {
        $html = View::render('errors/404', [
            'title' => 'Page not found',
        ]);
        Response::html($html, 404);
    }

    public function serverError(?string $detail = null): void
    {
        $html = View::render('errors/500', [
            'title' => 'Something went wrong',
            'detail' => $detail,
        ]);
        Response::html($html, 500);
    }
}
