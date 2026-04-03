<?php

declare(strict_types=1);

use App\Application\Auth\JwtService;
use App\Infrastructure\Database\ConnectionFactory;

require __DIR__ . '/bootstrap.php';

loadBackendEnv();
$pdo = ConnectionFactory::createNewConnection();
$bootstrapAdmin = in_array('--bootstrap-admin', $argv, true);

$stmt = $pdo->query(
    "SELECT id, role
     FROM admin_users
     WHERE role IN ('super_admin','admin')
     ORDER BY (role = 'super_admin') DESC, id ASC
     LIMIT 1"
);
$row = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($row)) {
    if (!$bootstrapAdmin) {
        fwrite(STDERR, "No admin user found.\n");
        exit(1);
    }
    $email = trim((string) ($_ENV['SEO_SMOKE_ADMIN_EMAIL'] ?? 'seo-smoke-admin@hypocommerce.local'));
    $password = trim((string) ($_ENV['SEO_SMOKE_ADMIN_PASSWORD'] ?? 'SeoSmoke!2026#Strong'));
    $hash = password_hash($password, PASSWORD_BCRYPT);
    if (!is_string($hash) || $hash === '') {
        fwrite(STDERR, "Unable to hash bootstrap admin password.\n");
        exit(1);
    }
    $insert = $pdo->prepare(
        'INSERT INTO admin_users (email, password_hash, role, created_at, last_login_at)
         VALUES (:email, :password_hash, :role, :created_at, NULL)'
    );
    $insert->execute([
        'email' => strtolower($email),
        'password_hash' => $hash,
        'role' => 'super_admin',
        'created_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);
    $row = [
        'id' => (int) $pdo->lastInsertId(),
        'role' => 'super_admin',
    ];
    fwrite(STDOUT, "[BOOTSTRAP_ADMIN_CREATED] {$email}\n");
}

$userId = (int) ($row['id'] ?? 0);
$role = (string) ($row['role'] ?? '');
if ($userId < 1 || $role === '') {
    fwrite(STDERR, "Invalid admin user row.\n");
    exit(1);
}

$subject = sprintf('admin:%d:%s', $userId, $role);
$token = (new JwtService())->createToken($subject, 3600);
fwrite(STDOUT, $token . PHP_EOL);

