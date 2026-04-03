<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/normalize_existing_cms_images_remote.php <host> <db> <user> <password> [--dry-run] [--limit <n>] [--output-log <path>]\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$limit = 1000;
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

/**
 * @return array{slug:string,title:string}|null
 */
function findContext(PDO $pdo, string $needle): ?array
{
    if ($needle === '') {
        return null;
    }
    $st = $pdo->prepare(
        'SELECT p.slug, p.title
         FROM cms_page_sections s
         INNER JOIN cms_pages p ON p.id = s.page_id
         WHERE LOWER(CAST(s.payload AS CHAR)) LIKE :needle
         LIMIT 1'
    );
    $st->execute(['needle' => '%' . strtolower($needle) . '%']);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return [
        'slug' => (string) ($row['slug'] ?? ''),
        'title' => (string) ($row['title'] ?? ''),
    ];
}

function isAlreadyNormalized(string $filenameNoExt): bool
{
    // old patterns usually ended with long random/hex suffix.
    return !preg_match('/-[a-f0-9]{8,}$|-[0-9]{10,}$/i', $filenameNoExt);
}

try {
    $pdo = pdoFromArgv($argv);
    $mediaService = new \App\Application\Cms\MediaLibraryService();
    $optimizer = new \App\Application\Cms\ImageOptimizationService();
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $hasCmsMediaLibrary = false;
    $hasMediaAssets = false;
    $hasCmsMediaUsages = false;
    $hasBlogArticles = false;
    foreach ([
        'cms_media_library' => 'hasCmsMediaLibrary',
        'media_assets' => 'hasMediaAssets',
        'cms_media_usages' => 'hasCmsMediaUsages',
        'blog_articles' => 'hasBlogArticles',
    ] as $table => $varName) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t");
        $st->execute(['t' => $table]);
        ${$varName} = ((int) $st->fetchColumn()) > 0;
    }

    if (!$hasCmsMediaLibrary && !$hasMediaAssets) {
        throw new RuntimeException('No media table found');
    }

    if ($hasCmsMediaLibrary) {
        $rows = $pdo->query(
            'SELECT id, url, path, filename, extension, mime_type, width, height, folder, title, alt_text, caption, description
             FROM cms_media_library
             WHERE status = "active" AND url LIKE "/uploads/cms/%"
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query(
            'SELECT id, url, alt_text, media_type, metadata
             FROM media_assets
             WHERE media_type = "image" AND url LIKE "/uploads/cms/%"
             ORDER BY id ASC'
        )->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$row) {
            $meta = json_decode((string) ($row['metadata'] ?? '{}'), true);
            if (!is_array($meta)) {
                $meta = [];
            }
            $row['path'] = (string) ($meta['path'] ?? '');
            $row['filename'] = (string) ($meta['filename'] ?? '');
            $row['extension'] = (string) ($meta['extension'] ?? '');
            $row['mime_type'] = (string) ($meta['mime_type'] ?? '');
            $row['width'] = is_numeric($meta['width'] ?? null) ? (int) $meta['width'] : null;
            $row['height'] = is_numeric($meta['height'] ?? null) ? (int) $meta['height'] : null;
            $row['folder'] = (string) ($meta['folder'] ?? 'general');
            $row['title'] = (string) ($meta['title'] ?? '');
            $row['caption'] = (string) ($meta['caption'] ?? '');
            $row['description'] = (string) ($meta['description'] ?? '');
        }
        unset($row);
    }

    if (!is_array($rows)) {
        $rows = [];
    }

    $replaceMap = [];
    $mapping = [];
    $oldUrlsForCleanup = [];
    $processed = 0;

    foreach ($rows as $row) {
        if ($processed >= $limit) {
            break;
        }
        $oldId = (int) ($row['id'] ?? 0);
        if ($oldId < 1) {
            continue;
        }
        $oldUrl = (string) ($row['url'] ?? '');
        $oldFilename = (string) ($row['filename'] ?? '');
        $oldExtension = (string) ($row['extension'] ?? '');
        $oldFolder = trim((string) ($row['folder'] ?? 'general')) ?: 'general';

        $hasUsage = false;
        if ($hasCmsMediaUsages) {
            $u = $pdo->prepare('SELECT COUNT(*) FROM cms_media_usages WHERE media_id = :id');
            $u->execute(['id' => $oldId]);
            $hasUsage = ((int) $u->fetchColumn()) > 0;
        }
        if (!$hasUsage && $hasBlogArticles) {
            $u = $pdo->prepare('SELECT COUNT(*) FROM blog_articles WHERE featured_media_id = :id');
            $u->execute(['id' => $oldId]);
            $hasUsage = ((int) $u->fetchColumn()) > 0;
        }
        if (!$hasUsage && $oldUrl !== '') {
            $u = $pdo->prepare('SELECT COUNT(*) FROM cms_page_sections WHERE LOWER(CAST(payload AS CHAR)) LIKE :needle');
            $u->execute(['needle' => '%' . strtolower($oldUrl) . '%']);
            $hasUsage = ((int) $u->fetchColumn()) > 0;
        }
        if (!$hasUsage) {
            continue;
        }

        $baseName = pathinfo($oldFilename, PATHINFO_FILENAME);
        if ($baseName !== '' && isAlreadyNormalized((string) $baseName)) {
            continue;
        }

        $ctx = findContext($pdo, $oldUrl !== '' ? $oldUrl : $oldFilename);
        $ctxSlug = slugify((string) ($ctx['slug'] ?? 'page'));
        $ctxTitle = slugify((string) ($ctx['title'] ?? 'image'));
        $desiredFilename = $ctxSlug . '-' . $ctxTitle;

        $oldPath = (string) ($row['path'] ?? '');
        $localSourcePath = '';
        if ($oldPath !== '' && is_file($oldPath)) {
            $localSourcePath = $oldPath;
        } elseif ($oldUrl !== '') {
            $candidate = dirname(__DIR__) . '/var' . $oldUrl;
            if (is_file($candidate)) {
                $localSourcePath = $candidate;
            }
        }
        if ($localSourcePath === '') {
            $mapping[] = [
                'oldMediaId' => $oldId,
                'oldUrl' => $oldUrl,
                'skipped' => 'source_not_found',
            ];
            continue;
        }

        $maxW = ((int) ($row['width'] ?? 0)) > 0 ? min(2000, max(1200, (int) ($row['width'] ?? 1200))) : 1600;
        $optimized = $optimizer->optimizeToWebp($localSourcePath, $maxW, 82);
        $upload = null;
        if (!$dryRun) {
            $upload = $mediaService->uploadFromLocalPath((string) $optimized['path'], [
                'folder' => $oldFolder,
                'title' => $row['title'] ?? null,
                'altText' => $row['alt_text'] ?? null,
                'description' => $row['description'] ?? null,
                'caption' => $row['caption'] ?? null,
                'desiredFilename' => $desiredFilename,
                'allowRandomSuffix' => false,
            ]);
        }

        $newId = !$dryRun && is_array($upload) ? (int) ($upload['id'] ?? 0) : null;
        $newUrl = !$dryRun && is_array($upload) ? (string) ($upload['url'] ?? '') : ('/uploads/cms/' . $oldFolder . '/' . $desiredFilename . '.webp');

        if ($oldUrl !== '' && $newUrl !== '' && $oldUrl !== $newUrl) {
            $replaceMap[$oldUrl] = $newUrl;
            $oldUrlsForCleanup[] = $oldUrl;
        }

        $mapping[] = [
            'oldMediaId' => $oldId,
            'oldUrl' => $oldUrl,
            'newMediaId' => $newId,
            'newUrl' => $newUrl,
            'desiredFilename' => $desiredFilename,
            'sourcePath' => $localSourcePath,
        ];
        $processed++;
    }

    $updatedSections = 0;
    if (!$dryRun && $replaceMap !== []) {
        foreach ($mapping as $m) {
            $oldId = (int) ($m['oldMediaId'] ?? 0);
            $newId = (int) ($m['newMediaId'] ?? 0);
            if ($oldId < 1 || $newId < 1) {
                continue;
            }
            if ($hasCmsMediaUsages) {
                $u = $pdo->prepare('UPDATE cms_media_usages SET media_id = :newId WHERE media_id = :oldId');
                $u->execute(['newId' => $newId, 'oldId' => $oldId]);
            }
            if ($hasBlogArticles) {
                $u = $pdo->prepare('UPDATE blog_articles SET featured_media_id = :newId, updated_at = :now WHERE featured_media_id = :oldId');
                $u->execute(['newId' => $newId, 'oldId' => $oldId, 'now' => $now]);
            }
        }

        $keys = array_keys($replaceMap);
        $vals = array_values($replaceMap);
        $sections = $pdo->query('SELECT id, payload FROM cms_page_sections')->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($sections)) {
            $sections = [];
        }
        foreach ($sections as $s) {
            $sid = (int) ($s['id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            $decoded = json_decode((string) ($s['payload'] ?? ''), true);
            if (!is_array($decoded)) {
                continue;
            }
            $changed = false;
            $walk = function ($node) use (&$walk, $keys, $vals, &$changed) {
                if (is_string($node)) {
                    $replaced = str_replace($keys, $vals, $node);
                    if ($replaced !== $node) {
                        $changed = true;
                    }
                    return $replaced;
                }
                if (is_array($node)) {
                    foreach ($node as $k => $v) {
                        $node[$k] = $walk($v);
                    }
                }
                return $node;
            };
            $decoded = $walk($decoded);
            if ($changed) {
                $payload = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($payload) && $payload !== '') {
                    $u = $pdo->prepare('UPDATE cms_page_sections SET payload = :payload, updated_at = :now WHERE id = :id');
                    $u->execute(['payload' => $payload, 'now' => $now, 'id' => $sid]);
                    $updatedSections++;
                }
            }
        }

        $idsToDelete = array_values(array_filter(array_map(static function (array $m): int {
            return (int) ($m['oldMediaId'] ?? 0);
        }, $mapping)));
        if ($idsToDelete !== []) {
            $placeholders = implode(',', array_fill(0, count($idsToDelete), '?'));
            if ($hasCmsMediaLibrary) {
                $q = $pdo->prepare("UPDATE cms_media_library SET status = 'archived', updated_at = ? WHERE id IN ($placeholders)");
                $q->execute(array_merge([$now], $idsToDelete));
            }
            if ($hasMediaAssets) {
                $q = $pdo->prepare("DELETE FROM media_assets WHERE id IN ($placeholders)");
                $q->execute($idsToDelete);
            }
        }
    }

    $summary = [
        'dryRun' => $dryRun,
        'processed' => $processed,
        'updatedSections' => $updatedSections,
        'replaceMapCount' => count($replaceMap),
        'oldUrlsForCleanup' => array_values(array_unique($oldUrlsForCleanup)),
        'mapping' => $mapping,
    ];

    if ($outputLog !== null && $outputLog !== '') {
        @file_put_contents($outputLog, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
} catch (Throwable $e) {
    fwrite(STDERR, 'Normalize failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

