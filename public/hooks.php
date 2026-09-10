<?php

/**
 * Point d'entree du webhook entrant.
 *
 * Le traitement passe par le kernel HTTP de Laravel : sans cela le conteneur
 * n'est pas amorce (les liaisons "config", "db" et "log" n'existent pas) et
 * toute requete se termine en erreur fatale. Passer par le kernel apporte
 * aussi la gestion des exceptions, les middlewares et la journalisation.
 *
 * La requete est routee vers POST /api/webhooks/interventions
 * (routes/api.php) : declaree cote API, elle n'est soumise ni a la session
 * ni a la verification CSRF, qui renvoyait 419 sur un appel machine-a-machine.
 */

use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;

require __DIR__.'/../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require_once __DIR__.'/../bootstrap/app.php';

$kernel = $app->make(Kernel::class);

$request = Request::create(
    '/api/webhooks/interventions',
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
