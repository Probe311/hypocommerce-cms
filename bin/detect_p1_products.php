<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/detect_p1_products.php <host> <db> <user> <password> [output_json]\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];
$outputPath = $argv[5] ?? dirname(__DIR__) . '/var/reports/p1_products.json';

$keywords = [
    'bundle',
    'pack',
    'set',
    'starter',
    'most wanted',
    'box',
    'kit',
];

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $rows = $pdo->query("SELECT id, sku, name, slug, type, description FROM products WHERE status = 'published'")
        ->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    $p1 = [];
    foreach ($rows as $row) {
        $name = mb_strtolower((string) ($row['name'] ?? ''), 'UTF-8');
        $isBundleType = ((string) ($row['type'] ?? '')) === 'bundle';
        $hasKeyword = false;
        foreach ($keywords as $kw) {
            if (str_contains($name, $kw)) {
                $hasKeyword = true;
                break;
            }
        }
        if ($isBundleType || $hasKeyword) {
            $reason = $isBundleType ? 'type=bundle' : 'keyword';
            $p1[] = [
                'id' => $row['id'],
                'sku' => $row['sku'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'reason' => $reason,
                'has_description' => trim((string) ($row['description'] ?? '')) !== '',
            ];
        }
    }

    $dir = dirname($outputPath);
    if (!is_dir($dir)) {
        mkdir($dir, 0777, true);
    }
    file_put_contents(
        $outputPath,
        json_encode(
            [
                'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
                'count' => count($p1),
                'items' => $p1,
            ],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
        )
    );

    fwrite(STDOUT, "Detection P1 terminee.\n");
    fwrite(STDOUT, "count: " . count($p1) . "\n");
    fwrite(STDOUT, "output: {$outputPath}\n");
} catch (Throwable $e) {
    fwrite(STDERR, "Echec detection P1: " . $e->getMessage() . "\n");
    exit(1);
}
