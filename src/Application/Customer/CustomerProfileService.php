<?php

declare(strict_types=1);

namespace App\Application\Customer;

use App\Infrastructure\Persistence\PdoCustomerRepository;

final class CustomerProfileService
{
    public function __construct(private readonly PdoCustomerRepository $customerRepository = new PdoCustomerRepository())
    {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function profile(string $customerId): ?array
    {
        return $this->customerRepository->findById(\Ramsey\Uuid\Uuid::fromString($customerId));
    }

    public function updateProfile(string $customerId, ?string $firstName, ?string $lastName): void
    {
        $this->customerRepository->updateProfile($customerId, $firstName, $lastName);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function addresses(string $customerId): array
    {
        return $this->customerRepository->listAddresses($customerId);
    }

    /**
     * @param array<string,mixed> $address
     */
    public function upsertAddress(string $customerId, array $address): int
    {
        return $this->customerRepository->upsertAddress($customerId, $address);
    }

    public function deleteAddress(string $customerId, int $addressId): bool
    {
        return $this->customerRepository->deleteAddress($customerId, $addressId);
    }
}
