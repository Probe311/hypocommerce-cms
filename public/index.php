<?php

declare(strict_types=1);

use App\Infrastructure\Http\Kernel;
use Symfony\Component\HttpFoundation\Request;

require dirname(__DIR__) . '/vendor/autoload.php';
require dirname(__DIR__) . '/bin/bootstrap.php';

loadBackendEnv();

$request = Request::createFromGlobals();

$kernel = new Kernel();
$response = $kernel->handle($request);

$response->send();
