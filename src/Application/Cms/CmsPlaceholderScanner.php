<?php

declare(strict_types=1);

namespace App\Application\Cms;

use PDO;

final class CmsPlaceholderScanner
{
    public function __construct(private PDO $pdo)
    {
    }

    /**
     * @return array<string,mixed>
     */
    public function discover(array $options = []): array
    {
        $onlyHostMotif = isset($options['onlyHostMotif']) ? (string) $options['onlyHostMotif'] : 'placehold.co';
        $limit = isset($options['limit']) ? max(1, (int) $options['limit']) : 200;

        $motifs = [
            'placehold',
            'placeholder',
            'dummy',
            'no-image',
            'image_placeholder',
        ];

        // If an explicit host motif is provided, prioritize it.
        $motifs[] = $onlyHostMotif;

        $mediaCandidates = $this->loadMediaCandidates($motifs);
        $mediaByUrl = [];
        $mediaByPath = [];
        $mediaByFilename = [];
        foreach ($mediaCandidates as $m) {
            if (!is_string($m['url'] ?? null) || ($m['url'] ?? '') === '') {
                continue;
            }
            $mediaByUrl[(string) $m['url']] = (int) $m['id'];
            if (!is_string($m['path'] ?? null) || ($m['path'] ?? '') === '') {
                continue;
            }
            $mediaByPath[(string) $m['path']] = (int) $m['id'];
            if (!is_string($m['filename'] ?? null) || ($m['filename'] ?? '') === '') {
                continue;
            }
            $mediaByFilename[(string) $m['filename']] = (int) $m['id'];
        }

        $occurrenceRows = $this->loadPageSectionPayloadsContaining($motifs, $limit);

        $occurrences = [];
        $seenSignature = [];

        foreach ($occurrenceRows as $row) {
            $payloadRaw = (string) ($row['payload'] ?? '');
            $decoded = json_decode($payloadRaw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $collected = [];
                $this->collectStringsRecursive($decoded, 'payload', $collected);
                foreach ($collected as $c) {
                    $val = (string) $c['value'];
                    if ($this->looksLikePlaceholder($val, $motifs)) {
                        $resolvedMediaId = $this->resolveMediaIdForPlaceholderString($val, $mediaByUrl, $mediaByPath, $mediaByFilename, $mediaCandidates);
                        $sig = implode('|', [$row['page_slug'] ?? '', $row['section_key'] ?? '', $c['path'], $val, (string) ($resolvedMediaId ?? '')]);
                        if (isset($seenSignature[$sig])) {
                            continue;
                        }
                        $seenSignature[$sig] = true;
                        $occurrences[] = [
                            'pageSlug' => $row['page_slug'],
                            'pageTitle' => $row['page_title'],
                            'sectionKey' => $row['section_key'],
                            'sectionType' => $row['section_type'],
                            'payloadPath' => $c['path'],
                            'placeholderValue' => $val,
                            'resolvedMediaId' => $resolvedMediaId,
                        ];
                    }
                }
            } else {
                // Payload isn't valid JSON; fall back to raw substring match.
                $needle = strtolower($payloadRaw);
                if ($this->looksLikePlaceholder($needle, $motifs)) {
                    $resolvedMediaId = $this->resolveMediaIdForPlaceholderString($payloadRaw, $mediaByUrl, $mediaByPath, $mediaByFilename, $mediaCandidates);
                    $occurrences[] = [
                        'pageSlug' => $row['page_slug'],
                        'pageTitle' => $row['page_title'],
                        'sectionKey' => $row['section_key'],
                        'sectionType' => $row['section_type'],
                        'payloadPath' => 'raw',
                        'placeholderValue' => $payloadRaw,
                        'resolvedMediaId' => $resolvedMediaId,
                    ];
                }
            }
        }

        $countsByResolvedMediaId = [];
        foreach ($occurrences as $o) {
            $mid = $o['resolvedMediaId'];
            if (!is_int($mid)) {
                continue;
            }
            $countsByResolvedMediaId[(string) $mid] = ($countsByResolvedMediaId[(string) $mid] ?? 0) + 1;
        }

        // Limit occurrence output: keep enough examples for debugging, but avoid huge payload dumps.
        $examples = array_slice($occurrences, 0, 50);

        return [
            'meta' => [
                'onlyHostMotif' => $onlyHostMotif,
                'limit' => $limit,
                'scannedAt' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            ],
            'mediaCandidates' => array_map(static function (array $m): array {
                return [
                    'id' => (int) $m['id'],
                    'url' => $m['url'],
                    'path' => $m['path'],
                    'filename' => $m['filename'],
                    'extension' => $m['extension'],
                    'mimeType' => $m['mime_type'],
                    'folder' => $m['folder'],
                    'width' => $m['width'],
                    'height' => $m['height'],
                    'title' => $m['title'],
                    'altText' => $m['alt_text'],
                ];
            }, $mediaCandidates),
            'occurrencesCount' => count($occurrences),
            'occurrencesExamples' => $examples,
            'occurrencesCountByMediaId' => $countsByResolvedMediaId,
        ];
    }

