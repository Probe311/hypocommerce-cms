<?php

declare(strict_types=1);

namespace App\Application\Cart;

use App\Application\Coupon\CouponService;
use App\Application\Inventory\InventoryService;
use App\Application\Notification\TransactionalEmailService;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\PdoCartRepository;
use App\Infrastructure\Persistence\PdoOrderRepository;
use App\Infrastructure\Persistence\PdoProductRepository;
use Ramsey\Uuid\Uuid;
use Ramsey\Uuid\UuidInterface;

final class CartService
{
    public function __construct(
        private readonly PdoCartRepository $cartRepository = new PdoCartRepository(),
        private readonly PdoProductRepository $productRepository = new PdoProductRepository(),
        private readonly PdoOrderRepository $orderRepository = new PdoOrderRepository(),
        private readonly InventoryService $inventoryService = new InventoryService(),
        private readonly CouponService $couponService = new CouponService(),
        private readonly TransactionalEmailService $transactionalEmailService = new TransactionalEmailService()
    ) {
    }

    public function getOrCreateCart(string $sessionId): array
    {
        $cart = $this->cartRepository->findBySessionId($sessionId);
        if ($cart === null) {
            $cart = $this->cartRepository->create(Uuid::uuid4(), null, $sessionId);
        }

        return $this->toCartArray($cart->id());
    }

    public function cartWithCoupon(string $sessionId, string $couponCode, ?string $customerRef = null): array
    {
        $cart = $this->cartRepository->findBySessionId($sessionId);
        if ($cart === null) {
            $cart = $this->cartRepository->create(Uuid::uuid4(), null, $sessionId);
        }
        return $this->toCartArray($cart->id(), $couponCode, $customerRef);
    }

    public function addToCart(string $sessionId, string $productId, ?string $variantId, int $quantity): array
    {
        $cart = $this->cartRepository->findBySessionId($sessionId);
        if ($cart === null) {
            $cart = $this->cartRepository->create(Uuid::uuid4(), null, $sessionId);
        }

        $product = $this->productRepository->findById(Uuid::fromString($productId));
        if ($product === null) {
            throw new \RuntimeException('Product not found');
        }

        $this->cartRepository->addItem(
            $cart->id(),
            Uuid::fromString($productId),
            $variantId !== null ? Uuid::fromString($variantId) : null,
            $quantity,
            $product->salePrice() ?? $product->price()
        );

        return $this->toCartArray($cart->id());
    }

    public function updateCartItem(int $cartItemId, int $quantity, string $sessionId): array
    {
        $this->cartRepository->updateItemQuantity($cartItemId, $quantity);
        $cart = $this->cartRepository->findBySessionId($sessionId);
        if ($cart === null) {
            throw new \RuntimeException('Cart not found');
        }

        return $this->toCartArray($cart->id());
    }

    public function removeCartItem(int $cartItemId, string $sessionId): array
    {
        $this->cartRepository->removeItem($cartItemId);
        $cart = $this->cartRepository->findBySessionId($sessionId);
        if ($cart === null) {
            throw new \RuntimeException('Cart not found');
        }

        return $this->toCartArray($cart->id());
    }

    public function checkoutFromSession(string $sessionId, ?string $customerId = null): array
    {
        return $this->checkoutFromSessionDetailed(
            $sessionId,
            $customerId,
            null,
            null,
            null,
            null,
            null,
            null
        );
    }

