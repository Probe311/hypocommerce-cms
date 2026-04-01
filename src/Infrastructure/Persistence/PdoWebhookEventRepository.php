<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;

final class PdoWebhookEventRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function registerAttempt(string $provider, string $eventId, string $eventType, array $payload): void
    {
        $now = new DateTimeImmutable();
        $stmt = $this->pdo->prepare(
            'INSERT INTO webhook_events (provider, event_id, event_type, payload, status, attempt_count, created_at, updated_at)
             VALUES (:provider, :event_id, :event_type, :payload, :status, 1, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                attempt_count = attempt_count + 1,
                status = :status,
                updated_at = :updated_at'
        );
        $stmt->execute([
            'provider' => $provider,
            'event_id' => $eventId,
            'event_type' => $eventType,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
            'status' => 'received',
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);
    }

    public function markProcessed(string $provider, string $eventId): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE webhook_events
             SET status = :status, last_error = NULL, next_retry_at = NULL, updated_at = :updated_at
             WHERE provider = :provider AND event_id = :event_id'
        );
        $stmt->execute([
            'status' => 'processed',
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'provider' => $provider,
            'event_id' => $eventId,
        ]);
    }

    public function markFailed(string $provider, string $eventId, string $error): void
    {
        $nextRetry = (new DateTimeImmutable('+5 minutes'))->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'UPDATE webhook_events
             SET status = :status, last_error = :last_error, next_retry_at = :next_retry_at, updated_at = :updated_at
             WHERE provider = :provider AND event_id = :event_id'
        );
        $stmt->execute([
            'status' => 'failed',
            'last_error' => $error,
            'next_retry_at' => $nextRetry,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'provider' => $provider,
            'event_id' => $eventId,
        ]);
    }
}
