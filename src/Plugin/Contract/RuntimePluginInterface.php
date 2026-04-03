<?php

declare(strict_types=1);

namespace App\Plugin\Contract;

interface RuntimePluginInterface
{
    public function key(): string;

    public function type(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    /**
     * @param array<string,mixed> $config
     * @return array{ok:bool,errors:list<string>}
     */
    public function validateConfig(array $config, string $mode): array;
}

