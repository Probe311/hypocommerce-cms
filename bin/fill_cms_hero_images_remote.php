<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/fill_cms_hero_images_remote.php <host> <db> <user> <password> [--dry-run] [--limit <n>] [--output-log <path>]\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$limit = 200;
$outputLog = null;

for ($i = 5; $i < $argc; $i++) {
    $arg = $argv[$i] ?? '';
    if ($arg === '--limit' && isset($argv[$i + 1]) && is_numeric((string) $argv[$i + 1])) {
        $limit = max(1, (int) $argv[$i + 1]);
        $i++;
        continue;
    }
    if ($arg === '--output-log' && isset($argv[$i + 1])) {
        $outputLog = (string) $argv[$i + 1];
        $i++;
        continue;
    }
}

function hasImageUrlInPayload(array $payload): bool
{
    $keys = ['image', 'imageUrl', 'backgroundImage', 'mediaUrl', 'heroImage', 'heroImageUrl'];
    foreach ($keys as $k) {
        if (isset($payload[$k]) && is_string($payload[$k]) && trim((string) $payload[$k]) !== '') {
            return true;
        }
    }
    return false;
}

function slugify(string $value): string
{
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($ascii) && $ascii !== '') {
        $value = $ascii;
    }
    $v = strtolower(trim($value));
    $v = preg_replace('/[^a-z0-9]+/', '-', $v) ?? '';
    $v = trim($v, '-');
    return $v !== '' ? $v : 'hero';
}

try {
    $pdo = pdoFromArgv($argv);
    $downloader = new \App\Application\Cms\UnsplashSourceDownloader();
    $openverseDownloader = new \App\Application\Cms\OpenverseImageDownloader();
    $optimizer = new \App\Application\Cms\ImageOptimizationService();
    $media = new \App\Application\Cms\MediaLibraryService();
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $st = $pdo->prepare(
        "SELECT s.id AS section_id, s.payload, p.id AS page_id, p.slug, p.title
         FROM cms_page_sections s
         INNER JOIN cms_pages p ON p.id = s.page_id
         WHERE p.status = 'published' AND s.section_key = 'hero'
         ORDER BY p.id ASC
         LIMIT :lim"
    );
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($rows)) {
        $rows = [];
    }

    $processed = 0;
    $updated = 0;
    $logs = [];

    foreach ($rows as $row) {
        $processed++;
        $payloadRaw = (string) ($row['payload'] ?? '');
        $payload = json_decode($payloadRaw, true);
        if (!is_array($payload)) {
            $payload = [];
        }

        if (hasImageUrlInPayload($payload)) {
            continue;
        }

        $pageSlug = (string) ($row['slug'] ?? '');
        $pageTitle = (string) ($row['title'] ?? '');
        $query = trim($pageTitle . ' ' . $pageSlug . ' artisanal atelier texture');

        $newUrl = null;
        $newMediaId = null;
        if (!$dryRun) {
            try {
                $downloaded = $downloader->download($query, 1200, 800);
            } catch (\Throwable) {
                // Fallback when Unsplash Source is unavailable from current network.
                $downloaded = $openverseDownloader->download($query);
            }
            $optimized = $optimizer->optimizeToWebp((string) $downloaded['path'], 1600, 82);
            $seoBaseName = sprintf(
                '%s-%s',
                slugify($pageSlug !== '' ? $pageSlug : 'page'),
                slugify($pageTitle !== '' ? $pageTitle : 'hero')
            );
            $seoExtension = strtolower(pathinfo((string) $optimized['path'], PATHINFO_EXTENSION));
            if ($seoExtension === '') {
                $seoExtension = 'webp';
            }
            $seoPath = dirname((string) $optimized['path']) . DIRECTORY_SEPARATOR . $seoBaseName . '.' . $seoExtension;
            copy((string) $optimized['path'], $seoPath);

            $imageAlt = $pageTitle !== '' ? ('Illustration hero - ' . $pageTitle) : 'Illustration hero';
            $imageCaption = 'Visuel hero SEO pour ' . ($pageTitle !== '' ? $pageTitle : $pageSlug);
            $upload = $media->uploadFromLocalPath($seoPath, [
                'folder' => 'cms-hero',
                'title' => 'cms_hero_autofill:' . ($pageSlug !== '' ? $pageSlug : 'page'),
                'altText' => $imageAlt,
                'description' => 'cms_hero_autofill:' . $pageSlug,
                'caption' => $imageCaption,
                'desiredFilename' => $seoBaseName,
                'allowRandomSuffix' => false,
            ]);
            $newUrl = (string) $upload['url'];
            $newMediaId = (int) $upload['id'];

            $payload['imageUrl'] = $newUrl;
            $payload['imageAlt'] = $imageAlt;
            $payload['imageCaption'] = $imageCaption;
            $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if (!is_string($payloadJson) || $payloadJson === '') {
                continue;
            }
            $up = $pdo->prepare('UPDATE cms_page_sections SET payload = :p, updated_at = :now WHERE id = :id');
            $up->execute(['p' => $payloadJson, 'now' => $now, 'id' => (int) $row['section_id']]);
            $updated++;
        }

        $logs[] = [
            'pageId' => (int) ($row['page_id'] ?? 0),
            'pageSlug' => $pageSlug,
            'pageTitle' => $pageTitle,
            'mediaId' => $newMediaId,
            'url' => $newUrl,
            'dryRun' => $dryRun,
        ];
    }

    $summary = [
        'dryRun' => $dryRun,
        'processedHeroSections' => $processed,
        'updatedHeroSections' => $updated,
        'items' => $logs,
    ];

    if ($outputLog !== null && $outputLog !== '') {
        @file_put_contents($outputLog, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, 'Fill failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

