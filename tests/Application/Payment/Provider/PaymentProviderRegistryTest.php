<?php

declare(strict_types=1);

namespace App\Tests\Application\Payment\Provider;

use App\Application\Payment\Provider\PaymentProviderInterface;
use App\Application\Payment\Provider\PaymentProviderRegistry;
use App\Domain\Order\Order;
use PHPUnit\Framework\TestCase;

final class PaymentProviderRegistryTest extends TestCase
{
    public function testRegistryReturnsProviderByKey(): void
    {
        $provider = new class () implements PaymentProviderInterface {
            public function key(): string
            {
                return 'dummy';
            }

            public function createCheckoutSession(Order $order, string $successUrl, string $cancelUrl): array
            {
                return [
                    'id' => 'dummy-id',
                    'url' => $successUrl,
                    'status' => 'initiated',
                ];
            }
        };

        $registry = new PaymentProviderRegistry([$provider]);

        self::assertTrue($registry->has('dummy'));
        self::assertNotNull($registry->get('dummy'));
        self::assertNull($registry->get('missing'));
    }
}
