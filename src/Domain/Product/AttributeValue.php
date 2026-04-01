<?php

declare(strict_types=1);

namespace App\Domain\Product;

final class AttributeValue
{
    public function __construct(
        private int $id,
        private int $attributeId,
        private string $value,
        private string $slug
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function attributeId(): int
    {
        return $this->attributeId;
    }

    public function value(): string
    {
        return $this->value;
    }

    public function slug(): string
    {
        return $this->slug;
    }
}

