<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * Supprime les catégories hors taxonomie Peintures / Basing & décors (après migration + uniformize).
 *
 * Usage: php backend/bin/cleanup_legacy_product_categories.php [host db user password] [--dry-run]
 */

$dryRun = in_array('--dry-run', $argv, true);

/** @var list<string> $whitelist */
$whitelist = [
    'non-classe',
    'peintures',
    'basing-decors',
    'peintures-pack-set',
    'peintures-acryliques',
    'peintures-lavis-encres',
    'peintures-metalliques',
    'peintures-effets-speciaux',
    'peintures-pigments',
    'peintures-acryliques-base',
    'peintures-acryliques-layer',
    'peintures-acryliques-airbrush',
    'peintures-effets-speciaux-sang',
    'peintures-effets-speciaux-rouille',
    'peintures-effets-speciaux-fluorescent',
    'peintures-effets-speciaux-cameleon',
    'basing-decors-vegetation',
    'basing-decors-textures-sols',
    'basing-decors-elements-decor',
    'basing-decors-socles',
    'basing-decors-sets-bundles',
    'basing-decors-biomes',
    'basing-decors-vegetation-tufts-herbes',
    'basing-decors-vegetation-fleurs',
    'basing-decors-vegetation-buissons',
    'basing-decors-textures-sols-sable-gravier',
    'basing-decors-textures-sols-flocage',
    'basing-decors-textures-sols-texture-paint',
    'basing-decors-textures-sols-neige-boue-eau',
    'basing-decors-elements-decor-rochers',
    'basing-decors-elements-decor-ruines',
    'basing-decors-elements-decor-debris',
    'basing-decors-elements-decor-arbres',
    'basing-decors-elements-decor-divers',
    'basing-decors-socles-nus',
    'basing-decors-socles-textures',
    'basing-decors-socles-premium',
    'basing-decors-sets-bundles-kits-demarrage',
    'basing-decors-sets-bundles-kits-peinture-debutant',
    'basing-decors-sets-bundles-kits-basing',
    'basing-decors-biomes-desert',
    'basing-decors-biomes-jungle',
    'basing-decors-biomes-urbain',
    'basing-decors-biomes-neige',
    'basing-decors-biomes-foret',
    'basing-decors-biomes-marecage',
    'basing-decors-biomes-montagne',
    'basing-decors-biomes-plaines',
    'basing-decors-biomes-volcanique',
    'basing-decors-biomes-toundra',
    'basing-decors-biomes-cotier',
    'basing-decors-biomes-marin',
    'basing-decors-biomes-savane',
    'basing-decors-biomes-steppe',
    'basing-decors-biomes-arctique',
    'basing-decors-biomes-ruines',
];

$pdo = pdoFromArgv($argv);
$inList = implode(',', array_fill(0, count($whitelist), '?'));

$clearFk = static function (PDO $pdo, string $column) use ($inList, $whitelist): int {
    $sql = "UPDATE products p
            INNER JOIN product_categories c ON c.id = p.{$column}
            SET p.{$column} = NULL
            WHERE c.slug NOT IN ({$inList})";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($whitelist);
    return $stmt->rowCount();
};

if (!$dryRun) {
    $pdo->beginTransaction();
    try {
        $clearFk($pdo, 'main_category_id');
        $clearFk($pdo, 'sub_category_id');
        $delPivot = $pdo->prepare(
            "DELETE pcp FROM product_category_pivot pcp
             INNER JOIN product_categories c ON c.id = pcp.category_id
             WHERE c.slug NOT IN ({$inList})"
        );
        $delPivot->execute($whitelist);
        $deletedTotal = 0;
        do {
            $sql = "DELETE c FROM product_categories c
                    LEFT JOIN product_categories ch ON ch.parent_id = c.id
                    WHERE c.slug NOT IN ({$inList})
                    AND ch.id IS NULL";
            $stmt = $pdo->prepare($sql);
            $stmt->execute($whitelist);
            $n = $stmt->rowCount();
            $deletedTotal += $n;
        } while ($n > 0);

        $pdo->commit();
        fwrite(STDOUT, "Nettoyage termine. Categories supprimees (feuilles): {$deletedTotal}\n");
    } catch (Throwable $e) {
        $pdo->rollBack();
        fwrite(STDERR, 'Erreur: ' . $e->getMessage() . "\n");
        exit(1);
    }
} else {
    $count = $pdo->prepare("SELECT COUNT(*) FROM product_categories WHERE slug NOT IN ({$inList})");
    $count->execute($whitelist);
    $n = (int) $count->fetchColumn();
    fwrite(STDOUT, "[dry-run] Categories hors liste: {$n}\n");
}
