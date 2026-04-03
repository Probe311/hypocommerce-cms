<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/replace_cms_placeholder_images.php <host> <db> <user> <password> [--dry-run] [--limit <n>] [--only-host <motif>] [--output-log <path>]\n");
    exit(1);
}

$dryRun = in_array('--dry-run', $argv, true);
$onlyHostMotif = 'placehold.co';
$limit = 50;
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

try {
    $pdo = pdoFromArgv($argv);

    $motifs = [
        'placehold',
        'placeholder',
        'dummy',
        'no-image',
        'image_placeholder',
        $onlyHostMotif,
    ];

    $needleHost = '%' . strtolower($onlyHostMotif) . '%';

    $mediaSelectClauses = [];
    $mediaParams = [];
    foreach ($motifs as $idx => $motif) {
        $k = 'm' . $idx;
        $mediaSelectClauses[] = '(LOWER(url) LIKE :' . $k . ' OR LOWER(path) LIKE :' . $k . ' OR LOWER(filename) LIKE :' . $k . ')';
        $mediaParams[$k] = '%' . strtolower($motif) . '%';
    }

    $sqlCandidates = 'SELECT id, url, path, filename, extension, mime_type, width, height, folder, title, alt_text, status
                       FROM cms_media_library
                       WHERE status = \'active\' AND (' . implode(' OR ', $mediaSelectClauses) . ')
                       ORDER BY id DESC
                       LIMIT :limit';

    $stmtCandidates = $pdo->prepare($sqlCandidates);
    foreach ($mediaParams as $k => $v) {
        $stmtCandidates->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $stmtCandidates->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmtCandidates->execute();
    $candidates = $stmtCandidates->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($candidates)) {
        $candidates = [];
    }

    $mediaService = new \App\Application\Cms\MediaLibraryService();
    $downloader = new \App\Application\Cms\UnsplashSourceDownloader();
    $optimizer = new \App\Application\Cms\ImageOptimizationService();

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $mapping = [];
    $processed = 0;

    $replaceMap = []; // oldUrlOrPathOrFilename => newUrl

    foreach ($candidates as $cand) {
        $oldMediaId = (int) ($cand['id'] ?? 0);
        if ($oldMediaId < 1) {
            continue;
        }

        $oldUrl = is_string($cand['url'] ?? null) ? (string) $cand['url'] : '';
        $oldPath = is_string($cand['path'] ?? null) ? (string) $cand['path'] : '';
        $oldFilename = is_string($cand['filename'] ?? null) ? (string) $cand['filename'] : '';
        $oldFolder = is_string($cand['folder'] ?? null) ? (string) $cand['folder'] : 'general';

        // Check if referenced anywhere in CMS payload/usages.
        $hasUsage = false;
        $stmtUsage = $pdo->prepare('SELECT COUNT(*) FROM cms_media_usages WHERE media_id = :id');
        $stmtUsage->execute(['id' => $oldMediaId]);
        $hasUsage = ((int) $stmtUsage->fetchColumn()) > 0;

        $payloadNeedles = [];
        if ($oldUrl !== '') {
            $payloadNeedles[] = [$oldUrl];
        }
        if ($oldPath !== '') {
            $payloadNeedles[] = [$oldPath];
        }
        if ($oldFilename !== '') {
            $payloadNeedles[] = [$oldFilename];
        }

        if (!$hasUsage && $payloadNeedles !== []) {
            $likeParts = [];
            $params = [];
            foreach ($payloadNeedles as $idx => [$needleValue]) {
                $k = 'n' . $idx;
                $likeParts[] = 'LOWER(CAST(payload AS CHAR)) LIKE :' . $k;
                $params[$k] = '%' . strtolower($needleValue) . '%';
            }
            $sqlPayloadUsage = 'SELECT COUNT(*) FROM cms_page_sections
                                 WHERE ' . implode(' OR ', $likeParts);
            $stmtPayloadUsage = $pdo->prepare($sqlPayloadUsage);
            $stmtPayloadUsage->execute($params);
            $hasUsage = ((int) $stmtPayloadUsage->fetchColumn()) > 0;
        }

        if (!$hasUsage) {
            continue; // avoid downloading unused placeholder library entries
        }

        $marker = 'cms_placeholder_replaced:media_id=' . $oldMediaId;

        // Idempotence: re-use existing replacement if already generated.
        $existing = null;
        $stmtExisting = $pdo->prepare(
            'SELECT id, url FROM cms_media_library
             WHERE status = \'active\' AND (title = :marker OR description = :marker)
             LIMIT 1'
        );
        $stmtExisting->execute(['marker' => $marker]);
        $rowExisting = $stmtExisting->fetch(PDO::FETCH_ASSOC);
        if (is_array($rowExisting) && isset($rowExisting['id'], $rowExisting['url'])) {
            $existing = $rowExisting;
        }

        $context = null;
        $contextQuery = $oldFilename;
        $ctx = findFirstPageContextReferencingMedia($pdo, $oldUrl, $oldPath, $oldFilename);
        if (is_array($ctx) && isset($ctx['slug'], $ctx['title'])) {
            $context = $ctx;
            $contextQuery = trim((string) $ctx['title'] . ' ' . (string) $ctx['slug']);
        }

        if (isset($existing['id'], $existing['url'])) {
            $newMediaId = (int) $existing['id'];
            $newUrl = (string) $existing['url'];
        } elseif ($dryRun) {
            $newMediaId = null;
            $newUrl = null;
        } else {
            $w = is_numeric($cand['width'] ?? null) ? (int) $cand['width'] : 0;
            $h = is_numeric($cand['height'] ?? null) ? (int) $cand['height'] : 0;
            [$targetW, $targetH, $optimizerTargetMaxW] = pickTargetDimensions($w, $h);

            $downloaded = $downloader->download($contextQuery, $targetW, $targetH);
            $inputPath = (string) $downloaded['path'];

            $optimized = $optimizer->optimizeToWebp($inputPath, $optimizerTargetMaxW, 82);
            $uploadMeta = [
                'folder' => $oldFolder !== '' ? $oldFolder : 'general',
                'title' => $marker,
                'altText' => (is_array($context) && isset($context['title'])) ? (string) $context['title'] : 'Image CMS',
                'description' => $marker,
                'caption' => (is_array($context) && isset($context['slug'])) ? (string) $context['slug'] : null,
            ];
            $uploadResult = $mediaService->uploadFromLocalPath((string) $optimized['path'], $uploadMeta);
            $newMediaId = (int) $uploadResult['id'];
            $newUrl = (string) $uploadResult['url'];
        }

        $mapping[(string) $oldMediaId] = [
            'oldMediaId' => $oldMediaId,
            'oldUrl' => $oldUrl,
            'oldPath' => $oldPath,
            'oldFilename' => $oldFilename,
            'context' => $context,
            'newMediaId' => $newMediaId,
            'newUrl' => $newUrl,
            'marker' => $marker,
        ];

        if (is_int($mapping[(string) $oldMediaId]['newMediaId'] ?? null) && is_string($mapping[(string) $oldMediaId]['newUrl'] ?? null)) {
            $newUrl = (string) $mapping[(string) $oldMediaId]['newUrl'];
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
        if ($processed >= $limit) {
            break;
        }
    }

    if ($dryRun) {
        $payload = [
            'dryRun' => true,
            'processed' => $processed,
            'onlyHostMotif' => $onlyHostMotif,
            'mapping' => $mapping,
        ];
        fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
        exit(0);
    }

    if ($replaceMap === []) {
        fwrite(STDOUT, "No replacement media generated (or no references found). Nothing to update.\n");
        exit(0);
    }

    // 1) Update cms_media_usages media_id
    foreach ($mapping as $oldIdStr => $m) {
        $oldId = (int) $m['oldMediaId'];
        $newId = $m['newMediaId'] ?? null;
        if (!is_int($newId)) {
            continue;
        }
        $stmt = $pdo->prepare('UPDATE cms_media_usages SET media_id = :newId WHERE media_id = :oldId');
        $stmt->execute(['newId' => $newId, 'oldId' => $oldId]);
    }

    // 2) Update cms_page_sections.payload deep strings replacement
    $sectionWhereClauses = [];
    $sectionParams = [];
    foreach ($motifs as $idx => $motif) {
        $k = 'sp' . $idx;
        $sectionWhereClauses[] = 'LOWER(CAST(payload AS CHAR)) LIKE :' . $k;
        $sectionParams[$k] = '%' . strtolower($motif) . '%';
    }

    $sectionsSql = 'SELECT id, payload
                    FROM cms_page_sections
                    WHERE ' . implode(' OR ', $sectionWhereClauses) . '
                    ORDER BY id DESC';
    $stmtSections = $pdo->prepare($sectionsSql);
    foreach ($sectionParams as $k => $v) {
        $stmtSections->bindValue(':' . $k, $v, PDO::PARAM_STR);
    }
    $stmtSections->execute();
    $sections = $stmtSections->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($sections)) {
        $sections = [];
    }

    $keys = array_keys($replaceMap);
    $values = array_values($replaceMap);

        $changedCount = 0;
    foreach ($sections as $s) {
        $sectionId = (int) ($s['id'] ?? 0);
        if ($sectionId < 1) {
            continue;
        }
        $payloadRaw = (string) ($s['payload'] ?? '');
        $decoded = json_decode($payloadRaw, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            continue;
        }

        $changed = false;
        $decoded = deepReplaceStrings($decoded, $keys, $values, $changed);
        if (!$changed) {
            continue;
        }

        $json = json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($json) || $json === '') {
            continue;
        }

        $stmtUpdate = $pdo->prepare('UPDATE cms_page_sections SET payload = :payload, updated_at = :updatedAt WHERE id = :id');
        $stmtUpdate->execute(['payload' => $json, 'updatedAt' => $now, 'id' => $sectionId]);
        $changedCount++;
    }

    // 3) Optional mapping log
    if ($outputLog !== null && $outputLog !== '') {
        $logPayload = [
            'dryRun' => false,
            'processed' => $processed,
            'onlyHostMotif' => $onlyHostMotif,
            'replaceMapCount' => count($replaceMap),
            'cmsPageSectionsUpdated' => $changedCount,
            'mapping' => $mapping,
        ];
        @file_put_contents($outputLog, json_encode($logPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
    }

    // 4) Validation: ensure no placeholder-like urls remain in payload/usages for this host.
    $remainingPayloadCount = 0;
    $remainingPayloadWhereClauses = [];
    $remainingPayloadParams = [];
    foreach ($motifs as $idx => $motif) {
        $k = 'rp' . $idx;
        $remainingPayloadWhereClauses[] = 'LOWER(CAST(payload AS CHAR)) LIKE :' . $k;
        $remainingPayloadParams[$k] = '%' . strtolower($motif) . '%';
    }
    $stmtRemainingPayload = $pdo->prepare('SELECT COUNT(*) FROM cms_page_sections WHERE ' . implode(' OR ', $remainingPayloadWhereClauses));
    $stmtRemainingPayload->execute($remainingPayloadParams);
    $remainingPayloadCount = (int) $stmtRemainingPayload->fetchColumn();

    $remainingUsageCount = 0;
    try {
        $usageWhereClauses = [];
        $usageParams = [];
        foreach ($motifs as $idx => $motif) {
            $k = 'up' . $idx;
            $usageWhereClauses[] = '(LOWER(m.url) LIKE :' . $k . ' OR LOWER(m.filename) LIKE :' . $k . ' OR LOWER(m.path) LIKE :' . $k . ')';
            $usageParams[$k] = '%' . strtolower($motif) . '%';
        }
        $stmtRemainingUsage = $pdo->prepare(
            'SELECT COUNT(*)
             FROM cms_media_usages u
             INNER JOIN cms_media_library m ON m.id = u.media_id
             WHERE ' . implode(' OR ', $usageWhereClauses)
        );
        $stmtRemainingUsage->execute($usageParams);
        $remainingUsageCount = (int) $stmtRemainingUsage->fetchColumn();
    } catch (\Throwable) {
        $remainingUsageCount = 0;
    }

    $summary = [
        'dryRun' => false,
        'processed' => $processed,
        'onlyHostMotif' => $onlyHostMotif,
        'cmsPageSectionsUpdated' => $changedCount,
        'remainingPlaceholderLikeInPayloadCount' => $remainingPayloadCount,
        'remainingPlaceholderLikeInUsagesCount' => $remainingUsageCount,
    ];
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . "\n");
} catch (Throwable $e) {
    fwrite(STDERR, 'Replacement failed: ' . $e->getMessage() . "\n");
    exit(1);
}

