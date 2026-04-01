<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;

final class PdoCouponRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function upsert(array $payload): int
    {
        $code = strtoupper(trim((string) ($payload['code'] ?? '')));
        $type = (string) ($payload['type'] ?? 'percent');
        $value = (float) ($payload['value'] ?? 0);
        $minOrderTotal = isset($payload['minOrderTotal']) ? (float) $payload['minOrderTotal'] : null;
        $maxUses = isset($payload['maxUses']) ? (int) $payload['maxUses'] : null;
        $maxUsesPerUser = isset($payload['maxUsesPerUser']) ? (int) $payload['maxUsesPerUser'] : null;
        $startsAt = isset($payload['startsAt']) ? (string) $payload['startsAt'] : null;
        $endsAt = isset($payload['endsAt']) ? (string) $payload['endsAt'] : null;
        $appliesTo = isset($payload['appliesTo']) && is_array($payload['appliesTo']) ? $payload['appliesTo'] : null;

        $select = $this->pdo->prepare('SELECT id FROM coupons WHERE code = :code LIMIT 1');
        $select->execute(['code' => $code]);
        $existingId = $select->fetchColumn();

        $params = [
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'min_order_total' => $minOrderTotal,
            'max_uses' => $maxUses,
            'max_uses_per_user' => $maxUsesPerUser,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'applies_to' => $appliesTo !== null ? json_encode($appliesTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        ];

        if ($existingId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO coupons (code, type, value, min_order_total, max_uses, max_uses_per_user, starts_at, ends_at, applies_to)
                 VALUES (:code, :type, :value, :min_order_total, :max_uses, :max_uses_per_user, :starts_at, :ends_at, :applies_to)'
            );
            $insert->execute($params);
            return (int) $this->pdo->lastInsertId();
        }

        $update = $this->pdo->prepare(
            'UPDATE coupons
             SET type = :type, value = :value, min_order_total = :min_order_total, max_uses = :max_uses,
                 max_uses_per_user = :max_uses_per_user, starts_at = :starts_at, ends_at = :ends_at, applies_to = :applies_to
             WHERE id = :id'
        );
        $update->execute(array_merge($params, ['id' => (int) $existingId]));
        return (int) $existingId;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM coupons WHERE code = :code LIMIT 1');
        $stmt->execute(['code' => strtoupper(trim($code))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function usageCount(int $couponId): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :coupon_id');
        $stmt->execute(['coupon_id' => $couponId]);
        return (int) $stmt->fetchColumn();
    }

    public function usageCountForCustomerRef(int $couponId, string $customerRef): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM coupon_redemptions WHERE coupon_id = :coupon_id AND customer_ref = :customer_ref'
        );
        $stmt->execute([
            'coupon_id' => $couponId,
            'customer_ref' => $customerRef,
        ]);
        return (int) $stmt->fetchColumn();
    }

    public function recordRedemption(int $couponId, string $orderId, ?string $customerRef, float $amount): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO coupon_redemptions (coupon_id, order_id, customer_ref, amount, created_at)
             VALUES (:coupon_id, :order_id, :customer_ref, :amount, :created_at)'
        );
        $stmt->execute([
            'coupon_id' => $couponId,
            'order_id' => $orderId,
            'customer_ref' => $customerRef,
            'amount' => $amount,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<int,string> $productIds
     * @return array<string,string> product_id => category_slug
     */
    public function categorySlugByProductIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $sql = "SELECT pcp.product_id, pc.slug
                FROM product_category_pivot pcp
                INNER JOIN product_categories pc ON pc.id = pcp.category_id
                WHERE pcp.product_id IN ({$placeholders})";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_values($productIds));
        $result = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (is_array($row)) {
                $result[(string) $row['product_id']] = (string) $row['slug'];
            }
        }
        return $result;
    }
}
