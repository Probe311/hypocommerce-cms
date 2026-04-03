<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Infrastructure\Payment\PluginPaymentConfigResolver;
use App\Infrastructure\Payment\PaypalPaymentProvider;
use App\Infrastructure\Persistence\PdoOrderRepository;
use App\Infrastructure\Persistence\PdoWebhookEventRepository;
use Ramsey\Uuid\Uuid;
use Stripe\Webhook;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class PaymentWebhookController
{
    public function handleStripe(Request $request): Response
    {
        $raw = (string) $request->getContent();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'invalid_payload'], 400);
        }
        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['type'] ?? '');
        if ($eventId === '' || $eventType === '') {
            return $this->json(['error' => 'invalid_event'], 400);
        }

        $repo = new PdoWebhookEventRepository();
        if ($repo->isProcessed('stripe', $eventId)) {
            return $this->json(['ok' => true, 'duplicate' => true], 200);
        }
        $repo->registerAttempt('stripe', $eventId, $eventType, $payload);

        try {
            $sig = (string) $request->headers->get('Stripe-Signature', '');
            $runtime = (new PluginPaymentConfigResolver())->resolve('stripe');
            $cfg = $runtime['config'];
            $secret = trim((string) ($cfg['stripeWebhookSecret'] ?? ($_ENV['STRIPE_WEBHOOK_SECRET'] ?? '')));
            if ($secret === '' || $sig === '') {
                throw new \RuntimeException('missing_stripe_signature_or_secret');
            }
            Webhook::constructEvent($raw, $sig, $secret);

            if ($eventType === 'checkout.session.completed') {
                /** @var array<string,mixed> $object */
                $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
                $providerRef = trim((string) ($object['id'] ?? ''));
                $orderId = trim((string) ($object['client_reference_id'] ?? ($object['metadata']['order_id'] ?? '')));
                if ($providerRef !== '') {
                    (new PdoOrderRepository())->updatePaymentStatusByProviderRef('stripe', $providerRef, 'paid', $payload);
                }
                if ($orderId !== '' && Uuid::isValid($orderId)) {
                    (new PdoOrderRepository())->transitionStatus(Uuid::fromString($orderId), 'paid');
                }
            } elseif ($eventType === 'checkout.session.expired') {
                /** @var array<string,mixed> $object */
                $object = is_array($payload['data']['object'] ?? null) ? $payload['data']['object'] : [];
                $providerRef = trim((string) ($object['id'] ?? ''));
                if ($providerRef !== '') {
                    (new PdoOrderRepository())->updatePaymentStatusByProviderRef('stripe', $providerRef, 'failed', $payload);
                }
            }

            $repo->markProcessed('stripe', $eventId);
            return $this->json(['ok' => true], 200);
        } catch (\Throwable $e) {
            $repo->markFailed('stripe', $eventId, $e->getMessage());
            return $this->json(['error' => 'webhook_failed'], 400);
        }
    }

    public function handlePaypal(Request $request): Response
    {
        $raw = (string) $request->getContent();
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'invalid_payload'], 400);
        }
        $eventId = (string) ($payload['id'] ?? '');
        $eventType = (string) ($payload['event_type'] ?? '');
        if ($eventId === '' || $eventType === '') {
            return $this->json(['error' => 'invalid_event'], 400);
        }
        $repo = new PdoWebhookEventRepository();
        if ($repo->isProcessed('paypal', $eventId)) {
            return $this->json(['ok' => true, 'duplicate' => true], 200);
        }
        $repo->registerAttempt('paypal', $eventId, $eventType, $payload);

        try {
            $headers = [];
            foreach ($request->headers->all() as $k => $vals) {
                $headers[strtolower($k)] = is_array($vals) ? (string) ($vals[0] ?? '') : (string) $vals;
            }

            $provider = new PaypalPaymentProvider();
            $verified = $provider->verifyWebhook($headers, $payload);
            if (!$verified) {
                throw new \RuntimeException('invalid_paypal_signature');
            }

            if (in_array($eventType, ['CHECKOUT.ORDER.APPROVED', 'PAYMENT.CAPTURE.COMPLETED'], true)) {
                /** @var array<string,mixed> $resource */
                $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
                $providerRef = trim((string) ($resource['id'] ?? ''));

                $orderId = '';
                if (isset($resource['purchase_units']) && is_array($resource['purchase_units']) && isset($resource['purchase_units'][0]) && is_array($resource['purchase_units'][0])) {
                    $orderId = trim((string) (($resource['purchase_units'][0]['custom_id'] ?? ($resource['purchase_units'][0]['reference_id'] ?? ''))));
                }

                if ($providerRef !== '') {
                    (new PdoOrderRepository())->updatePaymentStatusByProviderRef('paypal', $providerRef, 'paid', $payload);
                }
                if ($orderId !== '' && Uuid::isValid($orderId)) {
                    (new PdoOrderRepository())->transitionStatus(Uuid::fromString($orderId), 'paid');
                }
            } elseif ($eventType === 'PAYMENT.CAPTURE.DENIED') {
                /** @var array<string,mixed> $resource */
                $resource = is_array($payload['resource'] ?? null) ? $payload['resource'] : [];
                $providerRef = trim((string) ($resource['id'] ?? ''));
                if ($providerRef !== '') {
                    (new PdoOrderRepository())->updatePaymentStatusByProviderRef('paypal', $providerRef, 'failed', $payload);
                }
            }

            $repo->markProcessed('paypal', $eventId);
            return $this->json(['ok' => true], 200);
        } catch (\Throwable $e) {
            $repo->markFailed('paypal', $eventId, $e->getMessage());
            return $this->json(['error' => 'webhook_failed'], 400);
        }
    }

    /**
     * @param mixed $payload
     */
    private function json($payload, int $status = 200): Response
    {
        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }
}

