<?php

declare(strict_types=1);

namespace App\Application\Admin;

use App\Application\Auth\JwtService;
use App\Infrastructure\Persistence\PdoAdminUserRepository;
use RuntimeException;

final class AdminAuthService
{
    public function __construct(
        private readonly PdoAdminUserRepository $adminUserRepository = new PdoAdminUserRepository(),
        private readonly JwtService $jwtService = new JwtService()
    ) {
    }

    /**
     * @return array{token:string,userId:int,role:string,email:string}
     */
    public function login(string $email, string $password): array
    {
        $user = $this->adminUserRepository->findByEmail($email);
        if ($user === null) {
            throw new RuntimeException('invalid_admin_credentials');
        }
        $passwordHash = (string) ($user['password_hash'] ?? '');
        if ($passwordHash === '' || !password_verify($password, $passwordHash)) {
            throw new RuntimeException('invalid_admin_credentials');
        }

        $userId = (int) $user['id'];
        $role = (string) $user['role'];
        if (!in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            throw new RuntimeException('invalid_admin_role');
        }
        $this->adminUserRepository->updateLastLogin($userId);

        // Subject format: admin:<id>:<role>
        $token = $this->jwtService->createToken(sprintf('admin:%d:%s', $userId, $role), 8 * 3600);

        return [
            'token' => $token,
            'userId' => $userId,
            'role' => $role,
            'email' => (string) $user['email'],
        ];
    }

    /**
     * @return array{userId:int,role:string}
     */
    public function verifyBearer(string $token): array
    {
        $subject = $this->jwtService->verifyAndGetSubject($token);
        if (!str_starts_with($subject, 'admin:')) {
            throw new RuntimeException('invalid_admin_token_subject');
        }

        $parts = explode(':', $subject);
        if (count($parts) !== 3) {
            throw new RuntimeException('invalid_admin_token_subject');
        }

        $userId = (int) $parts[1];
        $role = (string) $parts[2];
        if ($userId < 1 || !in_array($role, ['super_admin', 'admin', 'manager'], true)) {
            throw new RuntimeException('invalid_admin_token_subject');
        }

        $user = $this->adminUserRepository->findById($userId);
        if ($user === null) {
            throw new RuntimeException('admin_not_found');
        }
        if ((string) $user['role'] !== $role) {
            throw new RuntimeException('admin_role_changed');
        }

        return [
            'userId' => $userId,
            'role' => $role,
        ];
    }
}
