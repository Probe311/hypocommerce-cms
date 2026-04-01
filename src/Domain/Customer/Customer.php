<?php

declare(strict_types=1);

namespace App\Domain\Customer;

use Ramsey\Uuid\UuidInterface;

final class Customer
{
    public function __construct(
        private UuidInterface $id,
        private string $email,
        private ?string $firstName,
        private ?string $lastName
    ) {
    }

    public function id(): UuidInterface
    {
        return $this->id;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function firstName(): ?string
    {
        return $this->firstName;
    }

    public function lastName(): ?string
    {
        return $this->lastName;
    }
}
