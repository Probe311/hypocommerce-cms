<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/fill_home_missing_images_remote.php <host> <db> <user> <password> [--dry-run]\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);

function slugify(string $value): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $v = strtolower(trim($value));
    $v = preg_replace('/[^a-z0-9]+/', '-', $v) ?? '';
    $v = trim($v, '-');
    return $v !== '' ? $v : 'image';
}

function downloadAndUpload(
    \PDO $pdo,
    string $query,
    string $slugBase,
    string $alt,
    string $caption,
    int $w,
    int $h,
    int $maxW,
    bool $dryRun
): array {
    if ($dryRun) {
        return ['url' => null, 'alt' => $alt, 'caption' => $caption];
    }

    $downloader = new \App\Application\Cms\UnsplashSourceDownloader();
    $openverseDownloader = new \App\Application\Cms\OpenverseImageDownloader();
    $optimizer = new \App\Application\Cms\ImageOptimizationService();
    $media = new \App\Application\Cms\MediaLibraryService();

    try {
        $downloaded = $downloader->download($query, $w, $h);
    } catch (\Throwable) {
        $downloaded = $openverseDownloader->download($query);
    }

    $optimized = $optimizer->optimizeToWebp((string) $downloaded['path'], $maxW, 82);
    $seoBase = slugify($slugBase);
    $seoPath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $seoBase . '.webp';
    copy((string) $optimized['path'], $seoPath);

    $upload = $media->uploadFromLocalPath($seoPath, [
        'folder' => 'cms-home',
        'title' => 'cms_home_image:' . $slugBase,
        'altText' => $alt,
        'description' => 'cms_home_image:' . $slugBase,
        'caption' => $caption,
        'desiredFilename' => $seoBase,
        'allowRandomSuffix' => false,
    ]);

    return [
        'url' => (string) $upload['url'],
        'alt' => $alt,
        'caption' => $caption,
        'width' => $upload['width'] ?? null,
        'height' => $upload['height'] ?? null,
        'sizeBytes' => $upload['sizeBytes'] ?? null,
    ];
}

try {
    $pdo = pdoFromArgv($argv);
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $st = $pdo->prepare(
        "SELECT s.id AS section_id, s.payload
         FROM cms_page_sections s
         INNER JOIN cms_pages p ON p.id = s.page_id
         WHERE p.slug = 'accueil' AND s.section_key = 'hero'
         LIMIT 1"
    );
    $st->execute();
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        throw new RuntimeException('home_hero_section_not_found');
    }

    $payload = json_decode((string) ($row['payload'] ?? '{}'), true);
    if (!is_array($payload)) {
        $payload = [];
    }

    $promo = downloadAndUpload(
        $pdo,
        'miniature atelier edition limitee ambiance premium',
        'home-edition-limitee',
        'Visuel édition limitée atelier, ambiance premium',
        'Edition limitee, qualite atelier',
        900,
        600,
        900,
        $dryRun
    );

    $training = downloadAndUpload(
        $pdo,
        'formation soclage narratif atelier figurines',
        'home-formation-soclage',
        'Image formation soclage narratif en atelier',
        'Masterclass de soclage narratif',
        1600,
        1100,
        1600,
        $dryRun
    );

    $payload['homePromoImageUrl'] = $promo['url'];
    $payload['homePromoImageAlt'] = $promo['alt'];
    $payload['homePromoImageCaption'] = $promo['caption'];
    $payload['homeTrainingImageUrl'] = $training['url'];
    $payload['homeTrainingImageAlt'] = $training['alt'];
    $payload['homeTrainingImageCaption'] = $training['caption'];

    if (!$dryRun) {
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payloadJson) || $payloadJson === '') {
            throw new RuntimeException('invalid_payload_json');
        }
        $up = $pdo->prepare('UPDATE cms_page_sections SET payload = :payload, updated_at = :now WHERE id = :id');
        $up->execute([
            'payload' => $payloadJson,
            'now' => $now,
            'id' => (int) $row['section_id'],
        ]);
    }

    fwrite(STDOUT, json_encode([
        'dryRun' => $dryRun,
        'sectionId' => (int) $row['section_id'],
        'promo' => $promo,
        'training' => $training,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, 'Fill home missing images failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

