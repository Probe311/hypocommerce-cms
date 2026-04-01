<?php

declare(strict_types=1);

namespace App\Infrastructure\Logging;

use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;

final class LoggerFactory
{
    private static ?Logger $logger = null;

    public static function getLogger(): Logger
    {
        if (self::$logger instanceof Logger) {
            return self::$logger;
        }

        $logDir = dirname(__DIR__, 3) . '/var/log';
        if (!is_dir($logDir)) {
            mkdir($logDir, 0775, true);
        }

        $logger = new Logger('app');
        $logger->pushHandler(new StreamHandler($logDir . '/app.log', Level::Debug));

        self::$logger = $logger;

        return $logger;
    }
}

