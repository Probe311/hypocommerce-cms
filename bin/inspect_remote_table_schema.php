<?php

declare(strict_types=1);

if ($argc < 6) {
    fwrite(STDERR, "Usage: php bin/inspect_remote_table_schema.php <host> <db> <user> <password> <table1> [table2 ...]\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];
$tables = array_slice($argv, 5);

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    foreach ($tables as $table) {
        echo "--- {$table} ---" . PHP_EOL;
        $stmt = $pdo->query('DESCRIBE `' . str_replace('`', '``', $table) . '`');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            echo $row['Field'] . ' | ' . $row['Type'] . PHP_EOL;
        }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Inspect failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

