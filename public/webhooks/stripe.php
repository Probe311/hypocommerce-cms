<?php

declare(strict_types=1);

use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Persistence\PdoIdempotencyRepository;
use App\Infrastructure\Persistence\PdoOrderRepository;
use App\Infrastructure\Persistence\PdoWebhookEventRepository;
use App\Application\Inventory\InventoryService;
use App\Application\Notification\TransactionalEmailService;
use App\Infrastructure\Security\RateLimiter;
use Ramsey\Uuid\Uuid;
use Stripe\Stripe;
use Stripe\Webhook;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/bin/bootstrap.php';

loadBackendEnv();

$payload = file_get_contents('php://input') ?: '';
$limiter = new RateLimiter();
$rate = $limiter->check('webhook_stripe', (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown'), 120, 60);
if ($rate['allowed'] === false) {
    http_response_code(429);
    echo 'rate_limited';
    exit;
}
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
$endpointSecret = $_ENV['STRIPE_WEBHOOK_SECRET'] ?? null;

if ($endpointSecret === null) {
    http_response_code(500);
    echo 'Webhook secret not configured';
    exit;
}

Stripe::setApiKey($_ENV['STRIPE_SECRET_KEY'] ?? '');

try {
    $event = Webhook::constructEvent($payload, $sigHeader, $endpointSecret);
} catch (Throwable $e) {
    http_response_code(400);
    echo 'Invalid payload';
    exit;
}

$eventId = (string) ($event->id ?? '');
$eventType = (string) ($event->type ?? '');
$orderId = extractStripeOrderId($event);
if ($eventId === '' || $orderId === '') {
    http_response_code(400);
    echo 'Missing order reference';
    exit;
}

$webhookEvents = new PdoWebhookEventRepository();
$webhookEvents->registerAttempt('stripe', $eventId, $eventType, [
    'type' => $eventType,
    'orderId' => $orderId,
]);

$idempotencyRepository = new PdoIdempotencyRepository();
$isFirstProcessing = $idempotencyRepository->markOnce('stripe_webhook', $eventId, $orderId);
if ($isFirstProcessing === false) {
    $webhookEvents->markProcessed('stripe', $eventId);
    http_response_code(200);
    echo 'already_processed';
    exit;
}

$targetStatus = match ($eventType) {
    'checkout.session.completed' => 'paid',
    'charge.refunded' => 'refunded',
    'checkout.session.expired', 'checkout.session.async_payment_failed' => 'cancelled',
    default => null,
};
if ($targetStatus === null) {
    $webhookEvents->markProcessed('stripe', $eventId);
    http_response_code(200);
    echo 'ignored';
    exit;
}

$pdo = ConnectionFactory::getConnection();
$pdo->beginTransaction();
try {
    $transitioned = (new PdoOrderRepository())->transitionStatus(Uuid::fromString($orderId), $targetStatus);
    if (!$transitioned) {
        throw new RuntimeException('invalid_status_transition_or_missing_order');
    }
    $inventoryService = new InventoryService();
    if ($targetStatus === 'paid') {
        $inventoryService->confirmForOrder($orderId);
    } elseif ($targetStatus === 'cancelled') {
        $inventoryService->releaseReservationForOrder($orderId);
    } elseif ($targetStatus === 'refunded') {
        $inventoryService->compensateForOrder($orderId);
    }
    $pdo->commit();
    $orderData = (new PdoOrderRepository())->findOrderNotificationData(Uuid::fromString($orderId));
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
    $webhookEvents->markProcessed('stripe', $eventId);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $webhookEvents->markFailed('stripe', $eventId, $e->getMessage());
    http_response_code(409);
    echo 'Order transition failed';
    exit;
}

http_response_code(200);
echo 'ok';

/**
 * @param mixed $event
 */
function extractStripeOrderId($event): string
{
    if (!is_object($event) || !isset($event->data) || !isset($event->data->object)) {
        return '';
    }
    $object = $event->data->object;
    if (is_object($object) && isset($object->client_reference_id) && is_string($object->client_reference_id)) {
        return $object->client_reference_id;
    }
    if (is_object($object) && isset($object->metadata) && is_object($object->metadata)) {
        $metadata = (array) $object->metadata;
        if (isset($metadata['order_id']) && is_string($metadata['order_id'])) {
            return $metadata['order_id'];
        }
    }

    return '';
}

