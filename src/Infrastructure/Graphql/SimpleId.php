<?php

declare(strict_types=1);

namespace App\Infrastructure\Graphql;

use GraphQL\Type\Definition\ScalarType;

final class SimpleId extends ScalarType
{
    public string $name = 'ID';
}
