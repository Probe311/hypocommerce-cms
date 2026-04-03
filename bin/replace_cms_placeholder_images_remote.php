<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/replace_cms_placeholder_images_remote.php <host> <db> <user> <password> [--dry-run] [--limit <n>] [--only-host <motif>] [--output-log <path>]\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$onlyHostMotif = 'placehold.co';
$limit = 200;
$outputLog = null;

for ($i = 5; $i < $argc; $i++) {
    $arg = $argv[$i] ?? '';
    if ($arg === '--only-host' && isset($argv[$i + 1])) {
        $onlyHostMotif = (string) $argv[$i + 1];
        $i++;
        continue;
    }
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

/**
 * @param array<int,string> $motifs
 * @param array<int,array<string,mixed>> $rows
 * @return array<int,array<string,mixed>>
 */
function filterRowsByMotifs(array $rows, array $motifs): array
{
    $out = [];
    foreach ($rows as $r) {
        $hay = strtolower(
            (string) ($r['url'] ?? '') . ' ' .
            (string) ($r['path'] ?? '') . ' ' .
            (string) ($r['filename'] ?? '') . ' ' .
            (string) ($r['alt_text'] ?? '') . ' ' .
            (string) ($r['metadata'] ?? '')
        );
        foreach ($motifs as $m) {
            if ($m !== '' && str_contains($hay, strtolower($m))) {
                $out[] = $r;
                break;
            }
        }
    }
    return $out;
}

function pickTargetDimensions(int $w, int $h): array
{
    if ($w > 0 && $h > 0) {
        if ($w >= $h) {
            return [1200, 800, min(1600, max(1200, $w))];
        }
        return [800, 1200, min(1200, max(900, $w))];
    }
    return [1200, 800, 1600];
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
    $stmt = $pdo->prepare(
        'SELECT p.slug, p.title
         FROM cms_page_sections s
         INNER JOIN cms_pages p ON p.id = s.page_id
         WHERE LOWER(CAST(s.payload AS CHAR)) LIKE :n
         LIMIT 1'
    );
    $stmt->execute(['n' => '%' . strtolower($needle) . '%']);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return ['slug' => (string) ($row['slug'] ?? ''), 'title' => (string) ($row['title'] ?? '')];
}

try {
    $pdo = pdoFromArgv($argv);

    $motifs = ['placehold', 'placeholder', 'dummy', 'no-image', 'image_placeholder', $onlyHostMotif];
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    // schema detection
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
        throw new RuntimeException('No media table found (cms_media_library or media_assets)');
    }

    if ($hasCmsMediaLibrary) {
        $rows = $pdo->query(
            'SELECT id, url, path, filename, extension, mime_type, width, height, folder, title, alt_text, description
             FROM cms_media_library
             WHERE status = "active"
             ORDER BY id DESC'
        )->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $rows = $pdo->query(
            'SELECT id, url, alt_text, media_type, metadata
             FROM media_assets
             ORDER BY id DESC'
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
            $row['description'] = (string) ($meta['description'] ?? '');
        }
        unset($row);
    }

    if (!is_array($rows)) {
        $rows = [];
    }
    $candidates = array_slice(filterRowsByMotifs($rows, $motifs), 0, $limit);

    $mediaService = new \App\Application\Cms\MediaLibraryService();
    $downloader = new \App\Application\Cms\UnsplashSourceDownloader();
    $optimizer = new \App\Application\Cms\ImageOptimizationService();

    $mapping = [];
    $replaceMap = [];
    $processed = 0;

    foreach ($candidates as $cand) {
        $oldId = (int) ($cand['id'] ?? 0);
        if ($oldId < 1) {
            continue;
        }
        $oldUrl = (string) ($cand['url'] ?? '');
        $oldPath = (string) ($cand['path'] ?? '');
        $oldFilename = (string) ($cand['filename'] ?? '');
        $oldFolder = trim((string) ($cand['folder'] ?? 'general')) ?: 'general';

        // is used?
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
        if (!$hasUsage) {
            $needle = $oldUrl !== '' ? $oldUrl : ($oldFilename !== '' ? $oldFilename : $oldPath);
            if ($needle !== '') {
                $u = $pdo->prepare('SELECT COUNT(*) FROM cms_page_sections WHERE LOWER(CAST(payload AS CHAR)) LIKE :n');
                $u->execute(['n' => '%' . strtolower($needle) . '%']);
                $hasUsage = ((int) $u->fetchColumn()) > 0;
            }
        }
        if (!$hasUsage) {
            continue;
        }

        $marker = 'cms_placeholder_replaced:media_id=' . $oldId;

        $existing = null;
        if ($hasCmsMediaLibrary) {
            $st = $pdo->prepare('SELECT id, url FROM cms_media_library WHERE status = "active" AND (title = :m OR description = :m) LIMIT 1');
            $st->execute(['m' => $marker]);
            $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        } else {
            $st = $pdo->prepare('SELECT id, url FROM media_assets WHERE metadata LIKE :m LIMIT 1');
            $st->execute(['m' => '%"description":"' . $marker . '"%']);
            $existing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }

        $contextNeedle = $oldUrl !== '' ? $oldUrl : ($oldFilename !== '' ? $oldFilename : $oldPath);
        $ctx = findContext($pdo, $contextNeedle);
        $query = $ctx ? trim($ctx['title'] . ' ' . $ctx['slug']) : ($oldFilename !== '' ? $oldFilename : 'atelier artisanat');

        $newId = null;
        $newUrl = null;
        if (is_array($existing) && isset($existing['id'], $existing['url'])) {
            $newId = (int) $existing['id'];
            $newUrl = (string) $existing['url'];
        } elseif (!$dryRun) {
            [$dw, $dh, $maxW] = pickTargetDimensions((int) ($cand['width'] ?? 0), (int) ($cand['height'] ?? 0));
            $download = $downloader->download($query, $dw, $dh);
            $optimized = $optimizer->optimizeToWebp((string) $download['path'], $maxW, 82);
            $ctxSlug = isset($ctx['slug']) ? (string) $ctx['slug'] : 'page';
            $ctxTitle = isset($ctx['title']) ? (string) $ctx['title'] : 'Image CMS';
            $seoBaseName = sprintf('%s-%s', slugify($ctxSlug), slugify($ctxTitle));
            $seoExtension = strtolower(pathinfo((string) $optimized['path'], PATHINFO_EXTENSION));
            if ($seoExtension === '') {
                $seoExtension = 'webp';
            }
            $seoPath = dirname((string) $optimized['path']) . DIRECTORY_SEPARATOR . $seoBaseName . '.' . $seoExtension;
            copy((string) $optimized['path'], $seoPath);
            $upload = $mediaService->uploadFromLocalPath($seoPath, [
                'folder' => $oldFolder,
                'title' => $marker,
                'altText' => 'Illustration hero - ' . $ctxTitle,
                'description' => $marker,
                'caption' => 'Visuel hero SEO pour ' . $ctxTitle,
                'desiredFilename' => $seoBaseName,
                'allowRandomSuffix' => false,
            ]);
            $newId = (int) $upload['id'];
            $newUrl = (string) $upload['url'];
        }

        $mapping[] = [
            'oldMediaId' => $oldId,
            'oldUrl' => $oldUrl,
            'oldPath' => $oldPath,
            'oldFilename' => $oldFilename,
            'context' => $ctx,
            'newMediaId' => $newId,
            'newUrl' => $newUrl,
        ];

        if (is_int($newId) && is_string($newUrl) && $newUrl !== '') {
            if ($oldUrl !== '') {
                $replaceMap[$oldUrl] = $newUrl;
            }
            if ($oldPath !== '') {
                $replaceMap[$oldPath] = $newUrl;
            }
            if ($oldFilename !== '') {
                $replaceMap[$oldFilename] = $newUrl;
            }
        }
        $processed++;
    }

    if ($dryRun) {
        $out = ['dryRun' => true, 'processed' => $processed, 'mapping' => $mapping];
        fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
        exit(0);
    }

    if ($replaceMap !== []) {
        // Update direct media references
        foreach ($mapping as $m) {
            if (!is_int($m['newMediaId'] ?? null)) {
                continue;
            }
            $oldId = (int) $m['oldMediaId'];
            $newId = (int) $m['newMediaId'];
            if ($hasCmsMediaUsages) {
                $u = $pdo->prepare('UPDATE cms_media_usages SET media_id = :newId WHERE media_id = :oldId');
                $u->execute(['newId' => $newId, 'oldId' => $oldId]);
            }
            if ($hasBlogArticles) {
                $u = $pdo->prepare('UPDATE blog_articles SET featured_media_id = :newId, updated_at = :now WHERE featured_media_id = :oldId');
                $u->execute(['newId' => $newId, 'oldId' => $oldId, 'now' => $now]);
            }
        }

        // Update JSON payloads
        $sections = $pdo->query('SELECT id, section_key, payload FROM cms_page_sections')->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($sections)) {
            $sections = [];
        }
        $keys = array_keys($replaceMap);
        $vals = array_values($replaceMap);
        $updatedSections = 0;
        foreach ($sections as $s) {
            $sid = (int) ($s['id'] ?? 0);
            if ($sid < 1) {
                continue;
            }
            $raw = (string) ($s['payload'] ?? '');
            $decoded = json_decode($raw, true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                continue;
            }
            $changed = false;
            $walk = function ($node) use (&$walk, $keys, $vals, &$changed) {
                if (is_string($node)) {
                    $r = str_replace($keys, $vals, $node);
                    if ($r !== $node) {
                        $changed = true;
                    }
                    return $r;
                }
                if (is_array($node)) {
                    foreach ($node as $k => $v) {
                        $node[$k] = $walk($v);
                    }
                }
                return $node;
            };
            $decoded = $walk($decoded);
            if (($s['section_key'] ?? '') === 'hero' && is_array($decoded)) {
                $heroAlt = isset($decoded['imageAlt']) ? trim((string) $decoded['imageAlt']) : '';
                $heroCaption = isset($decoded['imageCaption']) ? trim((string) $decoded['imageCaption']) : '';
                $heroUrl = isset($decoded['imageUrl']) ? trim((string) $decoded['imageUrl']) : '';
                if ($heroUrl !== '' && $heroAlt === '') {
                    $decoded['imageAlt'] = 'Illustration hero';
                    $changed = true;
                }
                if ($heroUrl !== '' && $heroCaption === '') {
                    $decoded['imageCaption'] = 'Visuel hero SEO';
                    $changed = true;
                }
            }
            if ($changed) {
                $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                if (is_string($json) && $json !== '') {
                    $u = $pdo->prepare('UPDATE cms_page_sections SET payload = :p, updated_at = :now WHERE id = :id');
                    $u->execute(['p' => $json, 'now' => $now, 'id' => $sid]);
                    $updatedSections++;
                }
            }
        }

        $summary = [
            'dryRun' => false,
            'processed' => $processed,
            'updatedSections' => $updatedSections,
            'replaceMapCount' => count($replaceMap),
            'mapping' => $mapping,
        ];
        if ($outputLog) {
            @file_put_contents($outputLog, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        }
        fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    } else {
        fwrite(STDOUT, json_encode(['dryRun' => false, 'processed' => $processed, 'message' => 'No references to replace'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL);
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'Replacement failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

