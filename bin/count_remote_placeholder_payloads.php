<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/count_remote_placeholder_payloads.php <host> <db> <user> <password>\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];

$motifs = ['placehold', 'placeholder', 'dummy', 'no-image', 'image_placeholder'];

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    foreach ($motifs as $m) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM cms_page_sections WHERE LOWER(CAST(payload AS CHAR)) LIKE :n');
        $st->execute(['n' => '%' . strtolower($m) . '%']);
        echo $m . ':' . (int) $st->fetchColumn() . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Count failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

