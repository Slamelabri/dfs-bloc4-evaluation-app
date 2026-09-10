<?php

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$request = Request::create(
    '/webhooks/interventions',
    'POST',
    $_POST,
    $_COOKIE,
    $_FILES,
    $_SERVER,
    file_get_contents('php://input') ?: null
);

$response = $kernel->handle($request);
$response->send();

$kernel->terminate($request, $response);