/**
 * Helper methods live in the global namespace because we can't add classes inside bin scripts cleanly.
 */

function deepReplaceStrings(mixed $node, array $keys, array $values, bool &$changed): mixed
{
    if (is_string($node)) {
        $replaced = str_replace($keys, $values, $node);
        if ($replaced !== $node) {
            $changed = true;
        }
        return $replaced;
    }
    if (is_array($node)) {
        foreach ($node as $k => $v) {
            $node[$k] = deepReplaceStrings($v, $keys, $values, $changed);
        }
        return $node;
    }
    return $node;
}

function pickTargetDimensions(int $w, int $h): array
{
    // Default sizes from the plan.
    $defaultLandscape = [1200, 675, 1600];
    $defaultPortrait = [800, 1200, 1000];

    if ($w > 0 && $h > 0) {
        if ($w >= $h) {
            $aspect = $w / max(1, $h);
            // ~16:9
            if (abs($aspect - (1200 / 675)) < 0.35) {
                return [1200, 675, min(1600, max(1200, $w))];
            }
            return [1200, 800, min(1600, max(1200, $w))];
        }
        return $defaultPortrait;
    }

    return $defaultLandscape;
}

function findFirstPageContextReferencingMedia(PDO $pdo, string $oldUrl, string $oldPath, string $oldFilename): ?array
{
    $needles = [];
    if ($oldUrl !== '') {
        $needles[] = $oldUrl;
    }
    if ($oldPath !== '') {
        $needles[] = $oldPath;
    }
    if ($oldFilename !== '') {
        $needles[] = $oldFilename;
    }

    if ($needles !== []) {
        $likeParts = [];
        $params = [];
        foreach ($needles as $idx => $needleValue) {
            $k = 'n' . $idx;
            $likeParts[] = 'LOWER(CAST(s.payload AS CHAR)) LIKE :' . $k;
            $params[$k] = '%' . strtolower($needleValue) . '%';
        }
        $sql = 'SELECT p.slug, p.title
                FROM cms_page_sections s
                INNER JOIN cms_pages p ON p.id = s.page_id
                WHERE ' . implode(' OR ', $likeParts) . '
                LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (is_array($row) && isset($row['slug'], $row['title'])) {
            return ['slug' => (string) $row['slug'], 'title' => (string) $row['title']];
        }
    }

    return null;
}