    /**
     * @param array<int,string> $motifs
     * @return array<int,array<string,mixed>>
     */
    private function loadMediaCandidates(array $motifs): array
    {
        $clauses = [];
        $params = [];
        foreach ($motifs as $idx => $motif) {
            $key = 'm' . $idx;
            $clauses[] = 'LOWER(url) LIKE :' . $key . ' OR LOWER(path) LIKE :' . $key . ' OR LOWER(filename) LIKE :' . $key . '';
            $params[$key] = '%' . strtolower($motif) . '%';
        }

        $sql = 'SELECT id, url, path, filename, extension, mime_type, size_bytes, width, height, title, alt_text, caption, description, folder
                FROM cms_media_library
                WHERE ' . implode(' OR ', $clauses);

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param array<int,string> $motifs
     * @return array<int,array<string,mixed>>
     */
    private function loadPageSectionPayloadsContaining(array $motifs, int $limit): array
    {
        $clauses = [];
        $params = [];
        foreach ($motifs as $idx => $motif) {
            $key = 'p' . $idx;
            $clauses[] = 'LOWER(CAST(s.payload AS CHAR)) LIKE :' . $key;
            $params[$key] = '%' . strtolower($motif) . '%';
        }

        $sql = 'SELECT s.id AS section_id, p.slug AS page_slug, p.title AS page_title, s.section_key, s.section_type, s.payload
                FROM cms_page_sections s
                INNER JOIN cms_pages p ON p.id = s.page_id
                WHERE ' . implode(' OR ', $clauses) . '
                ORDER BY s.id DESC
                LIMIT :limit';

        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return is_array($rows) ? $rows : [];
    }

    /**
     * @param mixed $value
     * @param array<int,array{path:string,value:string}> $out
     */
    private function collectStringsRecursive(mixed $value, string $path, array &$out): void
    {
        if (is_string($value)) {
            $out[] = ['path' => $path, 'value' => $value];
            return;
        }
        if (is_null($value) || is_bool($value) || is_int($value) || is_float($value)) {
            return;
        }
        if (is_array($value)) {
            foreach ($value as $k => $v) {
                $nextPath = $path . '[' . (string) $k . ']';
                $this->collectStringsRecursive($v, $nextPath, $out);
            }
            return;
        }
        if (is_object($value)) {
            foreach (get_object_vars($value) as $k => $v) {
                $nextPath = $path . '.' . (string) $k;
                $this->collectStringsRecursive($v, $nextPath, $out);
            }
        }
    }

    /**
     * @param array<int,string> $motifs
     */
    private function looksLikePlaceholder(string $value, array $motifs): bool
    {
        $needle = strtolower($value);
        foreach ($motifs as $m) {
            if ($m === '') {
                continue;
            }
            if (str_contains($needle, strtolower($m))) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param array<int,array<string,mixed>> $mediaCandidates
     * @return int|null
     */
    private function resolveMediaIdForPlaceholderString(
        string $value,
        array $mediaByUrl,
        array $mediaByPath,
        array $mediaByFilename,
        array $mediaCandidates
    ): ?int {
        $trimmed = trim($value);
        if (isset($mediaByUrl[$trimmed])) {
            return (int) $mediaByUrl[$trimmed];
        }
        if (isset($mediaByPath[$trimmed])) {
            return (int) $mediaByPath[$trimmed];
        }
        if (isset($mediaByFilename[$trimmed])) {
            return (int) $mediaByFilename[$trimmed];
        }

        $needle = strtolower($trimmed);
        // Fallback: try to find an existing candidate URL inside the string.
        foreach ($mediaCandidates as $m) {
            $url = (string) ($m['url'] ?? '');
            if ($url !== '' && str_contains($needle, strtolower($url))) {
                return (int) $m['id'];
            }
            $path = (string) ($m['path'] ?? '');
            if ($path !== '' && str_contains($needle, strtolower($path))) {
                return (int) $m['id'];
            }
            $filename = (string) ($m['filename'] ?? '');
            if ($filename !== '' && str_contains($needle, strtolower($filename))) {
                return (int) $m['id'];
            }
        }

        return null;
    }
}

