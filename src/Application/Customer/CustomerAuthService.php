<?php

declare(strict_types=1);

namespace App\Application\Customer;

use App\Application\Auth\JwtService;
use App\Application\Notification\TransactionalEmailService;
use App\Infrastructure\Persistence\PdoCustomerRepository;
use Ramsey\Uuid\Uuid;
use RuntimeException;

final class CustomerAuthService
{
    public function __construct(
        private readonly PdoCustomerRepository $customerRepository = new PdoCustomerRepository(),
        private readonly JwtService $jwtService = new JwtService(),
        private readonly TransactionalEmailService $transactionalEmailService = new TransactionalEmailService()
    ) {
    }

    /**
     * @return array{customerId:string,token:string}
     */
    public function register(string $email, string $password, ?string $firstName, ?string $lastName): array
    {
        $existing = $this->customerRepository->findByEmail($email);
        if ($existing !== null) {
            throw new RuntimeException('email_already_exists');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('password_hash_failed');
        }
        $customerId = $this->customerRepository->create($email, $hash, $firstName, $lastName);
        $token = $this->jwtService->createToken($customerId);
        $this->transactionalEmailService->send('account_created', $email, [
            'customer_id' => $customerId,
            'first_name' => (string) ($firstName ?? ''),
        ]);

        return ['customerId' => $customerId, 'token' => $token];
    }

    /**
     * @return array{customerId:string,token:string}
     */
    public function login(string $email, string $password): array
    {
        $customer = $this->customerRepository->findByEmail($email);
        if ($customer === null) {
            throw new RuntimeException('invalid_credentials');
        }
        $passwordHash = (string) ($customer['password_hash'] ?? '');
        if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
            throw new RuntimeException('invalid_credentials');
        }

        $customerId = (string) $customer['id'];
        $this->customerRepository->updateLastLogin($customerId);
        return [
            'customerId' => $customerId,
            'token' => $this->jwtService->createToken($customerId),
        ];
    }

    public function verifyCustomerToken(string $token): string
    {
        $customerId = $this->jwtService->verifyAndGetSubject($token);
        if (!Uuid::isValid($customerId)) {
            throw new RuntimeException('invalid_customer_token');
        }
        return $customerId;
    }

    public function requestPasswordReset(string $email): string
    {
        $customer = $this->customerRepository->findByEmail($email);
        if ($customer === null) {
            // Do not leak account existence.
            return 'ok';
        }
        $plainToken = bin2hex(random_bytes(24));
        $tokenHash = hash('sha256', $plainToken);
        $this->customerRepository->createPasswordReset((string) $customer['id'], $tokenHash, 3600);
        $this->transactionalEmailService->send('password_reset_requested', (string) $customer['email'], [
            'token' => $plainToken,
            'customer_id' => (string) $customer['id'],
        ]);
        return $plainToken;
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $tokenHash = hash('sha256', $token);
        $reset = $this->customerRepository->findValidPasswordReset($tokenHash);
        if ($reset === null) {
            return false;
        }
        $hash = password_hash($newPassword, PASSWORD_BCRYPT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('password_hash_failed');
        }
        $this->customerRepository->updatePassword((string) $reset['customer_id'], $hash);
        $this->customerRepository->markPasswordResetUsed((int) $reset['id']);
        $customer = $this->customerRepository->findById(Uuid::fromString((string) $reset['customer_id']));
        if (is_array($customer) && isset($customer['email'])) {
            $this->transactionalEmailService->send('password_reset_done', (string) $customer['email'], [
                'customer_id' => (string) $reset['customer_id'],
            ]);
        }
        return true;
    }
}
