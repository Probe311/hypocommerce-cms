<?php

declare(strict_types=1);

use App\Infrastructure\Http\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bin/bootstrap.php';

loadBackendEnv();

$request = Request::createFromGlobals();

try {
    $kernel = new Kernel();
    $response = $kernel->handle($request);
} catch (Throwable) {
    $response = new Symfony\Component\HttpFoundation\Response(
        json_encode(['error' => 'internal_error'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        500,
        ['Content-Type' => 'application/json']
    );
}

$response->send();
