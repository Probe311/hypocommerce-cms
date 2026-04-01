<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/enrich_p1_products_eeat.php <host> <db> <user> <password> [p1_json]\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];
$p1Path = $argv[5] ?? dirname(__DIR__) . '/var/reports/p1_products.json';

$matrixPath = dirname(__DIR__) . '/config/eeat_template_matrix.json';
$matrixRaw = file_get_contents($matrixPath);
if ($matrixRaw === false) {
    fwrite(STDERR, "Matrice EEAT introuvable.\n");
    exit(1);
}
/** @var mixed $matrix */
$matrix = json_decode($matrixRaw, true);
if (!is_array($matrix) || !isset($matrix['templates']['fiche_produit_p1'])) {
    fwrite(STDERR, "Matrice EEAT invalide.\n");
    exit(1);
}
$tpl = $matrix['templates']['fiche_produit_p1'];

$p1Raw = file_get_contents($p1Path);
if ($p1Raw === false) {
    fwrite(STDERR, "Liste P1 introuvable: {$p1Path}\n");
    exit(1);
}
/** @var mixed $p1Decoded */
$p1Decoded = json_decode($p1Raw, true);
if (!is_array($p1Decoded) || !isset($p1Decoded['items']) || !is_array($p1Decoded['items'])) {
    fwrite(STDERR, "Liste P1 invalide.\n");
    exit(1);
}

/**
 * @param array<string,mixed> $row
 */
function buildMarketingDescription(array $row, array $tpl): string
{
    $name = (string) ($row['name'] ?? 'Ce pack');
    $lines = [];
    $lines[] = $name . " - " . (string) ($tpl['usp'] ?? '');
    $lines[] = "";
    $lines[] = "Benefices cles:";
    $benefits = isset($tpl['benefits']) && is_array($tpl['benefits']) ? $tpl['benefits'] : [];
    foreach ($benefits as $benefit) {
        $lines[] = "- " . (string) $benefit;
    }
    $lines[] = "";
    $lines[] = "Usage recommande:";
    $lines[] = (string) ($tpl['usage_block'] ?? '');
    $lines[] = "";
    $lines[] = "Confiance & transparence:";
    $lines[] = (string) ($tpl['trust_block'] ?? '');
    $faq = isset($tpl['faq']) && is_array($tpl['faq']) ? $tpl['faq'] : [];
    if ($faq !== []) {
        $lines[] = "";
        $lines[] = "FAQ:";
        foreach ($faq as $item) {
            if (!is_array($item)) {
                continue;
            }
            $q = (string) ($item['q'] ?? '');
            $a = (string) ($item['a'] ?? '');
            if ($q !== '' && $a !== '') {
                $lines[] = "Q: {$q}";
                $lines[] = "R: {$a}";
            }
        }
    }

    return trim(implode("\n", $lines));
}

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $items = $p1Decoded['items'];
    $stats = [
        'targeted' => 0,
        'updated_description' => 0,
        'updated_seo' => 0,
        'missing_product' => 0,
    ];
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

    $select = $pdo->prepare('SELECT id, sku, name, seo_title, seo_description, description FROM products WHERE id = :id LIMIT 1');
    $update = $pdo->prepare(
        'UPDATE products
         SET description = :description, seo_title = :seo_title, seo_description = :seo_description, updated_at = :updated_at
         WHERE id = :id'
    );

    $pdo->beginTransaction();
    foreach ($items as $item) {
        if (!is_array($item) || !isset($item['id'])) {
            continue;
        }
        $stats['targeted']++;
        $select->execute(['id' => $item['id']]);
        $product = $select->fetch(PDO::FETCH_ASSOC);
        if (!is_array($product)) {
            $stats['missing_product']++;
            continue;
        }

        $existingDescription = trim((string) ($product['description'] ?? ''));
        $existingSeoTitle = trim((string) ($product['seo_title'] ?? ''));
        $existingSeoDescription = trim((string) ($product['seo_description'] ?? ''));

        $newDescription = $existingDescription;
        if ($existingDescription === '') {
            $newDescription = buildMarketingDescription($product, $tpl);
            $stats['updated_description']++;
        } elseif (!str_contains($existingDescription, 'Confiance & transparence:')) {
            $newDescription = $existingDescription . "\n\nConfiance & transparence:\n" . (string) ($tpl['trust_block'] ?? '');
            $stats['updated_description']++;
        }

        $newSeoTitle = $existingSeoTitle;
        if ($existingSeoTitle === '') {
            $newSeoTitle = mb_substr((string) $product['name'] . " | Pack peinture figurines", 0, 255);
            $stats['updated_seo']++;
        }

        $newSeoDescription = $existingSeoDescription;
        if ($existingSeoDescription === '') {
            $newSeoDescription = mb_substr(
                "Pack prioritaire orienté resultat: gain de temps, workflow clair et rendu lisible en contexte de jeu.",
                0,
                255
            );
            $stats['updated_seo']++;
        }

        $update->execute([
            'id' => $product['id'],
            'description' => $newDescription,
            'seo_title' => $newSeoTitle !== '' ? $newSeoTitle : null,
            'seo_description' => $newSeoDescription !== '' ? $newSeoDescription : null,
            'updated_at' => $now,
        ]);
    }
    $pdo->commit();

    fwrite(STDOUT, "Enrichissement P1 termine.\n");
    foreach ($stats as $k => $v) {
        fwrite(STDOUT, "{$k}: {$v}\n");
    }
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    fwrite(STDERR, "Echec enrichissement P1: " . $e->getMessage() . "\n");
    exit(1);
}
