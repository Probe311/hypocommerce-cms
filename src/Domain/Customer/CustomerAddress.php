<?php

declare(strict_types=1);

namespace App\Domain\Customer;

final class CustomerAddress
{
    public function __construct(
        private int $id,
        private string $customerId,
        private string $type,
        private string $line1,
        private ?string $line2,
        private string $city,
        private string $postcode,
        private ?string $state,
        private string $country,
        private ?string $phone,
        private ?string $label
    ) {
    }

    public function id(): int
    {
        return $this->id;
    }

    public function customerId(): string
    {
        return $this->customerId;
    }

    public function type(): string
    {
        return $this->type;
    }

    public function line1(): string
    {
        return $this->line1;
    }

    public function line2(): ?string
    {
        return $this->line2;
    }

    public function city(): string
    {
        return $this->city;
    }

    public function postcode(): string
    {
        return $this->postcode;
    }

    public function state(): ?string
    {
        return $this->state;
    }

    public function country(): string
    {
        return $this->country;
    }

    public function phone(): ?string
    {
        return $this->phone;
    }

    public function label(): ?string
    {
        return $this->label;
    }
}
