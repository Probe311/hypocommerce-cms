<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class NotFoundController
{
    public function __invoke(Request $request): Response
    {
        return new Response('Not Found', 404);
    }
}
