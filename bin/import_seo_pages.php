<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
loadBackendEnv();

$host = $argv[1] ?? ($_ENV['DB_HOST'] ?? null);
$db = $argv[2] ?? ($_ENV['DB_NAME'] ?? null);
$user = $argv[3] ?? ($_ENV['DB_USER'] ?? null);
$password = $argv[4] ?? ($_ENV['DB_PASSWORD'] ?? null);
$jsonPath = $argv[5] ?? dirname(__DIR__, 2) . '/seo-suppliers/seo-strategy/contenus-20-pages-enrichi.json';
$dryRun = in_array('--dry-run', $argv, true);

if (!is_string($host) || !is_string($db) || !is_string($user) || !is_string($password) || $host === '' || $db === '' || $user === '') {
    fwrite(STDERR, "Usage: php bin/import_seo_pages.php <host> <db> <user> <password> [json_path]\n");
    fwrite(STDERR, "Ou configurer DB_HOST, DB_NAME, DB_USER, DB_PASSWORD dans backend/.env\n");
    exit(1);
}

if (!is_file($jsonPath)) {
    fwrite(STDERR, "JSON source introuvable: {$jsonPath}\n");
    exit(1);
}

$rawJson = file_get_contents($jsonPath);
if ($rawJson === false) {
    fwrite(STDERR, "Impossible de lire le JSON source.\n");
    exit(1);
}

/** @var mixed $decoded */
$decoded = json_decode($rawJson, true);
if (!is_array($decoded) || !isset($decoded['pages']) || !is_array($decoded['pages'])) {
    fwrite(STDERR, "JSON invalide: pages[] manquant.\n");
    exit(1);
}

/** @var array<int,array<string,mixed>> $pages */
$pages = array_values(array_filter($decoded['pages'], static fn (mixed $item): bool => is_array($item)));
$now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

/**
 * @return array<int,array<string,string>>
 */
function normalizeFaq(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $question = trim((string) ($item['question'] ?? ''));
        $answer = trim((string) ($item['answer'] ?? ''));
        if ($question === '' || $answer === '') {
            continue;
        }
        $out[] = ['question' => $question, 'answer' => $answer];
    }

    return $out;
}

/**
 * @return array<int,array<string,string|int>>
 */
function normalizeSections(array $sections): array
{
    $out = [];
    foreach ($sections as $section) {
        if (!is_array($section)) {
            continue;
        }
        $heading = trim((string) ($section['heading'] ?? ''));
        $body = trim((string) ($section['body'] ?? ''));
        if ($heading === '' || $body === '') {
            continue;
        }
        $out[] = [
            'heading' => $heading,
            'headingLevel' => (int) ($section['headingLevel'] ?? 2),
            'body' => $body,
        ];
    }

    return $out;
}

/**
 * @return array<string,mixed>
 */
function makePayload(array $page, string $template): array
{
    return [
        'template' => $template,
        'source' => 'contenus-20-pages-enrichi',
        'hero' => [
            'title' => trim((string) ($page['h1'] ?? $page['title'] ?? '')),
            'subtitle' => trim((string) ($page['metaDescription'] ?? '')),
            'ctaPrimary' => trim((string) ($page['ctaPrimary'] ?? '')),
        ],
        'sections' => normalizeSections((array) ($page['sections'] ?? [])),
        'faq' => normalizeFaq((array) ($page['faq'] ?? [])),
        'breadcrumbs' => (array) ($page['breadcrumbs'] ?? []),
        'internalLinksOut' => (array) ($page['internalLinksOut'] ?? []),
    ];
}

/**
 * @param array<int,array<string,mixed>> $entries
 * @return array<int,array<string,string>>
 */
function aggregateFaq(array $entries): array
{
    $seen = [];
    $faq = [];
    foreach ($entries as $entry) {
        $items = normalizeFaq((array) ($entry['faq'] ?? []));
        foreach ($items as $item) {
            $key = mb_strtolower($item['question'], 'UTF-8');
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $faq[] = $item;
        }
    }

    return $faq;
}

$home = null;
$categories = [];
$tutorials = [];
$comparatifs = [];
$brands = [];

foreach ($pages as $page) {
    $type = (string) ($page['type'] ?? '');
    if ($type === 'accueil') {
        $home = $page;
    } elseif ($type === 'categorie') {
        $categories[] = $page;
    } elseif ($type === 'tutoriel') {
        $tutorials[] = $page;
    } elseif ($type === 'comparatif') {
        $comparatifs[] = $page;
    } elseif ($type === 'marque') {
        $brands[] = $page;
    }
}

if (!is_array($home)) {
    fwrite(STDERR, "Page accueil introuvable.\n");
    exit(1);
}

