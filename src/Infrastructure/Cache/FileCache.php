<?php

declare(strict_types=1);

namespace App\Infrastructure\Cache;

final class FileCache
{
    /**
     * @return mixed
     */
    public function get(string $key)
    {
        $path = $this->pathForKey($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        $expiresAt = (int) ($decoded['expires_at'] ?? 0);
        if ($expiresAt < time()) {
            @unlink($path);
            return null;
        }
        return $decoded['value'] ?? null;
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value, int $ttlSeconds): void
    {
        $path = $this->pathForKey($key);
        $payload = [
            'expires_at' => time() + max(1, $ttlSeconds),
            'value' => $value,
        ];
        @file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function pathForKey(string $key): string
    {
        $root = dirname(__DIR__, 3);
        $dir = $root . '/var/cache/app';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . '/' . hash('sha256', $key) . '.json';
    }
}
