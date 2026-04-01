<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use DateTimeImmutable;

final class StructuredLogger
{
    /**
     * @param array<string,mixed> $context
     */
    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    /**
     * @param array<string,mixed> $context
     */
    private function write(string $level, string $message, array $context): void
    {
        $root = dirname(__DIR__, 3);
        $dir = $root . '/var/log';
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        $line = [
            'timestamp' => (new DateTimeImmutable())->format(DATE_ATOM),
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
        @file_put_contents(
            $dir . '/app-structured.log',
            json_encode($line, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
            FILE_APPEND
        );
    }
}
