<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;

final class PdoAdminPaymentMethodRepository
{
    private const ALLOWED_MODES = ['sandbox', 'live'];

    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function listAll(): array
    {
        $stmt = $this->pdo->query(
            'SELECT id, method_key, label, provider, mode, is_enabled, priority, metadata, updated_at
             FROM admin_payment_methods
             ORDER BY priority ASC, method_key ASC'
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) {
            return [];
        }
        return array_map(function (array $item): array {
            $item['is_enabled'] = ((int) ($item['is_enabled'] ?? 0)) === 1;
            $item['priority'] = (int) ($item['priority'] ?? 100);
            $meta = json_decode((string) ($item['metadata'] ?? '{}'), true);
            $item['metadata'] = is_array($meta) ? $meta : [];
            return $item;
        }, $items);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function upsert(string $methodKey, string $label, string $provider, string $mode, int $priority, array $metadata): bool
    {
        $methodKey = strtolower(trim($methodKey));
        $provider = strtolower(trim($provider));
        $label = trim($label);
        $mode = strtolower(trim($mode));
        if ($methodKey === '' || $provider === '' || $label === '' || !in_array($mode, self::ALLOWED_MODES, true)) {
            return false;
        }
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_payment_methods (method_key, label, provider, mode, is_enabled, priority, metadata, created_at, updated_at)
             VALUES (:method_key, :label, :provider, :mode, 1, :priority, :metadata, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                provider = VALUES(provider),
                mode = VALUES(mode),
                priority = VALUES(priority),
                metadata = VALUES(metadata),
                updated_at = VALUES(updated_at)'
        );
        return $stmt->execute([
            'method_key' => $methodKey,
            'label' => $label,
            'provider' => $provider,
            'mode' => $mode,
            'priority' => max(1, min(999, $priority)),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function toggle(int $id, bool $enabled): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE admin_payment_methods SET is_enabled = :enabled, updated_at = :updated_at WHERE id = :id'
        );
        return $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }
}
