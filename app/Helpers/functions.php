<?php

declare(strict_types=1);

use App\Core\App;

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function url(string $path = ''): string
{
    return App::url($path);
}

function asset(string $path): string
{
    return App::asset($path);
}