    /**
     * @param array<string,mixed>|null $shippingAddress
     * @param array<string,mixed>|null $billingAddress
     * @param array<string,mixed>|null $contact
     */
    public function checkoutFromSessionDetailed(
        string $sessionId,
        ?string $customerId = null,
        ?array $shippingAddress = null,
        ?array $billingAddress = null,
        ?array $contact = null,
        ?string $paymentMethod = null,
        ?string $couponCode = null,
        ?string $customerRef = null
    ): array
    {
        $pdo = ConnectionFactory::getConnection();
        $pdo->beginTransaction();
        try {
        $cart = $this->cartRepository->findBySessionId($sessionId);
        if ($cart === null) {
            throw new \RuntimeException('Cart not found');
        }

        $cartData = $this->toCartArray($cart->id(), $couponCode, $customerRef);
        $items = [];
        foreach ($cart->items() as $item) {
            $product = $this->productRepository->findById($item->productId());
            if ($product === null) {
                continue;
            }
            $items[] = new \App\Domain\Order\OrderItem(
                0,
                Uuid::uuid4(),
                $item->productId(),
                $item->variantId(),
                $product->name(),
                $item->quantity(),
                $item->unitPrice(),
                $item->total()
            );
        }

        $orderId = Uuid::uuid4();
        $order = $this->orderRepository->create(
            $orderId,
            'ORD-' . strtoupper(substr(str_replace('-', '', $orderId->toString()), 0, 10)),
            $customerId !== null ? Uuid::fromString($customerId) : null,
            $cartData['subTotal'],
            $cartData['taxTotal'],
            $cartData['shippingTotal'],
            $cartData['discountTotal'],
            $cartData['currency'],
            $paymentMethod,
            'standard',
            $items
        );

        if ($shippingAddress !== null) {
            $shippingAddressWithContact = $this->mergeAddressContact($shippingAddress, $contact);
            $this->orderRepository->upsertOrderAddress($orderId, 'shipping', $shippingAddressWithContact);
        }
        if ($billingAddress !== null) {
            $billingAddressWithContact = $this->mergeAddressContact($billingAddress, $contact);
            $this->orderRepository->upsertOrderAddress($orderId, 'billing', $billingAddressWithContact);
        }
        if ($paymentMethod !== null && $paymentMethod !== '') {
            $this->orderRepository->createOrderPayment(
                $orderId,
                $paymentMethod,
                'pending-' . $orderId->toString(),
                $cartData['total'],
                'pending',
                $contact
            );
        }
        $this->orderRepository->createOrderShipment($orderId, null, 'pending');
        $this->inventoryService->reserveForOrder($orderId->toString());
        if (isset($cartData['coupon']) && is_array($cartData['coupon']) && ($cartData['coupon']['valid'] ?? false) === true) {
            $couponId = (int) ($cartData['coupon']['couponId'] ?? 0);
            if ($couponId > 0 && (float) $cartData['discountTotal'] > 0) {
                $this->couponService->recordRedemption(
                    $couponId,
                    $orderId->toString(),
                    $customerRef,
                    (float) $cartData['discountTotal']
                );
            }
        }

        $result = [
            'id' => $order->id()->toString(),
            'number' => $order->number(),
            'status' => $order->status(),
        ];
        $contactEmail = isset($contact['email']) ? trim((string) $contact['email']) : '';
        if ($contactEmail !== '') {
            $this->transactionalEmailService->send('order_created', $contactEmail, [
                'order_id' => $order->id()->toString(),
                'order_number' => $order->number(),
            ]);
        }
        $pdo->commit();
        return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    public function orderHistory(string $customerId): array
    {
        $orders = $this->orderRepository->findByCustomerId(Uuid::fromString($customerId));

        return array_map(static fn ($o) => [
            'id' => $o->id()->toString(),
            'number' => $o->number(),
            'status' => $o->status(),
        ], $orders);
    }

    private function toCartArray(UuidInterface $cartId, ?string $couponCode = null, ?string $customerRef = null): array
    {
        $cart = $this->cartRepository->findById($cartId);
        if ($cart === null) {
            throw new \RuntimeException('Cart not found');
        }

        $subTotal = 0.0;
        $items = [];
        foreach ($cart->items() as $item) {
            $subTotal += $item->total();
            $items[] = [
                'id' => $item->id(),
                'productId' => $item->productId()->toString(),
                'variantId' => $item->variantId()?->toString(),
                'quantity' => $item->quantity(),
                'unitPrice' => $item->unitPrice(),
                'total' => $item->total(),
            ];
        }

        $taxTotal = round($subTotal * 0.20, 2);
        $shippingTotal = $subTotal > 100 ? 0.0 : 7.90;
        $discountTotal = 0.0;
        $couponResult = null;
        if ($couponCode !== null && trim($couponCode) !== '') {
            $couponResult = $this->couponService->validate($couponCode, $subTotal, $items, $customerRef);
            if (($couponResult['valid'] ?? false) === true) {
                $discountTotal = (float) ($couponResult['discount'] ?? 0.0);
            }
        }
        $total = $subTotal + $taxTotal + $shippingTotal - $discountTotal;

        return [
            'id' => $cart->id()->toString(),
            'currency' => $cart->currency(),
            'items' => $items,
            'subTotal' => $subTotal,
            'taxTotal' => $taxTotal,
            'shippingTotal' => $shippingTotal,
            'discountTotal' => $discountTotal,
            'total' => $total,
            'coupon' => $couponResult,
        ];
    }

    /**
     * @param array<string,mixed> $address
     * @param array<string,mixed>|null $contact
     * @return array<string,mixed>
     */
    private function mergeAddressContact(array $address, ?array $contact): array
    {
        if ($contact === null) {
            return $address;
        }

        if (!isset($address['firstName']) || trim((string) $address['firstName']) === '') {
            $address['firstName'] = (string) ($contact['firstName'] ?? '');
        }
        if (!isset($address['lastName']) || trim((string) $address['lastName']) === '') {
            $address['lastName'] = (string) ($contact['lastName'] ?? '');
        }
        if (!isset($address['phone']) || trim((string) $address['phone']) === '') {
            $address['phone'] = (string) ($contact['phone'] ?? '');
        }

        return $address;
    }
}

