<?php

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$backend = dirname(__DIR__) . '/ptc-hub-backend';

if (file_exists($maintenance = $backend . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $backend . '/vendor/autoload.php';

/** @var Application $app */
$app = require_once $backend . '/bootstrap/app.php';

$app->handleRequest(Request::capture());
