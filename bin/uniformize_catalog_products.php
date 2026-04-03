<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Application\Catalog\ProductNormalizationService;

/**
 * Usage:
 *   php backend/bin/uniformize_catalog_products.php [host db user password] [--dry-run] [--report=path]
 */

$dryRun = in_array('--dry-run', $argv, true);
$reportPath = dirname(__DIR__) . '/var/reports/catalog_uniformization_report.json';
foreach ($argv as $arg) {
    if (str_starts_with((string) $arg, '--report=')) {
        $reportPath = (string) substr((string) $arg, 9);
    }
}

$pdo = pdoFromArgv($argv);
$service = new ProductNormalizationService();
$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

$rows = $pdo->query(
    "SELECT
        p.id,
        p.name,
        p.description,
        COALESCE((
            SELECT b.name
            FROM product_brand_pivot pbp
            INNER JOIN brands b ON b.id = pbp.brand_id
            WHERE pbp.product_id = p.id
            ORDER BY b.id ASC
            LIMIT 1
        ), '') AS brand_name
     FROM products p
     WHERE p.status = 'published'
     ORDER BY p.updated_at DESC, p.id DESC"
)->fetchAll(PDO::FETCH_ASSOC);

if (!is_array($rows)) {
    fwrite(STDERR, "Impossible de lire les produits publies.\n");
    exit(1);
}

$report = [
    'started_at' => (new DateTimeImmutable())->format(DATE_ATOM),
    'dry_run' => $dryRun,
    'processed' => 0,
    'updated' => 0,
    'errors' => [],
];

