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

    public function isProcessed(string $provider, string $eventId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT status
             FROM webhook_events
             WHERE provider = :provider AND event_id = :event_id
             LIMIT 1'
        );
        $stmt->execute([
            'provider' => strtolower(trim($provider)),
            'event_id' => trim($eventId),
        ]);
        $status = $stmt->fetchColumn();
        return is_string($status) && strtolower($status) === 'processed';
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function listRecentByProvider(string $provider, int $limit = 10): array
    {
        $provider = strtolower(trim($provider));
        $limit = max(1, min(50, $limit));
        $stmt = $this->pdo->prepare(
            'SELECT event_id, event_type, status, attempt_count, last_error, updated_at
             FROM webhook_events
             WHERE provider = :provider
             ORDER BY updated_at DESC
             LIMIT :limit'
        );
        $stmt->bindValue('provider', $provider);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        /** @var list<array<string,mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }
}
