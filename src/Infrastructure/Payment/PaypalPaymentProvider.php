<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use RuntimeException;

final class PaypalPaymentProvider
{
    private string $clientId;
    private string $clientSecret;

    public function __construct()
    {
        $this->clientId = (string) ($_ENV['PAYPAL_CLIENT_ID'] ?? '');
        $this->clientSecret = (string) ($_ENV['PAYPAL_CLIENT_SECRET'] ?? '');

        if ($this->clientId === '' || $this->clientSecret === '') {
            throw new RuntimeException('PayPal credentials are not configured');
        }
    }

    /**
     * @return array{id:string,approvalUrl:string}
     */
    public function createCheckoutSession(
        string $orderId,
        float $amount,
        string $currency,
        string $returnUrl,
        string $cancelUrl
    ): array {
        $baseUrl = (string) ($_ENV['PAYPAL_CHECKOUT_BASE_URL'] ?? '');
        $token = 'pp_' . bin2hex(random_bytes(12));
        $query = http_build_query([
            'token' => $token,
            'order' => $orderId,
            'amount' => number_format($amount, 2, '.', ''),
            'currency' => strtoupper($currency),
            'return_url' => $returnUrl,
            'cancel_url' => $cancelUrl,
        ]);

        // Fallback dev URL if no hosted checkout base URL is configured yet.
        $approvalUrl = $baseUrl !== ''
            ? rtrim($baseUrl, '/') . '?' . $query
            : rtrim($returnUrl, '/') . '?paypal_simulated=1&' . $query;

        return [
            'id' => $token,
            'approvalUrl' => $approvalUrl,
        ];
    }
}
