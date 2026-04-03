<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/enrich_products_safe.php <host> <db> <user> <password> [json_path]\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];
$jsonPath = $argv[5] ?? dirname(__DIR__, 2) . '/seo-suppliers/donnees/produits/produits-normalises-dedup.json';

if (!is_file($jsonPath)) {
    fwrite(STDERR, "JSON produits introuvable: {$jsonPath}\n");
    exit(1);
}

$raw = file_get_contents($jsonPath);
if ($raw === false) {
    fwrite(STDERR, "Impossible de lire le JSON produits.\n");
    exit(1);
}

/** @var mixed $decoded */
$decoded = json_decode($raw, true);
if (!is_array($decoded)) {
    fwrite(STDERR, "JSON produits invalide.\n");
    exit(1);
}

function slugifySafe(string $value): string
{
    $value = mb_strtolower($value, 'UTF-8');
    $translit = @iconv('UTF-8', 'ASCII//TRANSLIT', $value);
    if ($translit !== false) {
        $value = $translit;
    }
    $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
    return trim($value, '-');
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $stats = [
        'source_rows' => 0,
        'matched' => 0,
        'updated' => 0,
        'ignored_no_match' => 0,
        'ignored_no_description' => 0,
        'ignored_already_filled' => 0,
        'rejected_invalid_row' => 0,
    ];

    $findBySku = $pdo->prepare('SELECT id, description FROM products WHERE sku = :sku LIMIT 1');
    $findBySlug = $pdo->prepare('SELECT id, description FROM products WHERE slug = :slug LIMIT 1');
    $update = $pdo->prepare('UPDATE products SET description = :description, updated_at = :updated_at WHERE id = :id');

    $pdo->beginTransaction();
    foreach ($decoded as $row) {
        $stats['source_rows']++;
        if (!is_array($row)) {
            $stats['rejected_invalid_row']++;
            continue;
        }

        $description = trim((string) ($row['description'] ?? ''));
        if ($description === '') {
            $stats['ignored_no_description']++;
            continue;
        }

        $sku = trim((string) ($row['sku'] ?? ''));
        $reference = trim((string) ($row['reference'] ?? ''));
        $name = trim((string) ($row['product_name'] ?? ''));
        $candidate = null;

        if ($sku !== '') {
            $findBySku->execute(['sku' => $sku]);
            $candidate = $findBySku->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($candidate) && $reference !== '') {
            $findBySku->execute(['sku' => $reference]);
            $candidate = $findBySku->fetch(PDO::FETCH_ASSOC);
        }
        if (!is_array($candidate) && $name !== '') {
            $slug = slugifySafe($name);
            if ($slug !== '') {
                $findBySlug->execute(['slug' => $slug]);
                $candidate = $findBySlug->fetch(PDO::FETCH_ASSOC);
            }
        }

        if (!is_array($candidate)) {
            $stats['ignored_no_match']++;
            continue;
        }

        $stats['matched']++;
        $existingDescription = trim((string) ($candidate['description'] ?? ''));
        if ($existingDescription !== '') {
            $stats['ignored_already_filled']++;
            continue;
        }

        $update->execute([
            'id' => $candidate['id'],
            'description' => $description,
            'updated_at' => $now,
        ]);
        $stats['updated']++;
    }
    $pdo->commit();

    fwrite(STDOUT, "Enrichissement produits safe termine.\n");
    foreach ($stats as $k => $v) {
        fwrite(STDOUT, "{$k}: {$v}\n");
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, 'Enrichissement produits echec: ' . $e->getMessage() . "\n");
    exit(1);
}
