<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

loadBackendEnv();

$errors = [];
$warnings = [];

$requiredVars = [
    'APP_ENV',
    'APP_DEBUG',
    'APP_URL',
    'APP_SECRET',
    'DB_HOST',
    'DB_PORT',
    'DB_NAME',
    'DB_USER',
    'JWT_SECRET',
    'ADMIN_API_TOKEN',
];

foreach ($requiredVars as $var) {
    $value = (string) ($_ENV[$var] ?? '');
    if ($value === '') {
        $errors[] = "Missing env var: {$var}";
    }
}

$appEnv = strtolower((string) ($_ENV['APP_ENV'] ?? ''));
$appDebug = (string) ($_ENV['APP_DEBUG'] ?? '');
$jwtSecret = (string) ($_ENV['JWT_SECRET'] ?? '');
$adminToken = (string) ($_ENV['ADMIN_API_TOKEN'] ?? '');
$appSecret = (string) ($_ENV['APP_SECRET'] ?? '');

if (!in_array($appEnv, ['prod', 'production', 'staging'], true)) {
    $warnings[] = "APP_ENV should be prod/production/staging for release (current: {$appEnv}).";
}
if ($appDebug !== '0') {
    $errors[] = 'APP_DEBUG must be 0 in production.';
}
if ($jwtSecret === 'change-me' || strlen($jwtSecret) < 24) {
    $errors[] = 'JWT_SECRET is weak/default. Use a strong secret (>=24 chars).';
}
if ($adminToken === 'change-me-admin-token' || strlen($adminToken) < 24) {
    $errors[] = 'ADMIN_API_TOKEN is weak/default. Use a strong token (>=24 chars).';
}
if ($appSecret === 'change-me-app-secret' || strlen($appSecret) < 24) {
    $errors[] = 'APP_SECRET is weak/default. Use a strong secret (>=24 chars).';
}

try {
    $pdo = pdoFromArgv($argv);
    $requiredTables = [
        'products',
        'orders',
        'pages',
        'cms_pages',
        'blog_articles',
        'faq_items',
        'legal_pages',
        'schema_migrations',
    ];
    $stmt = $pdo->query('SHOW TABLES');
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $existing = [];
    foreach ($tables as $table) {
        if (is_string($table)) {
            $existing[$table] = true;
        }
    }

    foreach ($requiredTables as $table) {
        if (!isset($existing[$table])) {
            $errors[] = "Missing table: {$table}";
        }
    }

    $row = $pdo->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
    if ((int) $row === 0) {
        $warnings[] = 'schema_migrations is empty. Run `php bin/migrate_versioned.php`.';
    }
} catch (Throwable $e) {
    $errors[] = 'DB connection/check failed: ' . $e->getMessage();
}

foreach ($warnings as $warning) {
    fwrite(STDOUT, "[WARN] {$warning}\n");
}
foreach ($errors as $error) {
    fwrite(STDERR, "[ERROR] {$error}\n");
}

if ($errors !== []) {
    fwrite(STDERR, "Preflight failed.\n");
    exit(1);
}

fwrite(STDOUT, "Preflight passed.\n");
