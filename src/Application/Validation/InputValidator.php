<?php

declare(strict_types=1);

namespace App\Application\Validation;

use Ramsey\Uuid\Uuid;

final class InputValidator
{
    /**
     * @param array<string,mixed> $payload
     */
    public function requireNonEmptyString(array $payload, string $key, string $errorCode): string
    {
        $value = trim((string) ($payload[$key] ?? ''));
        if ($value === '') {
            throw new ValidationException($errorCode);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function optionalTrimmedString(array $payload, string $key): ?string
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null) {
            return null;
        }

        return trim((string) $payload[$key]);
    }

    /**
     * @param array<string,mixed> $payload
     * @return array<int,mixed>
     */
    public function requireArray(array $payload, string $key, string $errorCode): array
    {
        $value = $payload[$key] ?? null;
        if (!is_array($value)) {
            throw new ValidationException($errorCode);
        }

        return array_values($value);
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function requireIntGreaterThanZero(array $payload, string $key, string $errorCode): int
    {
        $raw = $payload[$key] ?? null;
        if (!is_int($raw) && !is_numeric($raw)) {
            throw new ValidationException($errorCode);
        }

        $value = (int) $raw;
        if ($value < 1) {
            throw new ValidationException($errorCode);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function requireUuidString(array $payload, string $key, string $errorCode): string
    {
        $value = $this->requireNonEmptyString($payload, $key, $errorCode);
        if (!Uuid::isValid($value)) {
            throw new ValidationException($errorCode);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function optionalUuidString(array $payload, string $key, string $errorCode): ?string
    {
        $value = $this->optionalTrimmedString($payload, $key);
        if ($value === null || $value === '') {
            return null;
        }
        if (!Uuid::isValid($value)) {
            throw new ValidationException($errorCode);
        }

        return $value;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public function requireEmail(array $payload, string $key, string $errorCode): string
    {
        $value = $this->requireNonEmptyString($payload, $key, $errorCode);
        if (filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new ValidationException($errorCode);
        }

        return strtolower($value);
    }
}
