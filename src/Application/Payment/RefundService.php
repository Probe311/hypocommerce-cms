<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Inventory\InventoryService;
use App\Infrastructure\Persistence\PdoOrderRepository;
use App\Infrastructure\Persistence\PdoRefundRepository;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class RefundService
{
    public function __construct(
        private readonly PdoOrderRepository $orderRepository = new PdoOrderRepository(),
        private readonly PdoRefundRepository $refundRepository = new PdoRefundRepository(),
        private readonly InventoryService $inventoryService = new InventoryService()
    ) {
    }

    /**
     * @return array{refundId:int,orderId:string,amount:float,currency:string,status:string,fullyRefunded:bool}
     */
    public function refundOrder(string $orderId, float $amount, ?string $reason = null): array
    {
        if (!Uuid::isValid($orderId)) {
            throw new RuntimeException('invalid_order_id');
        }
        if ($amount <= 0) {
            throw new RuntimeException('invalid_refund_amount');
        }

        $order = $this->orderRepository->findById(Uuid::fromString($orderId));
        if ($order === null) {
            throw new RuntimeException('order_not_found');
        }
        if (!in_array($order->status(), ['paid', 'fulfilled', 'refunded'], true)) {
            throw new RuntimeException('order_not_refundable');
        }

        $alreadyRefunded = $this->refundRepository->totalRefunded($order->id());
        $remaining = max(0.0, round($order->total() - $alreadyRefunded, 2));
        if ($amount > $remaining + 0.00001) {
            throw new RuntimeException('refund_amount_exceeds_remaining');
        }

        $paymentRef = $this->refundRepository->latestPaymentReference($order->id());
        $provider = $paymentRef['provider'] ?? (string) ($order->paymentMethod() ?? 'manual');
        $providerRef = $paymentRef['provider_ref'] ?? null;

        $refundId = $this->refundRepository->createRefund(
            $order->id(),
            $provider,
            $providerRef,
            round($amount, 2),
            $order->currency(),
            $reason,
            'succeeded',
            ['mode' => 'admin']
        );

        // Reinject stock on any successful refund event.
        $this->inventoryService->compensateForOrder($orderId);

        $newTotalRefunded = $this->refundRepository->totalRefunded($order->id());
        $fullyRefunded = $newTotalRefunded >= round($order->total(), 2);
        if ($fullyRefunded) {
            $this->orderRepository->transitionStatus($order->id(), 'refunded');
        }

        return [
            'refundId' => $refundId,
            'orderId' => $orderId,
            'amount' => round($amount, 2),
            'currency' => $order->currency(),
            'status' => 'succeeded',
            'fullyRefunded' => $fullyRefunded,
        ];
    }
}
