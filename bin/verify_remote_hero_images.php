<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/verify_remote_hero_images.php <host> <db> <user> <password>\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $mediaCount = (int) $pdo->query('SELECT COUNT(*) FROM media_assets')->fetchColumn();
    $heroRows = $pdo->query(
        "SELECT p.slug, s.payload
         FROM cms_page_sections s
         INNER JOIN cms_pages p ON p.id = s.page_id
         WHERE s.section_key = 'hero' AND p.status = 'published'
         ORDER BY p.id ASC"
    )->fetchAll(PDO::FETCH_ASSOC);

    $heroWithImage = 0;
    $examples = [];
    foreach ($heroRows as $row) {
        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            continue;
        }
        $imageUrl = (string) ($payload['imageUrl'] ?? '');
        if ($imageUrl !== '') {
            $heroWithImage++;
            if (count($examples) < 10) {
                $examples[] = ['slug' => $row['slug'], 'imageUrl' => $imageUrl];
            }
        }
    }

    echo json_encode([
        'mediaAssetsCount' => $mediaCount,
        'heroSectionsCount' => is_array($heroRows) ? count($heroRows) : 0,
        'heroWithImageUrlCount' => $heroWithImage,
        'examples' => $examples,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Verify failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

