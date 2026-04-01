<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\UuidInterface;

final class PdoRefundRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function totalRefunded(UuidInterface $orderId): float
    {
        $stmt = $this->pdo->prepare(
            "SELECT COALESCE(SUM(amount), 0) FROM order_refunds
             WHERE order_id = :order_id AND status IN ('requested', 'succeeded')"
        );
        $stmt->execute(['order_id' => $orderId->toString()]);
        return (float) $stmt->fetchColumn();
    }

    /**
     * @return array{provider:string,provider_ref:string}|null
     */
    public function latestPaymentReference(UuidInterface $orderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT provider, provider_ref
             FROM order_payments
             WHERE order_id = :order_id
             ORDER BY id DESC
             LIMIT 1'
        );
        $stmt->execute(['order_id' => $orderId->toString()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            return null;
        }
        return [
            'provider' => (string) ($row['provider'] ?? ''),
            'provider_ref' => (string) ($row['provider_ref'] ?? ''),
        ];
    }

    /**
     * @param array<string,mixed>|null $rawPayload
     */
    public function createRefund(
        UuidInterface $orderId,
        string $provider,
        ?string $providerRef,
        float $amount,
        string $currency,
        ?string $reason,
        string $status,
        ?array $rawPayload = null
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_refunds
             (order_id, provider, provider_ref, amount, currency, reason, status, raw_payload, created_at)
             VALUES
             (:order_id, :provider, :provider_ref, :amount, :currency, :reason, :status, :raw_payload, :created_at)'
        );
        $rawJson = $rawPayload !== null
            ? json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            : null;
        $stmt->execute([
            'order_id' => $orderId->toString(),
            'provider' => $provider,
            'provider_ref' => $providerRef,
            'amount' => $amount,
            'currency' => strtoupper($currency),
            'reason' => $reason,
            'status' => $status,
            'raw_payload' => $rawJson === false ? null : $rawJson,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
