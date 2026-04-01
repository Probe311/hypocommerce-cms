<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Order\OrderStatusMachine;
use App\Domain\Order\Order;
use App\Domain\Order\OrderItem;
use App\Infrastructure\Database\ConnectionFactory;
use DateTimeImmutable;
use PDO;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class PdoOrderRepository
{
    private PDO $pdo;
    private OrderStatusMachine $statusMachine;

    public function __construct()
    {
        $this->pdo = ConnectionFactory::getConnection();
        $this->statusMachine = new OrderStatusMachine();
    }

    public function transitionStatus(UuidInterface $orderId, string $toStatus): bool
    {
        $stmt = $this->pdo->prepare('SELECT status FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId->toString()]);
        $fromStatus = $stmt->fetchColumn();
        if (!is_string($fromStatus) || $fromStatus === '') {
            return false;
        }
        if (!$this->statusMachine->canTransition($fromStatus, $toStatus)) {
            return false;
        }

        $update = $this->pdo->prepare(
            'UPDATE orders SET status = :status, updated_at = :updated_at WHERE id = :id'
        );
        $update->execute([
            'status' => $toStatus,
            'updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => $orderId->toString(),
        ]);

        return true;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findOrderNotificationData(UuidInterface $orderId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT o.id, o.number, o.status, o.customer_id, c.email AS customer_email
             FROM orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             WHERE o.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $orderId->toString()]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $address
     */
    public function upsertOrderAddress(UuidInterface $orderId, string $type, array $address): void
    {
        $existsStmt = $this->pdo->prepare(
            'SELECT id FROM order_addresses WHERE order_id = :order_id AND type = :type LIMIT 1'
        );
        $existsStmt->execute([
            'order_id' => $orderId->toString(),
            'type' => $type,
        ]);
        $existingId = $existsStmt->fetchColumn();

        $params = [
            'order_id' => $orderId->toString(),
            'type' => $type,
            'line1' => (string) ($address['line1'] ?? ''),
            'line2' => (string) ($address['line2'] ?? ''),
            'city' => (string) ($address['city'] ?? ''),
            'postcode' => (string) ($address['postcode'] ?? ''),
            'state' => (string) ($address['state'] ?? ''),
            'country' => strtoupper((string) ($address['country'] ?? 'FR')),
            'phone' => (string) ($address['phone'] ?? ''),
            'company' => (string) ($address['company'] ?? ''),
            'first_name' => (string) ($address['firstName'] ?? ''),
            'last_name' => (string) ($address['lastName'] ?? ''),
        ];

        if ($existingId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO order_addresses
                (order_id, type, line1, line2, city, postcode, state, country, phone, company, first_name, last_name)
                VALUES
                (:order_id, :type, :line1, :line2, :city, :postcode, :state, :country, :phone, :company, :first_name, :last_name)'
            );
            $insert->execute($params);
            return;
        }

        $update = $this->pdo->prepare(
            'UPDATE order_addresses
             SET line1 = :line1, line2 = :line2, city = :city, postcode = :postcode, state = :state, country = :country,
                 phone = :phone, company = :company, first_name = :first_name, last_name = :last_name
             WHERE id = :id'
        );
        $update->execute(array_merge($params, ['id' => (int) $existingId]));
    }

    public function createOrderPayment(
        UuidInterface $orderId,
        string $provider,
        string $providerRef,
        float $amount,
        string $status,
        ?array $rawPayload = null
    ): void {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_payments (order_id, provider, provider_ref, amount, status, raw_payload, created_at)
             VALUES (:order_id, :provider, :provider_ref, :amount, :status, :raw_payload, :created_at)'
        );
        $rawPayloadJson = $rawPayload !== null ? json_encode($rawPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
        $stmt->execute([
            'order_id' => $orderId->toString(),
            'provider' => $provider,
            'provider_ref' => $providerRef,
            'amount' => $amount,
            'status' => $status,
            'raw_payload' => $rawPayloadJson === false ? null : $rawPayloadJson,
            'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);
    }

    public function createOrderShipment(UuidInterface $orderId, ?string $carrier, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO order_shipments (order_id, carrier, tracking_number, status, shipped_at)
             VALUES (:order_id, :carrier, NULL, :status, NULL)'
        );
        $stmt->execute([
            'order_id' => $orderId->toString(),
            'carrier' => $carrier,
            'status' => $status,
        ]);
    }

    public function updateShipmentTracking(
        UuidInterface $orderId,
        string $carrier,
        string $trackingNumber,
        string $status = 'shipped'
    ): bool {
        $stmt = $this->pdo->prepare('SELECT id FROM order_shipments WHERE order_id = :order_id LIMIT 1');
        $stmt->execute(['order_id' => $orderId->toString()]);
        $shipmentId = $stmt->fetchColumn();
        if ($shipmentId === false) {
            $insert = $this->pdo->prepare(
                'INSERT INTO order_shipments (order_id, carrier, tracking_number, status, shipped_at)
                 VALUES (:order_id, :carrier, :tracking_number, :status, :shipped_at)'
            );
            $insert->execute([
                'order_id' => $orderId->toString(),
                'carrier' => $carrier,
                'tracking_number' => $trackingNumber,
                'status' => $status,
                'shipped_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            ]);
            return true;
        }

        $update = $this->pdo->prepare(
            'UPDATE order_shipments
             SET carrier = :carrier, tracking_number = :tracking_number, status = :status, shipped_at = :shipped_at
             WHERE id = :id'
        );
        $update->execute([
            'carrier' => $carrier,
            'tracking_number' => $trackingNumber,
            'status' => $status,
            'shipped_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
            'id' => (int) $shipmentId,
        ]);

        return true;
    }

    public function findById(UuidInterface $orderId): ?Order
    {
        $stmt = $this->pdo->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $orderId->toString()]);
        $row = $stmt->fetch();

        if (!$row) {
            return null;
        }

        return $this->hydrateOrder($row);
    }

    /**
     * @return Order[]
     */
    public function findByCustomerId(UuidInterface $customerId, int $limit = 20, int $offset = 0): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM orders WHERE customer_id = :customer_id ORDER BY placed_at DESC LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue('customer_id', $customerId->toString());
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $orders = [];
        while ($row = $stmt->fetch()) {
            $orders[] = $this->hydrateOrder($row);
        }

        return $orders;
    }

    /**
     * @param OrderItem[] $items
     */
    public function create(
        UuidInterface $orderId,
        string $number,
        ?UuidInterface $customerId,
        float $subTotal,
        float $taxTotal,
        float $shippingTotal,
        float $discountTotal,
        string $currency,
        ?string $paymentMethod,
        ?string $shippingMethod,
        array $items
    ): Order {
        $total = $subTotal + $taxTotal + $shippingTotal - $discountTotal;
        $now = new DateTimeImmutable();

        $stmt = $this->pdo->prepare(
            'INSERT INTO orders (
                id, number, customer_id, status, total, sub_total, tax_total, shipping_total, discount_total,
                currency, payment_method, shipping_method, placed_at, created_at, updated_at
             ) VALUES (
                :id, :number, :customer_id, :status, :total, :sub_total, :tax_total, :shipping_total, :discount_total,
                :currency, :payment_method, :shipping_method, :placed_at, :created_at, :updated_at
             )'
        );

        $stmt->execute([
            'id' => $orderId->toString(),
            'number' => $number,
            'customer_id' => $customerId?->toString(),
            'status' => 'pending',
            'total' => $total,
            'sub_total' => $subTotal,
            'tax_total' => $taxTotal,
            'shipping_total' => $shippingTotal,
            'discount_total' => $discountTotal,
            'currency' => $currency,
            'payment_method' => $paymentMethod,
            'shipping_method' => $shippingMethod,
            'placed_at' => $now->format('Y-m-d H:i:s'),
            'created_at' => $now->format('Y-m-d H:i:s'),
            'updated_at' => $now->format('Y-m-d H:i:s'),
        ]);

        foreach ($items as $item) {
            $itemStmt = $this->pdo->prepare(
                'INSERT INTO order_items (order_id, product_id, variant_id, name, quantity, unit_price, total, meta)
                 VALUES (:order_id, :product_id, :variant_id, :name, :quantity, :unit_price, :total, :meta)'
            );
            $itemStmt->execute([
                'order_id' => $orderId->toString(),
                'product_id' => $item->productId()->toString(),
                'variant_id' => $item->variantId()?->toString(),
                'name' => $item->name(),
                'quantity' => $item->quantity(),
                'unit_price' => $item->unitPrice(),
                'total' => $item->total(),
                'meta' => null,
            ]);
        }

        return new Order(
            $orderId,
            $number,
            $customerId,
            'pending',
            $total,
            $subTotal,
            $taxTotal,
            $shippingTotal,
            $discountTotal,
            $currency,
            $paymentMethod,
            $shippingMethod,
            $now,
            $items
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrateOrder(array $row): Order
    {
        $itemStmt = $this->pdo->prepare('SELECT * FROM order_items WHERE order_id = :order_id ORDER BY id');
        $itemStmt->execute(['order_id' => $row['id']]);

        $items = [];
        while ($itemRow = $itemStmt->fetch()) {
            $items[] = new OrderItem(
                (int) $itemRow['id'],
                Uuid::fromString($itemRow['order_id']),
                Uuid::fromString($itemRow['product_id']),
                $itemRow['variant_id'] !== null ? Uuid::fromString($itemRow['variant_id']) : null,
                $itemRow['name'],
                (int) $itemRow['quantity'],
                (float) $itemRow['unit_price'],
                (float) $itemRow['total']
            );
        }

        return new Order(
            Uuid::fromString($row['id']),
            $row['number'],
            $row['customer_id'] !== null ? Uuid::fromString($row['customer_id']) : null,
            $row['status'],
            (float) $row['total'],
            (float) $row['sub_total'],
            (float) $row['tax_total'],
            (float) $row['shipping_total'],
            (float) $row['discount_total'],
            $row['currency'],
            $row['payment_method'],
            $row['shipping_method'],
            new DateTimeImmutable($row['placed_at']),
            $items
        );
    }
}

