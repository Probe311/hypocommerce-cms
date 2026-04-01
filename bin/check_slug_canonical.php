<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/** @var PDO $pdo */
$pdo = pdoFromArgv($argv);
$errors = 0;

/**
 * @return array<int,string>
 */
function duplicatedSlugs(PDO $pdo, string $table, string $where = '1=1'): array
{
    $query = sprintf(
        'SELECT slug FROM %s WHERE %s GROUP BY slug HAVING COUNT(*) > 1',
        $table,
        $where
    );
    $stmt = $pdo->query($query);
    $rows = $stmt !== false ? $stmt->fetchAll(PDO::FETCH_COLUMN) : [];
    return array_values(array_filter(is_array($rows) ? $rows : [], static fn ($v): bool => is_string($v) && $v !== ''));
}

$checks = [
    ['table' => 'products', 'where' => "status IN ('draft','published','archived')"],
    ['table' => 'product_categories', 'where' => '1=1'],
    ['table' => 'cms_pages', 'where' => "status IN ('draft','published','archived')"],
    ['table' => 'blog_categories', 'where' => '1=1'],
    ['table' => 'legal_pages', 'where' => "status IN ('draft','published','archived')"],
];

foreach ($checks as $check) {
    $dupes = duplicatedSlugs($pdo, (string) $check['table'], (string) $check['where']);
    if ($dupes !== []) {
        $errors++;
        fwrite(STDERR, '[FAIL] duplicate slugs in ' . $check['table'] . ': ' . implode(', ', $dupes) . PHP_EOL);
    } else {
        fwrite(STDOUT, '[OK] unique slugs in ' . $check['table'] . PHP_EOL);
    }
}

if ($errors > 0) {
    fwrite(STDERR, "Slug canonical check failed: {$errors} issue(s).\n");
    exit(1);
}

fwrite(STDOUT, "Slug canonical check passed.\n");
