<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use App\Infrastructure\Persistence\PdoCatalogApiRepository;

$repo = new PdoCatalogApiRepository();

$rows = $repo->listProducts('fr', [
    'limit' => 10,
    'offset' => 0,
    'sort' => 'featured',
]);

$checked = 0;
$missingTitle = 0;
$missingDescription = 0;
$samples = [];

foreach ($rows as $row) {
    if (!is_array($row)) {
        continue;
    }
    $checked++;
    $seoTitle = trim((string) ($row['seoTitle'] ?? ''));
    $seoDescription = trim((string) ($row['seoDescription'] ?? ''));
    if ($seoTitle === '') {
        $missingTitle++;
    }
    if ($seoDescription === '') {
        $missingDescription++;
    }
    $samples[] = [
        'slug' => (string) ($row['slug'] ?? ''),
        'seoTitle' => $seoTitle,
        'seoDescription' => $seoDescription,
    ];
}

echo "checked={$checked}\n";
echo "api_missing_seo_title={$missingTitle}\n";
echo "api_missing_seo_description={$missingDescription}\n";
echo "api_samples=" . json_encode($samples, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";

