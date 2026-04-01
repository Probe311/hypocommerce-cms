<?php

declare(strict_types=1);

namespace App\Application\Auth;

use RuntimeException;

final class JwtService
{
    public function createToken(string $subject, int $ttlSeconds = 86400): string
    {
        $secret = $this->secret();
        $now = time();
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = [
            'sub' => $subject,
            'iat' => $now,
            'exp' => $now + $ttlSeconds,
        ];

        $headerPart = $this->base64UrlEncode(json_encode($header, JSON_UNESCAPED_SLASHES) ?: '{}');
        $payloadPart = $this->base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');
        $signature = hash_hmac('sha256', $headerPart . '.' . $payloadPart, $secret, true);
        $signaturePart = $this->base64UrlEncode($signature);

        return $headerPart . '.' . $payloadPart . '.' . $signaturePart;
    }

    public function verifyAndGetSubject(string $token): string
    {
        $parts = explode('.', trim($token));
        if (count($parts) !== 3) {
            throw new RuntimeException('invalid_token');
        }
        [$headerPart, $payloadPart, $signaturePart] = $parts;

        $expected = $this->base64UrlEncode(hash_hmac('sha256', $headerPart . '.' . $payloadPart, $this->secret(), true));
        if (!hash_equals($expected, $signaturePart)) {
            throw new RuntimeException('invalid_token_signature');
        }

        $payloadRaw = $this->base64UrlDecode($payloadPart);
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            throw new RuntimeException('invalid_token_payload');
        }
        $exp = (int) ($payload['exp'] ?? 0);
        if ($exp < time()) {
            throw new RuntimeException('token_expired');
        }
        $sub = (string) ($payload['sub'] ?? '');
        if ($sub === '') {
            throw new RuntimeException('invalid_token_subject');
        }

        return $sub;
    }

    private function secret(): string
    {
        $secret = (string) ($_ENV['JWT_SECRET'] ?? '');
        if ($secret === '') {
            throw new RuntimeException('JWT_SECRET is not configured');
        }

        return $secret;
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): string
    {
        $padding = 4 - (strlen($value) % 4);
        if ($padding < 4) {
            $value .= str_repeat('=', $padding);
        }
        return base64_decode(strtr($value, '-_', '+/')) ?: '';
    }
}
