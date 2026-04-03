<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/list_remote_media_urls.php <host> <db> <user> <password> [limit]\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];
$limit = isset($argv[5]) && is_numeric((string) $argv[5]) ? max(1, (int) $argv[5]) : 100;

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    $st = $pdo->prepare('SELECT id, url, alt_text, media_type, metadata FROM media_assets ORDER BY id DESC LIMIT :l');
    $st->bindValue(':l', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        echo (int) $r['id'] . ' | ' . (string) $r['url'] . ' | ' . (string) ($r['alt_text'] ?? '') . PHP_EOL;
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'List failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

