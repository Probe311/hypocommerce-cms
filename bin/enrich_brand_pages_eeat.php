<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/enrich_brand_pages_eeat.php <host> <db> <user> <password>\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];

$matrixPath = dirname(__DIR__) . '/config/eeat_template_matrix.json';
$matrixRaw = file_get_contents($matrixPath);
if ($matrixRaw === false) {
    fwrite(STDERR, "Matrice introuvable: {$matrixPath}\n");
    exit(1);
}
/** @var mixed $matrix */
$matrix = json_decode($matrixRaw, true);
if (!is_array($matrix) || !isset($matrix['templates']['marque']) || !is_array($matrix['templates']['marque'])) {
    fwrite(STDERR, "Matrice invalide.\n");
    exit(1);
}
$tpl = $matrix['templates']['marque'];

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $stats = ['targeted' => 0, 'updated' => 0];

    $select = $pdo->query(
        "SELECT id, slug, title FROM cms_pages
         WHERE template = 'marque'
            OR slug LIKE '%army-painter%'
            OR slug LIKE '%marque-%'"
    );
    $pages = $select->fetchAll(PDO::FETCH_ASSOC);
    if (!is_array($pages) || $pages === []) {
        fwrite(STDOUT, "Aucune page marque ciblee.\n");
        exit(0);
    }

    $delete = $pdo->prepare(
        "DELETE FROM cms_page_sections
         WHERE page_id = :page_id
           AND section_key IN ('eeat_positioning','eeat_use_cases','eeat_compatibility','eeat_trust','eeat_cta')"
    );
    $insert = $pdo->prepare(
        'INSERT INTO cms_page_sections (page_id, section_key, section_type, order_index, payload, created_at, updated_at)
         VALUES (:page_id, :section_key, :section_type, :order_index, :payload, :created_at, :updated_at)'
    );
    $audit = $pdo->prepare(
        'INSERT INTO audit_logs (user_id, action, entity_type, entity_id, data, created_at, ip)
         VALUES (NULL, :action, :entity_type, :entity_id, :data, :created_at, NULL)'
    );

    $pdo->beginTransaction();
    foreach ($pages as $page) {
        $pageId = (int) $page['id'];
        $stats['targeted']++;

        $delete->execute(['page_id' => $pageId]);

        $blocks = [
            ['key' => 'eeat_positioning', 'type' => 'eeat', 'order' => 300, 'payload' => ['text' => $tpl['positioning'] ?? '']],
            ['key' => 'eeat_use_cases', 'type' => 'eeat', 'order' => 301, 'payload' => ['items' => $tpl['use_cases'] ?? []]],
            ['key' => 'eeat_compatibility', 'type' => 'eeat', 'order' => 302, 'payload' => ['text' => $tpl['compatibility'] ?? '']],
            ['key' => 'eeat_trust', 'type' => 'eeat', 'order' => 303, 'payload' => ['text' => $tpl['trust'] ?? '']],
            ['key' => 'eeat_cta', 'type' => 'cta', 'order' => 304, 'payload' => ['label' => $tpl['cta'] ?? 'Voir la selection']],
        ];

        foreach ($blocks as $block) {
            $insert->execute([
                'page_id' => $pageId,
                'section_key' => $block['key'],
                'section_type' => $block['type'],
                'order_index' => $block['order'],
                'payload' => json_encode($block['payload'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $auditData = json_encode([
            'slug' => $page['slug'],
            'enrichment' => 'brand_eeat',
            'sections' => array_column($blocks, 'key'),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $audit->execute([
            'action' => 'enrich',
            'entity_type' => 'cms_pages',
            'entity_id' => (string) $page['slug'],
            'data' => $auditData,
            'created_at' => $now,
        ]);

        $stats['updated']++;
    }
    $pdo->commit();

    fwrite(STDOUT, "Enrichissement pages marques termine.\n");
    fwrite(STDOUT, "targeted: {$stats['targeted']}\n");
    fwrite(STDOUT, "updated: {$stats['updated']}\n");
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Echec enrichissement pages marques: " . $e->getMessage() . "\n");
    exit(1);
}
