<?php

declare(strict_types=1);

namespace App\Application\Shared;

final class HookDispatcher
{
    /**
     * @var array<string,list<callable(array<string,mixed>):void>>
     */
    private static array $listeners = [];
    private static bool $initialized = false;

    /**
     * @param callable(array<string,mixed>):void $listener
     */
    public static function register(string $event, callable $listener): void
    {
        self::$listeners[$event] ??= [];
        self::$listeners[$event][] = $listener;
    }

    /**
     * @param array<string,mixed> $payload
     */
    public static function dispatch(string $event, array $payload): void
    {
        self::bootstrapFromConfig();
        foreach (self::$listeners[$event] ?? [] as $listener) {
            $listener($payload);
        }
    }

    private static function bootstrapFromConfig(): void
    {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        $configPath = dirname(__DIR__, 3) . '/config/hooks.php';
        if (!is_file($configPath)) {
            return;
        }
        $hooks = require $configPath;
        if (!is_array($hooks)) {
            return;
        }
        foreach ($hooks as $event => $listeners) {
            if (!is_string($event) || !is_array($listeners)) {
                continue;
            }
            foreach ($listeners as $listener) {
                if (is_callable($listener)) {
                    self::register($event, $listener);
                }
            }
        }
    }
}
