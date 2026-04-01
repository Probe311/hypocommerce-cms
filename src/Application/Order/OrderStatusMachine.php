<?php

declare(strict_types=1);

namespace App\Application\Order;

final class OrderStatusMachine
{
    /**
     * @var array<string, list<string>>
     */
    private const TRANSITIONS = [
        'pending' => ['authorized', 'paid', 'cancelled'],
        'authorized' => ['paid', 'cancelled'],
        'paid' => ['fulfilled', 'cancelled', 'refunded'],
        'fulfilled' => ['refunded'],
        'cancelled' => [],
        'refunded' => [],
    ];

    public function canTransition(string $fromStatus, string $toStatus): bool
    {
        if ($fromStatus === $toStatus) {
            return true;
        }

        $allowedTargets = self::TRANSITIONS[$fromStatus] ?? null;
        if ($allowedTargets === null) {
            return false;
        }

        return in_array($toStatus, $allowedTargets, true);
    }
}
