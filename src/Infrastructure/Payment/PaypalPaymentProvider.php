<?php

declare(strict_types=1);

namespace App\Infrastructure\Payment;

use RuntimeException;

final class PaypalPaymentProvider
{
    private string $clientId;
    private string $clientSecret;
    private string $apiBase;
    private string $webhookId;

    public function __construct(
        private readonly PluginPaymentConfigResolver $configResolver = new PluginPaymentConfigResolver()
    )
    {
        $runtime = $this->configResolver->resolve('paypal');
        $cfg = $runtime['config'];
        $mode = strtolower((string) ($runtime['mode'] ?? 'sandbox'));

        $this->clientId = trim((string) ($cfg['paypalClientId'] ?? ($_ENV['PAYPAL_CLIENT_ID'] ?? '')));
        $this->clientSecret = trim((string) ($cfg['paypalClientSecret'] ?? ($_ENV['PAYPAL_CLIENT_SECRET'] ?? '')));
        $this->webhookId = trim((string) ($cfg['paypalWebhookId'] ?? ($_ENV['PAYPAL_WEBHOOK_ID'] ?? '')));

        $defaultBase = $mode === 'live'
            ? 'https://api-m.paypal.com'
            : 'https://api-m.sandbox.paypal.com';
        $this->apiBase = rtrim((string) ($cfg['paypalApiBase'] ?? ($_ENV['PAYPAL_API_BASE'] ?? $defaultBase)), '/');

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
        $accessToken = $this->createAccessToken();
        $payload = [
            'intent' => 'CAPTURE',
            'purchase_units' => [[
                'reference_id' => $orderId,
                'custom_id' => $orderId,
                'amount' => [
                    'currency_code' => strtoupper($currency),
                    'value' => number_format($amount, 2, '.', ''),
                ],
            ]],
            'application_context' => [
                'return_url' => $returnUrl,
                'cancel_url' => $cancelUrl,
                'user_action' => 'PAY_NOW',
            ],
        ];
        $order = $this->httpJson(
            'POST',
            '/v2/checkout/orders',
            $payload,
            [
                'Authorization: Bearer ' . $accessToken,
                'PayPal-Request-Id: ' . $orderId,
            ]
        );

        $approvalUrl = '';
        if (isset($order['links']) && is_array($order['links'])) {
            foreach ($order['links'] as $link) {
                if (!is_array($link)) {
                    continue;
                }
                if (strtolower((string) ($link['rel'] ?? '')) === 'approve') {
                    $approvalUrl = (string) ($link['href'] ?? '');
                    break;
                }
            }
        }
        if ($approvalUrl === '') {
            throw new RuntimeException('PayPal approval URL not found');
        }

        return [
            'id' => (string) ($order['id'] ?? ''),
            'approvalUrl' => $approvalUrl,
        ];
    }

    /**
     * @param array<string,mixed> $headers
     * @param array<string,mixed> $eventPayload
     */
    public function verifyWebhook(array $headers, array $eventPayload): bool
    {
        if ($this->webhookId === '') {
            // Si non configuré, on ne peut pas vérifier la signature côté PayPal.
            return false;
        }
        $accessToken = $this->createAccessToken();
        $payload = [
            'transmission_id' => (string) ($headers['paypal-transmission-id'] ?? ''),
            'transmission_time' => (string) ($headers['paypal-transmission-time'] ?? ''),
            'cert_url' => (string) ($headers['paypal-cert-url'] ?? ''),
            'auth_algo' => (string) ($headers['paypal-auth-algo'] ?? ''),
            'transmission_sig' => (string) ($headers['paypal-transmission-sig'] ?? ''),
            'webhook_id' => $this->webhookId,
            'webhook_event' => $eventPayload,
        ];
        $out = $this->httpJson(
            'POST',
            '/v1/notifications/verify-webhook-signature',
            $payload,
            ['Authorization: Bearer ' . $accessToken]
        );
        return strtoupper((string) ($out['verification_status'] ?? '')) === 'SUCCESS';
    }

    private function createAccessToken(): string
    {
        $ch = curl_init($this->apiBase . '/v1/oauth2/token');
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize PayPal OAuth request');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_USERPWD => $this->clientId . ':' . $this->clientSecret,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => 'grant_type=client_credentials',
            CURLOPT_TIMEOUT => 25,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('PayPal OAuth failed: empty response' . ($err !== '' ? ' (' . $err . ')' : ''));
        }
        $json = json_decode($raw, true);
        if (!is_array($json) || $code < 200 || $code >= 300) {
            throw new RuntimeException('PayPal OAuth failed: HTTP ' . $code);
        }
        $token = trim((string) ($json['access_token'] ?? ''));
        if ($token === '') {
            throw new RuntimeException('PayPal OAuth failed: missing access token');
        }
        return $token;
    }

    /**
     * @param array<string,mixed> $payload
     * @param list<string> $headers
     * @return array<string,mixed>
     */
    private function httpJson(string $method, string $path, array $payload, array $headers = []): array
    {
        $url = $this->apiBase . $path;
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize PayPal request');
        }
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            throw new RuntimeException('Unable to encode PayPal payload');
        }
        $baseHeaders = ['Content-Type: application/json'];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => array_merge($baseHeaders, $headers),
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_TIMEOUT => 30,
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($raw) || $raw === '') {
            throw new RuntimeException('PayPal request failed: empty response' . ($err !== '' ? ' (' . $err . ')' : ''));
        }
        $json = json_decode($raw, true);
        if (!is_array($json)) {
            throw new RuntimeException('PayPal request failed: invalid JSON response');
        }
        if ($code < 200 || $code >= 300) {
            $msg = (string) ($json['message'] ?? ('HTTP ' . $code));
            throw new RuntimeException('PayPal API error: ' . $msg);
        }
        return $json;
    }
}
