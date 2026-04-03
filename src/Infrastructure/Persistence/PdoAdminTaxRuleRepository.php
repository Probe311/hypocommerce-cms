<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;

final class PdoAdminTaxRuleRepository
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
            'SELECT id, country_code, region_code, tax_type, rate, is_enabled, is_default, metadata, updated_at
             FROM admin_tax_rules
             ORDER BY is_default DESC, country_code ASC, tax_type ASC'
        );
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($items)) {
            return [];
        }
        return array_map(function (array $item): array {
            $item['is_enabled'] = ((int) ($item['is_enabled'] ?? 0)) === 1;
            $item['is_default'] = ((int) ($item['is_default'] ?? 0)) === 1;
            $item['rate'] = (float) ($item['rate'] ?? 0);
            $decoded = json_decode((string) ($item['metadata'] ?? '{}'), true);
            $item['metadata'] = is_array($decoded) ? $decoded : [];
            return $item;
        }, $items);
    }

    /**
     * @param array<string,mixed> $metadata
     */
    public function upsert(string $countryCode, ?string $regionCode, string $taxType, float $rate, bool $isDefault, array $metadata): bool
    {
        $countryCode = strtoupper(trim($countryCode));
        $regionCode = $regionCode !== null ? strtoupper(trim($regionCode)) : null;
        $taxType = strtolower(trim($taxType));
        if ($countryCode === '' || $taxType === '') {
            return false;
        }
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO admin_tax_rules (country_code, region_code, tax_type, rate, is_enabled, is_default, metadata, created_at, updated_at)
             VALUES (:country_code, :region_code, :tax_type, :rate, 1, :is_default, :metadata, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE
                rate = VALUES(rate),
                is_default = VALUES(is_default),
                metadata = VALUES(metadata),
                updated_at = VALUES(updated_at)'
        );
        return $stmt->execute([
            'country_code' => $countryCode,
            'region_code' => $regionCode !== '' ? $regionCode : null,
            'tax_type' => $taxType,
            'rate' => max(0, min(100, $rate)),
            'is_default' => $isDefault ? 1 : 0,
            'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function toggle(int $id, bool $enabled): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE admin_tax_rules SET is_enabled = :enabled, updated_at = :updated_at WHERE id = :id'
        );
        return $stmt->execute([
            'enabled' => $enabled ? 1 : 0,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $id,
        ]);
    }
}
