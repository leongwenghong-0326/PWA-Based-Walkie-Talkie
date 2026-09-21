<?php

declare(strict_types=1);

use App\Core\App;
use App\Core\Autoloader;

$root = __DIR__;
require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Core' . DIRECTORY_SEPARATOR . 'Autoloader.php';
require $root . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Helpers' . DIRECTORY_SEPARATOR . 'functions.php';

Autoloader::register($root);
App::boot($root);
App::run();
