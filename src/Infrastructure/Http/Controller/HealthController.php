<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use Throwable;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class HealthController
{
    public function __invoke(Request $request): Response
    {
        $dbOk = false;
        $dbError = null;
        try {
            $pdo = ConnectionFactory::getConnection();
            $row = $pdo->query('SELECT 1 AS ok')->fetch();
            $dbOk = is_array($row) && (int) ($row['ok'] ?? 0) === 1;
        } catch (Throwable $e) {
            $dbError = $e->getMessage();
        }

        $payload = [
            'status' => $dbOk ? 'ok' : 'degraded',
            'mode' => (string) ($_ENV['APP_ENV'] ?? 'dev'),
            'version' => (string) ($_ENV['APP_VERSION'] ?? 'dev'),
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'dependencies' => [
                'database' => [
                    'ok' => $dbOk,
                    'error' => $dbError,
                ],
            ],
        ];

        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $dbOk ? 200 : 503,
            ['Content-Type' => 'application/json']
        );
    }
}
