<?php

declare(strict_types=1);

namespace App\Plugins;

use App\Application\Shared\HookDispatcher;

final class ExampleAuditPlugin
{
    public static function register(): void
    {
        HookDispatcher::register('payment.session.created', static function (array $payload): void {
            $dir = dirname(__DIR__) . '/var/log';
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            $line = sprintf(
                "[%s] provider=%s order=%s session=%s status=%s\n",
                date('c'),
                (string) ($payload['provider'] ?? ''),
                (string) ($payload['orderId'] ?? ''),
                (string) ($payload['paymentSessionId'] ?? ''),
                (string) ($payload['status'] ?? '')
            );
            @file_put_contents($dir . '/plugin-payment-events.log', $line, FILE_APPEND);
        });
    }
}
