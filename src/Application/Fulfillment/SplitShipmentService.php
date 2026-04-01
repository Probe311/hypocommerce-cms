<?php

declare(strict_types=1);

namespace App\Application\Fulfillment;

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\PdoOrderRepository;
use PDO;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class SplitShipmentService
{
    private PDO $pdo;

    public function __construct(
        private readonly PdoOrderRepository $orderRepository = new PdoOrderRepository()
    ) {
        $this->pdo = ConnectionFactory::getConnection();
    }

    /**
     * @param array<int,array<string,mixed>> $shipments
     * @return array{created:int,fullyAllocated:bool}
     */
    public function split(string $orderId, array $shipments): array
    {
        if (!Uuid::isValid($orderId)) {
            throw new RuntimeException('invalid_order_id');
        }
        if ($shipments === []) {
            throw new RuntimeException('empty_shipments');
        }

        $itemsStmt = $this->pdo->prepare('SELECT id, quantity FROM order_items WHERE order_id = :order_id');
        $itemsStmt->execute(['order_id' => $orderId]);
        $rows = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            throw new RuntimeException('order_items_not_found');
        }
        $orderItemQty = [];
        foreach ($rows as $row) {
            $orderItemQty[(int) $row['id']] = (int) $row['quantity'];
        }

        $allocated = [];
        $created = 0;
        $this->pdo->beginTransaction();
        try {
            $insertShipment = $this->pdo->prepare(
                'INSERT INTO order_shipments (order_id, warehouse_code, carrier, tracking_number, status, shipped_at)
                 VALUES (:order_id, :warehouse_code, :carrier, :tracking_number, :status, :shipped_at)'
            );
            $insertItem = $this->pdo->prepare(
                'INSERT INTO order_shipment_items (shipment_id, order_item_id, quantity)
                 VALUES (:shipment_id, :order_item_id, :quantity)'
            );

            foreach ($shipments as $shipment) {
                if (!is_array($shipment)) {
                    continue;
                }
                $warehouseCode = strtoupper(trim((string) ($shipment['warehouseCode'] ?? 'MAIN')));
                $carrier = trim((string) ($shipment['carrier'] ?? ''));
                $trackingNumber = trim((string) ($shipment['trackingNumber'] ?? ''));
                $status = strtolower(trim((string) ($shipment['status'] ?? 'shipped')));
                if ($status === '') {
                    $status = 'shipped';
                }

                $insertShipment->execute([
                    'order_id' => $orderId,
                    'warehouse_code' => $warehouseCode,
                    'carrier' => $carrier !== '' ? $carrier : null,
                    'tracking_number' => $trackingNumber !== '' ? $trackingNumber : null,
                    'status' => $status,
                    'shipped_at' => date('Y-m-d H:i:s'),
                ]);
                $shipmentId = (int) $this->pdo->lastInsertId();
                $created++;

                $items = $shipment['items'] ?? [];
                if (!is_array($items)) {
                    continue;
                }
                foreach ($items as $item) {
                    if (!is_array($item)) {
                        continue;
                    }
                    $orderItemId = (int) ($item['orderItemId'] ?? 0);
                    $qty = (int) ($item['quantity'] ?? 0);
                    if ($orderItemId < 1 || $qty < 1 || !isset($orderItemQty[$orderItemId])) {
                        continue;
                    }
                    $already = $allocated[$orderItemId] ?? 0;
                    if ($already + $qty > $orderItemQty[$orderItemId]) {
                        throw new RuntimeException('shipment_quantity_exceeds_order_item');
                    }
                    $allocated[$orderItemId] = $already + $qty;
                    $insertItem->execute([
                        'shipment_id' => $shipmentId,
                        'order_item_id' => $orderItemId,
                        'quantity' => $qty,
                    ]);
                }
            }

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $fullyAllocated = true;
        foreach ($orderItemQty as $itemId => $qty) {
            if (($allocated[$itemId] ?? 0) < $qty) {
                $fullyAllocated = false;
                break;
            }
        }
        if ($fullyAllocated) {
            $this->orderRepository->transitionStatus(Uuid::fromString($orderId), 'fulfilled');
        }

        return ['created' => $created, 'fullyAllocated' => $fullyAllocated];
    }
}