$selectCategoryBySlug = $pdo->prepare('SELECT id FROM product_categories WHERE slug = :slug LIMIT 1');
$insertCategory = $pdo->prepare(
    'INSERT INTO product_categories (parent_id, name, slug) VALUES (:parent_id, :name, :slug)'
);
$upsertCategoryTranslation = $pdo->prepare(
    'INSERT INTO product_category_translations (category_id, locale, name, description)
     VALUES (:category_id, :locale, :name, :description)
     ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description)'
);
$updateProduct = $pdo->prepare(
    'UPDATE products
     SET normalized_name_fr = :normalized_name_fr,
         main_category_id = :main_category_id,
         sub_category_id = :sub_category_id,
         normalized_color = :normalized_color,
         updated_at = :updated_at
     WHERE id = :id'
);
$upsertFrTranslation = $pdo->prepare(
    'INSERT INTO product_translations (product_id, locale, name, description, seo_title, seo_description)
     VALUES (:product_id, :locale, :name, :description, NULL, NULL)
     ON DUPLICATE KEY UPDATE name = VALUES(name), description = COALESCE(NULLIF(product_translations.description, \'\'), VALUES(description))'
);
$deleteCategoryPivot = $pdo->prepare('DELETE FROM product_category_pivot WHERE product_id = :product_id');
$insertCategoryPivot = $pdo->prepare(
    'INSERT IGNORE INTO product_category_pivot (product_id, category_id) VALUES (:product_id, :category_id)'
);

$selectTagBySlug = $pdo->prepare('SELECT id FROM product_tags WHERE slug = :slug LIMIT 1');
$insertTag = $pdo->prepare(
    'INSERT INTO product_tags (slug, name, type, created_at, updated_at)
     VALUES (:slug, :name, :type, :created_at, :updated_at)'
);
$deleteTagPivot = $pdo->prepare('DELETE FROM product_tag_pivot WHERE product_id = :product_id');
$insertTagPivot = $pdo->prepare(
    'INSERT IGNORE INTO product_tag_pivot (product_id, tag_id) VALUES (:product_id, :tag_id)'
);

/** @var array<string,int> $categoryCache */
$categoryCache = [];
/** @var array<string,int> $tagCache */
$tagCache = [];

$categoryRows = $pdo->query('SELECT id, slug FROM product_categories')->fetchAll(PDO::FETCH_ASSOC);
if (is_array($categoryRows)) {
    foreach ($categoryRows as $cRow) {
        if (!is_array($cRow)) {
            continue;
        }
        $slug = trim((string) ($cRow['slug'] ?? ''));
        $id = (int) ($cRow['id'] ?? 0);
        if ($slug !== '' && $id > 0) {
            $categoryCache[$slug] = $id;
        }
    }
}

$tagRows = $pdo->query('SELECT id, slug FROM product_tags')->fetchAll(PDO::FETCH_ASSOC);
if (is_array($tagRows)) {
    foreach ($tagRows as $tRow) {
        if (!is_array($tRow)) {
            continue;
        }
        $slug = trim((string) ($tRow['slug'] ?? ''));
        $id = (int) ($tRow['id'] ?? 0);
        if ($slug !== '' && $id > 0) {
            $tagCache[$slug] = $id;
        }
    }
}

/**
 * @return int
 */
function ensureCategory(
    PDO $pdo,
    PDOStatement $selectCategoryBySlug,
    PDOStatement $insertCategory,
    PDOStatement $upsertCategoryTranslation,
    array &$categoryCache,
    string $slug,
    string $name,
    ?int $parentId
): int {
    if (isset($categoryCache[$slug])) {
        $categoryId = $categoryCache[$slug];
        $upsertCategoryTranslation->execute([
            'category_id' => $categoryId,
            'locale' => 'fr',
            'name' => $name,
            'description' => "Produits {$name}.",
        ]);
        return $categoryId;
    }

    $selectCategoryBySlug->execute(['slug' => $slug]);
    $existingId = $selectCategoryBySlug->fetchColumn();
    if (is_numeric($existingId)) {
        $categoryId = (int) $existingId;
    } else {
        $insertCategory->execute([
            'parent_id' => $parentId,
            'name' => $name,
            'slug' => $slug,
        ]);
        $categoryId = (int) $pdo->lastInsertId();
    }

    $upsertCategoryTranslation->execute([
        'category_id' => $categoryId,
        'locale' => 'fr',
        'name' => $name,
        'description' => "Produits {$name}.",
    ]);
    $categoryCache[$slug] = $categoryId;

    return $categoryId;
}

/**
 * @return int
 */
function ensureTag(PDO $pdo, PDOStatement $selectTagBySlug, PDOStatement $insertTag, array &$tagCache, string $slug, string $name, string $type, string $now): int
{
    if (isset($tagCache[$slug])) {
        return $tagCache[$slug];
    }

    $selectTagBySlug->execute(['slug' => $slug]);
    $existingId = $selectTagBySlug->fetchColumn();
    if (is_numeric($existingId)) {
        $tagCache[$slug] = (int) $existingId;
        return $tagCache[$slug];
    }

    $insertTag->execute([
        'slug' => $slug,
        'name' => $name,
        'type' => $type,
        'created_at' => $now,
        'updated_at' => $now,
    ]);
    $tagCache[$slug] = (int) $pdo->lastInsertId();
    return $tagCache[$slug];
}

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }

    $report['processed']++;
    $productId = (string) ($row['id'] ?? '');
    if ($productId === '') {
        $report['errors'][] = ['reason' => 'missing_product_id'];
        continue;
    }

    try {
        $normalized = $service->normalize($row);
        /** @var list<array{slug:string,name:string}> $categoryPath */
        $categoryPath = $normalized['categoryPath'];

        if ($dryRun) {
            $report['updated']++;
            continue;
        }

        $pdo->beginTransaction();

        $pathIds = [];
        $parentId = null;
        foreach ($categoryPath as $segment) {
            $pathIds[] = ensureCategory(
                $pdo,
                $selectCategoryBySlug,
                $insertCategory,
                $upsertCategoryTranslation,
                $categoryCache,
                (string) $segment['slug'],
                (string) $segment['name'],
                $parentId
            );
            $parentId = $pathIds[count($pathIds) - 1];
        }

        $mainCategoryId = $pathIds[0] ?? null;
        $leafCategoryId = $pathIds[count($pathIds) - 1] ?? null;
        if ($mainCategoryId === null || $leafCategoryId === null) {
            throw new RuntimeException('categoryPath vide pour le produit ' . $productId);
        }

        $updateProduct->execute([
            'normalized_name_fr' => (string) $normalized['displayNameFr'],
            'main_category_id' => $mainCategoryId,
            'sub_category_id' => $leafCategoryId,
            'normalized_color' => $normalized['color'],
            'updated_at' => $now,
            'id' => $productId,
        ]);

        $upsertFrTranslation->execute([
            'product_id' => $productId,
            'locale' => 'fr',
            'name' => (string) $normalized['displayNameFr'],
            'description' => isset($row['description']) ? (string) $row['description'] : '',
        ]);

        $deleteCategoryPivot->execute(['product_id' => $productId]);
        foreach ($pathIds as $categoryId) {
            $insertCategoryPivot->execute(['product_id' => $productId, 'category_id' => $categoryId]);
        }

        $deleteTagPivot->execute(['product_id' => $productId]);
        foreach ($normalized['tags'] as $tag) {
            $tagId = ensureTag(
                $pdo,
                $selectTagBySlug,
                $insertTag,
                $tagCache,
                (string) $tag['slug'],
                (string) $tag['name'],
                (string) $tag['type'],
                $now
            );
            $insertTagPivot->execute([
                'product_id' => $productId,
                'tag_id' => $tagId,
            ]);
        }

        $pdo->commit();
        $report['updated']++;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $report['errors'][] = [
            'product_id' => $productId,
            'error' => $e->getMessage(),
        ];
    }

    if (((int) $report['processed']) % 250 === 0) {
        fwrite(STDOUT, sprintf("progress processed=%d updated=%d\n", (int) $report['processed'], (int) $report['updated']));
    }
}

$report['finished_at'] = (new DateTimeImmutable())->format(DATE_ATOM);
$reportDir = dirname($reportPath);
if (!is_dir($reportDir)) {
    mkdir($reportDir, 0777, true);
}
file_put_contents(
    $reportPath,
    json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n"
);

fwrite(
    STDOUT,
    sprintf(
        "Uniformisation terminee. processed=%d updated=%d errors=%d report=%s\n",
        (int) $report['processed'],
        (int) $report['updated'],
        is_countable($report['errors']) ? count($report['errors']) : 0,
        $reportPath
    )
);

