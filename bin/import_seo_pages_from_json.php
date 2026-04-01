<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/import_seo_pages_from_json.php <host> <db> <user> <password> [json_path]\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];
$jsonPath = $argv[5] ?? dirname(__DIR__, 2) . '/seo-suppliers/seo-strategy/contenus-20-pages-enrichi.json';

if (!is_file($jsonPath)) {
    fwrite(STDERR, "JSON SEO introuvable: {$jsonPath}\n");
    exit(1);
}

$raw = file_get_contents($jsonPath);
if ($raw === false) {
    fwrite(STDERR, "Impossible de lire le JSON SEO.\n");
    exit(1);
}

/** @var mixed $decoded */
$decoded = json_decode($raw, true);
if (!is_array($decoded) || !isset($decoded['pages']) || !is_array($decoded['pages'])) {
    fwrite(STDERR, "Format JSON SEO invalide (pages manquantes).\n");
    exit(1);
}

/**
 * @param array<string,mixed> $page
 * @return array<int,array{section_key:string,section_type:string,order_index:int,payload:string}>
 */
function buildSections(array $page): array
{
    $sections = [];
    $order = 1;

    $hero = [
        'title' => (string) ($page['h1'] ?? $page['title'] ?? ''),
        'subtitle' => (string) ($page['metaDescription'] ?? ''),
        'primaryKeyword' => (string) ($page['primaryKeyword'] ?? ''),
    ];
    $sections[] = [
        'section_key' => 'hero',
        'section_type' => 'hero',
        'order_index' => $order++,
        'payload' => json_encode($hero, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ];

    if (isset($page['sections']) && is_array($page['sections'])) {
        $normalized = [];
        foreach ($page['sections'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized[] = [
                'heading' => (string) ($item['heading'] ?? ''),
                'headingLevel' => (int) ($item['headingLevel'] ?? 2),
                'body' => (string) ($item['body'] ?? ''),
                'intent' => (string) ($item['intent'] ?? ''),
            ];
        }
        $sections[] = [
            'section_key' => 'sections',
            'section_type' => 'content_sections',
            'order_index' => $order++,
            'payload' => json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];
    }

    if (isset($page['faq']) && is_array($page['faq'])) {
        $faq = [];
        foreach ($page['faq'] as $f) {
            if (!is_array($f)) {
                continue;
            }
            $question = trim((string) ($f['question'] ?? ''));
            $answer = trim((string) ($f['answer'] ?? ''));
            if ($question === '' || $answer === '') {
                continue;
            }
            $faq[] = ['question' => $question, 'answer' => $answer];
        }
        if ($faq !== []) {
            $sections[] = [
                'section_key' => 'faq',
                'section_type' => 'faq',
                'order_index' => $order++,
                'payload' => json_encode($faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
            ];
        }
    }

    if (isset($page['breadcrumbs']) && is_array($page['breadcrumbs'])) {
        $sections[] = [
            'section_key' => 'breadcrumbs',
            'section_type' => 'breadcrumbs',
            'order_index' => $order++,
            'payload' => json_encode($page['breadcrumbs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];
    }

    if (isset($page['internalLinksOut']) && is_array($page['internalLinksOut'])) {
        $sections[] = [
            'section_key' => 'internal_links',
            'section_type' => 'internal_links',
            'order_index' => $order++,
            'payload' => json_encode($page['internalLinksOut'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '[]',
        ];
    }

    if (isset($page['ctaPrimary'])) {
        $sections[] = [
            'section_key' => 'cta_primary',
            'section_type' => 'cta',
            'order_index' => $order++,
            'payload' => json_encode(['label' => (string) $page['ctaPrimary']], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
        ];
    }

    $sections[] = [
        'section_key' => 'raw_source',
        'section_type' => 'seo_source',
        'order_index' => $order++,
        'payload' => json_encode($page, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}',
    ];

    return $sections;
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $pages = $decoded['pages'];
    $processed = 0;
    $inserted = 0;
    $updated = 0;
    $rejected = 0;

    $pdo->beginTransaction();
    $selectPage = $pdo->prepare('SELECT id FROM cms_pages WHERE slug = :slug LIMIT 1');
    $insertPage = $pdo->prepare(
        'INSERT INTO cms_pages (slug, title, template, status, meta_title, meta_description, published_at, created_at, updated_at)
         VALUES (:slug, :title, :template, :status, :meta_title, :meta_description, :published_at, :created_at, :updated_at)'
    );
    $updatePage = $pdo->prepare(
        'UPDATE cms_pages
         SET title = :title, template = :template, status = :status, meta_title = :meta_title,
             meta_description = :meta_description, published_at = :published_at, updated_at = :updated_at
         WHERE id = :id'
    );
    $deleteSections = $pdo->prepare('DELETE FROM cms_page_sections WHERE page_id = :page_id');
    $insertSection = $pdo->prepare(
        'INSERT INTO cms_page_sections (page_id, section_key, section_type, order_index, payload, created_at, updated_at)
         VALUES (:page_id, :section_key, :section_type, :order_index, :payload, :created_at, :updated_at)'
    );

    foreach ($pages as $page) {
        if (!is_array($page)) {
            $rejected++;
            continue;
        }
        $slug = trim((string) ($page['slug'] ?? ''));
        $title = trim((string) ($page['title'] ?? ''));
        $template = trim((string) ($page['type'] ?? 'generic'));
        if ($slug === '' || $title === '') {
            $rejected++;
            continue;
        }
        $processed++;

        $selectPage->execute(['slug' => $slug]);
        $existingId = $selectPage->fetchColumn();

        if ($existingId === false) {
            $insertPage->execute([
                'slug' => $slug,
                'title' => $title,
                'template' => $template,
                'status' => 'published',
                'meta_title' => mb_substr($title, 0, 255),
                'meta_description' => isset($page['metaDescription']) ? mb_substr((string) $page['metaDescription'], 0, 255) : null,
                'published_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $pageId = (int) $pdo->lastInsertId();
            $inserted++;
        } else {
            $pageId = (int) $existingId;
            $updatePage->execute([
                'id' => $pageId,
                'title' => $title,
                'template' => $template,
                'status' => 'published',
                'meta_title' => mb_substr($title, 0, 255),
                'meta_description' => isset($page['metaDescription']) ? mb_substr((string) $page['metaDescription'], 0, 255) : null,
                'published_at' => $now,
                'updated_at' => $now,
            ]);
            $deleteSections->execute(['page_id' => $pageId]);
            $updated++;
        }

        $sections = buildSections($page);
        foreach ($sections as $section) {
            $insertSection->execute([
                'page_id' => $pageId,
                'section_key' => $section['section_key'],
                'section_type' => $section['section_type'],
                'order_index' => $section['order_index'],
                'payload' => $section['payload'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    $pdo->commit();

    fwrite(STDOUT, "Import SEO pages termine.\n");
    fwrite(STDOUT, "Processed: {$processed}\n");
    fwrite(STDOUT, "Inserted: {$inserted}\n");
    fwrite(STDOUT, "Updated: {$updated}\n");
    fwrite(STDOUT, "Rejected: {$rejected}\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Import SEO pages echec: " . $e->getMessage() . "\n");
    exit(1);
}
