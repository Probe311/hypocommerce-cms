<?php

declare(strict_types=1);

namespace App\Domain\Product;

final class Category
{
    public function __construct(
        private int $id,
        private ?int $parentId,
        private string $name,
        private string $slug
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function parentId(): ?int
    {
        return $this->parentId;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function slug(): string
    {
        return $this->slug;
    }
}
