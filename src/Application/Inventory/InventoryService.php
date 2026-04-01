<?php

declare(strict_types=1);

namespace App\Application\Inventory;

use App\Infrastructure\Persistence\PdoInventoryRepository;
use App\Infrastructure\Persistence\PdoOrderRepository;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class InventoryService
{
    public function __construct(
        private readonly PdoInventoryRepository $inventoryRepository = new PdoInventoryRepository(),
        private readonly PdoOrderRepository $orderRepository = new PdoOrderRepository()
    ) {
    }

    public function reserveForOrder(string $orderId): void
    {
        $order = $this->orderRepository->findById(Uuid::fromString($orderId));
        if ($order === null) {
            throw new RuntimeException('order_not_found');
        }
        foreach ($order->items() as $item) {
            $this->inventoryRepository->reserve($item->productId(), $item->variantId(), $item->quantity());
        }
    }

    public function confirmForOrder(string $orderId): void
    {
        $order = $this->orderRepository->findById(Uuid::fromString($orderId));
        if ($order === null) {
            throw new RuntimeException('order_not_found');
        }
        foreach ($order->items() as $item) {
            $this->inventoryRepository->confirm($item->productId(), $item->variantId(), $item->quantity());
        }
    }

    public function compensateForOrder(string $orderId): void
    {
        $order = $this->orderRepository->findById(Uuid::fromString($orderId));
        if ($order === null) {
            throw new RuntimeException('order_not_found');
        }
        foreach ($order->items() as $item) {
            $this->inventoryRepository->compensate($item->productId(), $item->variantId(), $item->quantity());
        }
    }

    public function releaseReservationForOrder(string $orderId): void
    {
        $order = $this->orderRepository->findById(Uuid::fromString($orderId));
        if ($order === null) {
            throw new RuntimeException('order_not_found');
        }
        foreach ($order->items() as $item) {
            $this->inventoryRepository->releaseReservation($item->productId(), $item->variantId(), $item->quantity());
        }
    }
}
