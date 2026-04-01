<?php

declare(strict_types=1);

namespace App\Infrastructure\Security;

final class RateLimiter
{
    /**
     * @return array{allowed:bool,retryAfter:int}
     */
    public function check(string $scope, string $identifier, int $maxAttempts, int $windowSeconds): array
    {
        $now = time();
        $path = $this->storagePath();
        $data = $this->read($path);

        $key = strtolower(trim($scope . ':' . $identifier));
        $entry = $data[$key] ?? ['count' => 0, 'window_start' => $now];
        $windowStart = (int) ($entry['window_start'] ?? $now);
        $count = (int) ($entry['count'] ?? 0);

        if (($now - $windowStart) >= $windowSeconds) {
            $windowStart = $now;
            $count = 0;
        }

        $count++;
        $data[$key] = [
            'count' => $count,
            'window_start' => $windowStart,
        ];
        $this->write($path, $data);

        if ($count > $maxAttempts) {
            $retryAfter = max(1, $windowSeconds - ($now - $windowStart));
            return ['allowed' => false, 'retryAfter' => $retryAfter];
        }

        return ['allowed' => true, 'retryAfter' => 0];
    }

    private function storagePath(): string
    {
        $root = dirname(__DIR__, 3);
        $dir = $root . '/var/cache';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        return $dir . '/rate_limits.json';
    }

    /**
     * @return array<string,array{count:int,window_start:int}>
     */
    private function read(string $path): array
    {
        if (!is_file($path)) {
            return [];
        }
        $raw = @file_get_contents($path);
        if (!is_string($raw) || trim($raw) === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string,array{count:int,window_start:int}> $data
     */
    private function write(string $path, array $data): void
    {
        @file_put_contents($path, json_encode($data, JSON_UNESCAPED_SLASHES));
    }
}