$rows = [];
$rows[] = [
    'slug' => 'accueil',
    'title' => mb_substr(trim((string) ($home['h1'] ?? $home['title'] ?? 'Accueil')), 0, 255),
    'meta_title' => mb_substr(trim((string) ($home['title'] ?? 'Accueil')), 0, 255),
    'meta_description' => mb_substr(trim((string) ($home['metaDescription'] ?? '')), 0, 255) ?: null,
    'content' => json_encode(makePayload($home, 'home'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'published_at' => $now,
];

$faqEntries = array_merge($categories, $tutorials, $comparatifs, [$home]);
$faqPayload = makePayload($home, 'faq');
$faqPayload['hero']['title'] = 'Questions frequentes';
$faqPayload['hero']['subtitle'] = 'Reponses rapides pour choisir vos produits et finaliser votre commande.';
$faqPayload['faq'] = aggregateFaq($faqEntries);
$rows[] = [
    'slug' => 'faq',
    'title' => 'Questions frequentes',
    'meta_title' => 'FAQ | Atelier Tactile',
    'meta_description' => 'Questions frequentes: choix des sets, application des peintures, livraison et retours.',
    'content' => json_encode($faqPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'published_at' => $now,
];

$contactPayload = makePayload($home, 'contact');
$contactPayload['hero']['title'] = 'Contact et accompagnement';
$contactPayload['hero']['subtitle'] = 'Parlez avec l equipe pour choisir vos sets et planifier vos projets de peinture.';
$contactPayload['faq'] = array_slice(aggregateFaq($faqEntries), 0, 4);
$rows[] = [
    'slug' => 'contact',
    'title' => 'Contact et accompagnement',
    'meta_title' => 'Contact | Atelier Tactile',
    'meta_description' => 'Contactez notre equipe pour un conseil pre-achat ou une recommandation de set.',
    'content' => json_encode($contactPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'published_at' => $now,
];

$boutiqueSource = $categories[0] ?? $home;
$boutiquePayload = makePayload($boutiqueSource, 'boutique');
$boutiquePayload['hero']['title'] = trim((string) ($boutiqueSource['h1'] ?? 'La boutique des sets et packs'));
$boutiquePayload['hero']['subtitle'] = trim((string) ($boutiqueSource['metaDescription'] ?? ''));
$boutiquePayload['faq'] = aggregateFaq($categories);
$boutiquePayload['brandPages'] = array_values(array_map(
    static fn (array $entry): array => [
        'slug' => (string) ($entry['slug'] ?? ''),
        'title' => (string) ($entry['h1'] ?? $entry['title'] ?? ''),
    ],
    $brands
));
$rows[] = [
    'slug' => 'boutique',
    'title' => mb_substr((string) ($boutiquePayload['hero']['title'] ?: 'Boutique'), 0, 255),
    'meta_title' => 'Boutique | Atelier Tactile',
    'meta_description' => mb_substr((string) ($boutiqueSource['metaDescription'] ?? ''), 0, 255) ?: null,
    'content' => json_encode($boutiquePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'published_at' => $now,
];

$blogSource = $tutorials[0] ?? ($comparatifs[0] ?? $home);
$blogPayload = makePayload($blogSource, 'blog');
$blogPayload['hero']['title'] = trim((string) ($blogSource['h1'] ?? 'Guides et comparatifs'));
$blogPayload['hero']['subtitle'] = trim((string) ($blogSource['metaDescription'] ?? ''));
$blogPayload['sections'] = array_values(array_merge(
    normalizeSections((array) ($blogSource['sections'] ?? [])),
    isset($comparatifs[0]) ? normalizeSections((array) ($comparatifs[0]['sections'] ?? [])) : []
));
$rows[] = [
    'slug' => 'blog',
    'title' => mb_substr((string) ($blogPayload['hero']['title'] ?: 'Blog'), 0, 255),
    'meta_title' => 'Blog | Atelier Tactile',
    'meta_description' => mb_substr((string) ($blogSource['metaDescription'] ?? ''), 0, 255) ?: null,
    'content' => json_encode($blogPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    'published_at' => $now,
];

foreach ($brands as $brandPage) {
    $brandSlug = trim((string) ($brandPage['slug'] ?? ''));
    if ($brandSlug === '') {
        continue;
    }
    $rows[] = [
        'slug' => 'marque-' . $brandSlug,
        'title' => mb_substr(trim((string) ($brandPage['h1'] ?? $brandPage['title'] ?? $brandSlug)), 0, 255),
        'meta_title' => mb_substr(trim((string) ($brandPage['title'] ?? $brandSlug)), 0, 255),
        'meta_description' => mb_substr(trim((string) ($brandPage['metaDescription'] ?? '')), 0, 255) ?: null,
        'content' => json_encode(makePayload($brandPage, 'brand'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'published_at' => $now,
    ];
}

$rows = array_values(array_filter($rows, static function (array $row): bool {
    return is_string($row['content']) && $row['content'] !== 'false';
}));

if ($rows === []) {
    fwrite(STDERR, "Aucune page SEO a importer.\n");
    exit(1);
}

try {
    if ($dryRun) {
        $inScope = array_map(static fn (array $row): string => $row['slug'], $rows);
        fwrite(STDOUT, "Dry-run: aucune ecriture en base.\n");
        fwrite(STDOUT, "Rows prepares: " . count($rows) . "\n");
        fwrite(STDOUT, "Slugs importables: " . implode(', ', $inScope) . "\n");
        exit(0);
    }

    $pdo = pdoFromArgv($argv);

    $pdo->beginTransaction();
    $stmt = $pdo->prepare(
        'INSERT INTO pages (slug, title, content, meta_title, meta_description, published_at)
         VALUES (:slug, :title, :content, :meta_title, :meta_description, :published_at)
         ON DUPLICATE KEY UPDATE
         title = VALUES(title),
         content = VALUES(content),
         meta_title = VALUES(meta_title),
         meta_description = VALUES(meta_description),
         published_at = VALUES(published_at)'
    );

    foreach ($rows as $row) {
        $stmt->execute($row);
    }

    $pdo->commit();

    $inScope = array_map(static fn (array $row): string => $row['slug'], $rows);
    $quoted = implode(',', array_map(static fn (string $slug): string => "'" . str_replace("'", "''", $slug) . "'", $inScope));
    $count = (int) $pdo->query("SELECT COUNT(*) FROM pages WHERE slug IN ({$quoted})")->fetchColumn();

    fwrite(STDOUT, "Import pages termine.\n");
    fwrite(STDOUT, "Rows prepares: " . count($rows) . "\n");
    fwrite(STDOUT, "Rows en base: {$count}\n");
    fwrite(STDOUT, "Slugs importes: " . implode(', ', $inScope) . "\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Import echec: " . $e->getMessage() . "\n");
    exit(1);
}
