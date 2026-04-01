<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\UuidInterface;
use RuntimeException;

final class PdoInventoryRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function reserve(UuidInterface $productId, ?UuidInterface $variantId, int $quantity): void
    {
        $item = $this->findItem($productId, $variantId);
        if ($item === null) {
            throw new RuntimeException('inventory_not_found');
        }

        $available = ((int) $item['stock_qty']) - ((int) $item['reserved_qty']);
        $backorderAllowed = (int) $item['backorder_allowed'] === 1;
        if ($available < $quantity && !$backorderAllowed) {
            throw new RuntimeException('insufficient_stock');
        }

        $stmt = $this->pdo->prepare(
            'UPDATE inventory_items SET reserved_qty = reserved_qty + :quantity WHERE id = :id'
        );
        $stmt->execute([
            'quantity' => $quantity,
            'id' => (int) $item['id'],
        ]);
    }

    public function confirm(UuidInterface $productId, ?UuidInterface $variantId, int $quantity): void
    {
        $item = $this->findItem($productId, $variantId);
        if ($item === null) {
            throw new RuntimeException('inventory_not_found');
        }
        $inventoryItemId = (int) $item['id'];

        $update = $this->pdo->prepare(
            'UPDATE inventory_items
             SET stock_qty = GREATEST(stock_qty - :quantity, 0),
                 reserved_qty = GREATEST(reserved_qty - :quantity, 0)
             WHERE id = :id'
        );
        $update->execute([
            'quantity' => $quantity,
            'id' => $inventoryItemId,
        ]);
        $this->insertMovement($inventoryItemId, 'order', -$quantity);
    }

    public function compensate(UuidInterface $productId, ?UuidInterface $variantId, int $quantity): void
    {
        $item = $this->findItem($productId, $variantId);
        if ($item === null) {
            throw new RuntimeException('inventory_not_found');
        }
        $inventoryItemId = (int) $item['id'];

        $update = $this->pdo->prepare(
            'UPDATE inventory_items SET stock_qty = stock_qty + :quantity WHERE id = :id'
        );
        $update->execute([
            'quantity' => $quantity,
            'id' => $inventoryItemId,
        ]);
        $this->insertMovement($inventoryItemId, 'cancel', $quantity);
    }

    private function release(UuidInterface $productId, ?UuidInterface $variantId, int $quantity): void
    {
        $item = $this->findItem($productId, $variantId);
        if ($item === null) {
            throw new RuntimeException('inventory_not_found');
        }

        $update = $this->pdo->prepare(
            'UPDATE inventory_items SET reserved_qty = GREATEST(reserved_qty - :quantity, 0) WHERE id = :id'
        );
        $update->execute([
            'quantity' => $quantity,
            'id' => (int) $item['id'],
        ]);
    }

    public function releaseReservation(UuidInterface $productId, ?UuidInterface $variantId, int $quantity): void
    {
        $this->release($productId, $variantId, $quantity);
    }

    /**
     * @return array<string,mixed>|null
     */
    private function findItem(UuidInterface $productId, ?UuidInterface $variantId): ?array
    {
        $sql = 'SELECT * FROM inventory_items WHERE product_id = :product_id ';
        if ($variantId !== null) {
            $sql .= 'AND variant_id = :variant_id ';
        } else {
            $sql .= 'AND variant_id IS NULL ';
        }
        $sql .= 'LIMIT 1';

        $stmt = $this->pdo->prepare($sql);
        $params = ['product_id' => $productId->toString()];
        if ($variantId !== null) {
            $params['variant_id'] = $variantId->toString();
        }
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    private function insertMovement(int $inventoryItemId, string $type, int $quantityDelta): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO inventory_movements (inventory_item_id, type, quantity_delta, created_at)
             VALUES (:inventory_item_id, :type, :quantity_delta, :created_at)'
        );
        $stmt->execute([
            'inventory_item_id' => $inventoryItemId,
            'type' => $type,
            'quantity_delta' => $quantityDelta,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }
}
