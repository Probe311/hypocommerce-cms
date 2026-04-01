<?php

declare(strict_types=1);

use App\Infrastructure\Persistence\PdoIdempotencyRepository;
use App\Infrastructure\Persistence\PdoOrderRepository;
use App\Infrastructure\Persistence\PdoWebhookEventRepository;
use App\Application\Inventory\InventoryService;
use App\Application\Notification\TransactionalEmailService;
use App\Infrastructure\Security\RateLimiter;
use Ramsey\Uuid\Uuid;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/bin/bootstrap.php';

loadBackendEnv();

$rawPayload = file_get_contents('php://input') ?: '{}';
$limiter = new RateLimiter();
$rate = $limiter->check('webhook_paypal', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 120, 60);
if ($rate['allowed'] === false) {
    http_response_code(429);
    echo 'rate_limited';
    exit;
}
$payload = json_decode($rawPayload, true);
if (!is_array($payload)) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$eventId = (string) ($payload['id'] ?? '');
$eventType = (string) ($payload['event_type'] ?? '');
$resource = $payload['resource'] ?? null;
if ($eventId === '' || !is_array($resource)) {
    http_response_code(400);
    echo 'Missing event data';
    exit;
}

$orderId = (string) ($resource['custom_id'] ?? $resource['invoice_id'] ?? '');
if ($orderId === '' || !Uuid::isValid($orderId)) {
    http_response_code(400);
    echo 'Missing order reference';
    exit;
}
$webhookEvents = new PdoWebhookEventRepository();
$webhookEvents->registerAttempt('paypal', $eventId, $eventType, [
    'event_type' => $eventType,
    'orderId' => $orderId,
]);

$idempotency = new PdoIdempotencyRepository();
$accepted = $idempotency->markOnce('paypal_webhook', $eventId, $orderId);
if ($accepted === false) {
    $webhookEvents->markProcessed('paypal', $eventId);
    http_response_code(200);
    echo 'already_processed';
    exit;
}

$targetStatus = match ($eventType) {
    'PAYMENT.CAPTURE.COMPLETED', 'CHECKOUT.ORDER.APPROVED' => 'paid',
    'PAYMENT.CAPTURE.REFUNDED' => 'refunded',
    default => null,
};

if ($targetStatus === null) {
    $webhookEvents->markProcessed('paypal', $eventId);
    http_response_code(200);
    echo 'ignored';
    exit;
}

try {
    $repository = new PdoOrderRepository();
    $ok = $repository->transitionStatus(Uuid::fromString($orderId), $targetStatus);
    if (!$ok) {
        throw new RuntimeException('invalid_status_transition_or_missing_order');
    }
    $inventory = new InventoryService();
    if ($targetStatus === 'paid') {
        $inventory->confirmForOrder($orderId);
    }
    if ($targetStatus === 'refunded') {
        $inventory->compensateForOrder($orderId);
    }
    $orderData = $repository->findOrderNotificationData(Uuid::fromString($orderId));
    if (is_array($orderData) && isset($orderData['customer_email']) && is_string($orderData['customer_email']) && $orderData['customer_email'] !== '') {
        $template = $targetStatus === 'paid' ? 'order_paid' : ($targetStatus === 'refunded' ? 'order_refunded' : null);
        if ($template !== null) {
            (new TransactionalEmailService())->send($template, $orderData['customer_email'], [
                'order_id' => $orderId,
                'order_number' => (string) ($orderData['number'] ?? ''),
                'status' => $targetStatus,
            ]);
        }
    }
    $webhookEvents->markProcessed('paypal', $eventId);
} catch (Throwable $e) {
    $webhookEvents->markFailed('paypal', $eventId, $e->getMessage());
    http_response_code(409);
    echo 'Order transition failed';
    exit;
}

http_response_code(200);
echo 'ok';
