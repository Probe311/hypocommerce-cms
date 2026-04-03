<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;

final class PdoAdminShippingCarrierRepository
{
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
            'SELECT id, carrier_key, label, zones, is_enabled, priority, metadata, updated_at
             FROM admin_shipping_carriers
             ORDER BY priority ASC, carrier_key ASC'
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) {
            return [];
        }
        return array_map(function (array $item): array {
            $item['is_enabled'] = ((int) ($item['is_enabled'] ?? 0)) === 1;
            $item['priority'] = (int) ($item['priority'] ?? 100);
            $zones = json_decode((string) ($item['zones'] ?? '[]'), true);
            $meta = json_decode((string) ($item['metadata'] ?? '{}'), true);
            $item['zones'] = is_array($zones) ? $zones : [];
            $item['metadata'] = is_array($meta) ? $meta : [];
            return $item;
        }, $items);
    }

    /**
     * @param array<int,string> $zones
     * @param array<string,mixed> $metadata
     */
    public function upsert(string $carrierKey, string $label, array $zones, int $priority, array $metadata): bool
    {
        $carrierKey = strtolower(trim($carrierKey));
        $label = trim($label);
        if ($carrierKey === '' || $label === '') {
            return false;
        }
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_shipping_carriers (carrier_key, label, zones, is_enabled, priority, metadata, created_at, updated_at)
             VALUES (:carrier_key, :label, :zones, 1, :priority, :metadata, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                label = VALUES(label),
                zones = VALUES(zones),
                priority = VALUES(priority),
                metadata = VALUES(metadata),
                updated_at = VALUES(updated_at)'
        );
        return $stmt->execute([
            'carrier_key' => $carrierKey,
            'label' => $label,
            'zones' => json_encode(array_values($zones), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'priority' => max(1, min(999, $priority)),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function toggle(int $id, bool $enabled): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE admin_shipping_carriers SET is_enabled = :enabled, updated_at = :updated_at WHERE id = :id'
        );
        return $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }
}
