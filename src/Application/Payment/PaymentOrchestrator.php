<?php

declare(strict_types=1);

namespace App\Application\Payment;

use App\Application\Payment\Provider\PaymentProviderRegistry;
use App\Application\Shared\HookDispatcher;
use App\Infrastructure\Payment\Provider\PaypalCheckoutPaymentProvider;
use App\Infrastructure\Payment\Provider\StripeCheckoutPaymentProvider;
use App\Infrastructure\Persistence\PdoAdminPluginRepository;
use App\Infrastructure\Persistence\PdoOrderRepository;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class PaymentOrchestrator
{
    public function __construct(
        private readonly PdoOrderRepository $orderRepository = new PdoOrderRepository(),
        ?PaymentProviderRegistry $providerRegistry = null
    ) {
        $this->providerRegistry = $providerRegistry ?? new PaymentProviderRegistry([
            new StripeCheckoutPaymentProvider(),
            new PaypalCheckoutPaymentProvider(),
        ]);
    }

    private PaymentProviderRegistry $providerRegistry;

    /**
     * @return array{id:string,provider:string,url:string,status:string}
     */
    public function startPayment(
        string $orderId,
        ?string $provider,
        string $successUrl,
        string $cancelUrl
    ): array {
        if (!Uuid::isValid($orderId)) {
            throw new RuntimeException('invalid_order_id');
        }
        $order = $this->orderRepository->findById(Uuid::fromString($orderId));
        if ($order === null) {
            throw new RuntimeException('order_not_found');
        }
        if (!in_array($order->status(), ['pending', 'authorized'], true)) {
            throw new RuntimeException('order_not_payable');
        }

        $resolvedProvider = strtolower(trim((string) ($provider ?? $order->paymentMethod() ?? '')));
        if (!in_array($resolvedProvider, ['stripe', 'paypal'], true)) {
            throw new RuntimeException('invalid_payment_provider');
        }
        if (!(new PdoAdminPluginRepository())->isEnabled($resolvedProvider)) {
            throw new RuntimeException('payment_provider_disabled');
        }

        if (!$this->providerRegistry->has($resolvedProvider)) {
            throw new RuntimeException('invalid_payment_provider');
        }
        $provider = $this->providerRegistry->get($resolvedProvider);
        if ($provider === null) {
            throw new RuntimeException('invalid_payment_provider');
        }

        // Idempotence pragmatique: si une session récente est déjà initiée/authorized, on la réutilise.
        $latest = $this->orderRepository->findLatestOrderPaymentByProvider($order->id(), $resolvedProvider);
        if (is_array($latest)) {
            $latestStatus = strtolower((string) ($latest['status'] ?? ''));
            if (in_array($latestStatus, ['initiated', 'authorized'], true)) {
                $raw = is_array($latest['raw_payload'] ?? null) ? $latest['raw_payload'] : [];
                $existingUrl = (string) ($raw['checkout_url'] ?? ($raw['approval_url'] ?? ''));
                if ($existingUrl !== '') {
                    return [
                        'id' => (string) ($latest['provider_ref'] ?? ''),
                        'provider' => $resolvedProvider,
                        'url' => $existingUrl,
                        'status' => $latestStatus,
                    ];
                }
            }
        }

        $session = $provider->createCheckoutSession($order, $successUrl, $cancelUrl);

        $this->orderRepository->createOrderPayment(
            $order->id(),
            $resolvedProvider,
            (string) $session['id'],
            $order->total(),
            (string) $session['status'],
            isset($session['metadata']) && is_array($session['metadata']) ? $session['metadata'] : []
        );

        HookDispatcher::dispatch('payment.session.created', [
            'provider' => $resolvedProvider,
            'orderId' => $order->id()->toString(),
            'paymentSessionId' => (string) $session['id'],
            'status' => (string) $session['status'],
        ]);

        return [
            'id' => (string) $session['id'],
            'provider' => $resolvedProvider,
            'url' => (string) $session['url'],
            'status' => (string) $session['status'],
        ];
    }
}
