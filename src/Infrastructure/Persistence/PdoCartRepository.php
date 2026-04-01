<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Domain\Cart\Cart;
use App\Domain\Cart\CartItem;
use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class PdoCartRepository
{
    private PDO $pdo;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
    }

    public function findById(UuidInterface $cartId): ?Cart
    {
        $stmt = $this->pdo->prepare('SELECT * FROM carts WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $cartId->toString()]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrateCart($row);
    }

    public function findBySessionId(string $sessionId): ?Cart
    {
        $stmt = $this->pdo->prepare('SELECT * FROM carts WHERE session_id = :session_id ORDER BY created_at DESC LIMIT 1');
        $stmt->execute(['session_id' => $sessionId]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrateCart($row);
    }

    public function create(UuidInterface $cartId, ?UuidInterface $customerId, ?string $sessionId, string $currency = 'EUR'): Cart
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO carts (id, customer_id, session_id, currency, created_at, expires_at)
             VALUES (:id, :customer_id, :session_id, :currency, :created_at, :expires_at)'
        );

        $stmt->execute([
            'id' => $cartId->toString(),
            'customer_id' => $customerId?->toString(),
            'session_id' => $sessionId,
            'currency' => $currency,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'expires_at' => (new DateTimeImmutable('+30 days'))->format('Y-m-d H:i:s'),
        ]);

        return new Cart($cartId, $customerId, $sessionId, $currency, []);
    }

    public function addItem(UuidInterface $cartId, UuidInterface $productId, ?UuidInterface $variantId, int $quantity, float $unitPrice): void
    {
        $total = $unitPrice * $quantity;

        $stmt = $this->pdo->prepare(
            'INSERT INTO cart_items (cart_id, product_id, variant_id, quantity, unit_price, total)
             VALUES (:cart_id, :product_id, :variant_id, :quantity, :unit_price, :total)'
        );

        $stmt->execute([
            'cart_id' => $cartId->toString(),
            'product_id' => $productId->toString(),
            'variant_id' => $variantId?->toString(),
            'quantity' => $quantity,
            'unit_price' => $unitPrice,
            'total' => $total,
        ]);
    }

    public function updateItemQuantity(int $cartItemId, int $quantity): void
    {
        $stmt = $this->pdo->prepare('UPDATE cart_items SET quantity = :q, total = unit_price * :q WHERE id = :id');
        $stmt->execute([
            'id' => $cartItemId,
            'q' => $quantity,
        ]);
    }

    public function removeItem(int $cartItemId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM cart_items WHERE id = :id');
        $stmt->execute(['id' => $cartItemId]);
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrateCart(array $row): Cart
    {
        $itemStmt = $this->pdo->prepare('SELECT * FROM cart_items WHERE cart_id = :cart_id ORDER BY id');
        $itemStmt->execute(['cart_id' => $row['id']]);

        $items = [];
        while ($itemRow = $itemStmt->fetch()) {
            $items[] = new CartItem(
                (int) $itemRow['id'],
                Uuid::fromString($itemRow['cart_id']),
                Uuid::fromString($itemRow['product_id']),
                $itemRow['variant_id'] !== null ? Uuid::fromString($itemRow['variant_id']) : null,
                (int) $itemRow['quantity'],
                (float) $itemRow['unit_price'],
                (float) $itemRow['total']
            );
        }

        return new Cart(
            Uuid::fromString($row['id']),
            $row['customer_id'] !== null ? Uuid::fromString($row['customer_id']) : null,
            $row['session_id'],
            $row['currency'],
            $items
        );
    }
}

