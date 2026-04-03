<?php

declare(strict_types=1);

/**
 * Réécriture "EEAT" des fiches produit sur le JSON maître (hors LLM).
 *
 * Usage:
 *   php backend/bin/seo_product_copy_batch.php [--master=path] [--report=path] [--batch-size=25] [--offset=0] [--dry-run|--apply]
 *
 * Par défaut:
 *   master:  <repo>/seo-suppliers/donnees/produits/master-eeat.curated.json
 *   report:  <repo>/seo-suppliers/logs/product_copy_batch_report.json
 */

$repoRoot = dirname(__DIR__, 2);

$masterPath = $repoRoot . '/seo-suppliers/donnees/produits/master-eeat.curated.json';
$reportPath = $repoRoot . '/seo-suppliers/logs/product_copy_batch_report.json';
$batchSize = 25;
$offset = 0;
$dryRun = true;
$apply = false;

foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--master=')) {
        $masterPath = substr($arg, strlen('--master='));
    } elseif (str_starts_with($arg, '--report=')) {
        $reportPath = substr($arg, strlen('--report='));
    } elseif (str_starts_with($arg, '--batch-size=')) {
        $batchSize = max(1, (int) substr($arg, strlen('--batch-size=')));
    } elseif (str_starts_with($arg, '--offset=')) {
        $offset = max(0, (int) substr($arg, strlen('--offset=')));
    } elseif ($arg === '--dry-run') {
        $dryRun = true;
        $apply = false;
    } elseif ($arg === '--apply') {
        $apply = true;
        $dryRun = false;
    } elseif ($arg === '--help' || $arg === '-h') {
        fwrite(STDOUT, "Usage: php backend/bin/seo_product_copy_batch.php [--master=...] [--report=...] [--batch-size=25] [--offset=0] [--dry-run|--apply]\n");
        exit(0);
    }
}

if ($apply === $dryRun) {
    // Les deux false ou les deux true ne doit pas arriver; par défaut dry-run.
    $dryRun = true;
    $apply = false;
}

if (!is_file($masterPath)) {
    fwrite(STDERR, "Fichier master introuvable: {$masterPath}\n");
    exit(1);
}

$raw = file_get_contents($masterPath);
if ($raw === false) {
    fwrite(STDERR, "Lecture impossible: {$masterPath}\n");
    exit(1);
}

/** @var mixed $items */
$items = json_decode($raw, true);
if (!is_array($items)) {
    fwrite(STDERR, "JSON invalide (attendu: tableau).\n");
    exit(1);
}

/**
 * @return list<string>
 */
function tokens(string $text): array
{
    $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
    if ($clean === '') {
        return [];
    }
    $parts = preg_split('/\s+/u', $clean);
    return is_array($parts) ? array_values(array_filter($parts, static fn($w) => $w !== '')) : [];
}

function countWords(string $text): int
{
    return count(tokens($text));
}

/**
 * Corrige les oublis d'accents / orthographe courants dans les paragraphes générés.
 * On post-traite le texte plutôt que de réécrire toutes les gabarits à la main.
 */
function fixFrenchAccents(string $text): string
{
    if (trim($text) === "") return $text;

    $rules = [
        // Verbe / structure
        '/\brepond\s+a\b/i' => 'répond à',
        '/\brepond\b/i' => 'répond',
        '/\bmethode\b/i' => 'méthode',
        '/\bveritablement\b/i' => 'véritablement',
        '/\bconserver\s+une\s+méthode\b/i' => 'conserver une méthode',

        // Noms communs fréquents
        '/\bselection\b/i' => 'sélection',
        '/\bselectionnes\b/i' => 'sélectionnées',
        '/\bselectionne\b/i' => 'sélectionnée',
        '/\bpassionnes\b/i' => 'passionnés',
        '/\breferences\b/i' => 'références',
        '/\breference\b/i' => 'référence',
        '/\bmateriaux\b/i' => 'matériaux',
        '/\bmateriau\b/i' => 'matériau',
        '/\bmatiere\b/i' => 'matière',
        '/\bmatieres\b/i' => 'matières',
        '/\bdelais\b/i' => 'délais',
        '/\betapes\b/i' => 'étapes',
        '/\betape\b/i' => 'étape',
        '/\bdiametre\b/i' => 'diamètre',

        // Accord / adverbes
        '/\badapte\s+a\b/i' => 'adapté à',
        '/\badaptee\s+a\b/i' => 'adaptée à',
        '/\badaptees\s+a\b/i' => 'adaptées à',

        // Mise en forme + cohérence
        '/\blisibilite\b/i' => 'lisibilité',
        '/\bfiabilite\b/i' => 'fiabilité',
        '/\bfiabiliser\b/i' => 'fiabiliser',
        '/\bregularite\b/i' => 'régularité',
        '/\bpercue\b/i' => 'perçue',
        '/\borientee\b/i' => 'orientée',
        '/\bcoherence\b/i' => 'cohérence',
        '/\bcoherente\b/i' => 'cohérente',
        '/\bcoherent\b/i' => 'cohérent',
        '/\bcoherents\b/i' => 'cohérents',
        '/\bresultat\b/i' => 'résultat',
        '/\bresultats\b/i' => 'résultats',
        '/\bqualite\b/i' => 'qualité',

        // Lexique produit / terrain
        '/\beclairage\b/i' => 'éclairage',
        '/\blumiere\b/i' => 'lumière',
        '/\bpreparation\b/i' => 'préparation',
        '/\bvérification\b/u' => 'vérification',
        '/\bverification\b/i' => 'vérification',
        '/\bsechage\b/i' => 'séchage',
        '/\bseche\b/i' => 'sèche',
        '/\bdelai\b/i' => 'délai',
        '/\breactions\b/i' => 'réactions',
        '/\bdebris\b/i' => 'débris',
        '/\bvegetation\b/i' => 'végétation',
        '/\bgeologie\b/i' => 'géologie',

        '/\bexecution\b/i' => 'exécution',
        '/\bhesitation\b/i' => 'hésitation',
        '/\befficacite\b/i' => 'efficacité',
        '/\brepetabilite\b/i' => 'répétabilité',
        '/\becarts\b/i' => 'écarts',
        '/\bdetails\b/i' => 'détails',
        '/\bpresentation\b/i' => 'présentation',
        '/\breduction\b/i' => 'réduction',
        '/\bdebutants\b/i' => 'débutants',
        '/\bdebutant\b/i' => 'débutant',
        '/\bconfirmes\b/i' => 'confirmés',
        '/\bregulier\b/i' => 'régulier',
        '/\breguliere\b/i' => 'régulière',
        '/\breguliers\b/i' => 'réguliers',

        '/\bmeme\b/i' => 'même',
        '/\bpieces\b/i' => 'pièces',
        '/\bpiece\b/i' => 'pièce',
        '/\breduire\b/i' => 'réduire',
        '/\bcompleter\b/i' => 'compléter',
        '/\bspecialises\b/i' => 'spécialisés',
        '/\bspecialise\b/i' => 'spécialisé',
        '/\beclaircis\b/i' => 'éclaircis',
        '/\bcomplementaires\b/i' => 'complémentaires',
        '/\brecommandee\b/i' => 'recommandée',
        '/\bgeneralisation\b/i' => 'généralisation',
        '/\bpoussee\b/i' => 'poussée',

        // Mots fréquents encore sans diacritiques
        '/\barmee\b/i' => 'armée',
        '/\bintensite\b/i' => 'intensité',
        '/\bseparons\b/i' => 'séparons',
        '/\bcomplet\b/i' => 'complet',
        '/\bcomplete\b/i' => 'complète',
        '/\bcompatibilite\b/i' => 'compatibilité',
        '/\bspecifications\b/i' => 'spécifications',
        '/\bspecification\b/i' => 'spécification',
        '/\binterpretations\b/i' => 'interprétations',

        // Confort orthographique
        '/\bcontrole\b/i' => 'contrôle',
        '/\bcontroler\b/i' => 'contrôler',
        '/\binferer\b/i' => 'inférer',

        // Pronoms / ponctuation
        '/\bjusqu\s+a\b/i' => 'jusqu\'à',
        '/\ba\s+l\'echelle\b/i' => 'à l\'échelle',

        // Limites (faute fréquente)
        '/\birreversable\b/i' => 'irréversible',
    ];

    foreach ($rules as $pattern => $replacement) {
        $text = preg_replace($pattern, $replacement, $text);
    }

    return $text;
}

/**
 * @param array<string,mixed> $row
 */
function haystack(array $row): string
{
    $tags = isset($row['tags']) && is_array($row['tags']) ? implode(' ', $row['tags']) : '';
    return mb_strtolower(
        implode(' ', [
            (string) ($row['product_name'] ?? ''),
            (string) ($row['category_l1'] ?? ''),
            (string) ($row['category_l2'] ?? ''),
            (string) ($row['product_type'] ?? ''),
            $tags,
        ]),
        'UTF-8'
    );
}

/**
 * @return array{segment:string,visual:bool,paint:bool}
 */
function classifyProduct(array $row): array
{
    $h = haystack($row);
    $paint = (bool) preg_match(
        '/\b(peinture|paint|primer|appr[eê]t|shade|wash|lavis|ink|encre|airbrush|a[eé]rographe|pigment\b.*(peint|paint)|metal|m[eé]tallique|acrylic|acryl)/u',
        $h
    );
    $tuft = (bool) preg_match(
        '/\b(tuft|touffe|grass|gazon|laser\s*plant|flower|fleur|herbe\s*statique|static\s*grass)\b/u',
        $h
    );
    $basing = (bool) preg_match(
        '/\b(basing|soclage|texture|mud|boue|sand|sable|gravel|gravier|flock|flocage|diorama|decor|d[eé]cor|ground|sol)\b/u',
        $h
    );
    // Pigments de soclage: segment dédié (souvent "Basing Pigments")
    $basingPigment = (bool) preg_match('/basing\s+pigment|pigment.*socl|pigment.*base/i', $h);

    if ($basingPigment) {
        return ['segment' => 'basing_pigment', 'visual' => true, 'paint' => false];
    }
    if ($tuft) {
        return ['segment' => 'tufts_vegetation', 'visual' => true, 'paint' => false];
    }
    if ($basing) {
        return ['segment' => 'basing_texture', 'visual' => true, 'paint' => false];
    }
    if ($paint) {
        return ['segment' => 'paint', 'visual' => false, 'paint' => true];
    }
    return ['segment' => 'general', 'visual' => false, 'paint' => false];
}

function isCsvSource(array $row): bool
{
    $u = (string) ($row['source_url'] ?? '');
    return str_starts_with($u, 'csv://');
}

/**
 * Détecte un texte "template peinture" appliqué à un produit non-peinture.
 *
 * @param array{segment:string,visual:bool,paint:bool} $cls
 */
function hasPaintTemplateMismatch(array $row, string $long, array $cls): bool
{
    if ($cls['paint'] || $cls['segment'] === 'general') {
        return false;
    }
    $l = mb_strtolower($long, 'UTF-8');
    $hits = 0;
    if (str_contains($l, 'sessions de peinture')) {
        $hits++;
    }
    if (str_contains($l, 'vitesse de mise en peinture')) {
        $hits++;
    }
    if (str_contains($l, 'couches progressives') && $cls['segment'] !== 'paint') {
        $hits++;
    }
    // Incohérences fréquentes observées sur CSV Gamers Grass
    if ($cls['segment'] === 'basing_pigment' && str_contains($l, 'vegetation')) {
        $hits += 2;
    }
    return $hits >= 2;
}

function isTruncatedTail(string $text): bool
{
    $t = rtrim($text);
    return str_ends_with($t, ' mise en.') || str_ends_with($t, ' mise en') || str_ends_with($t, ' resul.') || str_ends_with($t, ' resu.');
}

/**
 * @param array<string,mixed> $row
 * @param array{segment:string,visual:bool,paint:bool} $cls
 * @return array{eligible:bool,reasons:list<string>}
 */
function eligibility(array $row, array $cls): array
{
    $reasons = [];
    $short = (string) ($row['short_description'] ?? '');
    $long = (string) ($row['long_description'] ?? '');
    if ($long === '') {
        $long = (string) ($row['description'] ?? '');
    }

    $csv = isCsvSource($row);
    if ($csv) {
        $reasons[] = 'source_url_csv';
    }

    if ($cls['visual'] && trim((string) ($row['image_url_hd'] ?? '')) === '') {
        $reasons[] = 'image_hd_manquante_segment_visuel';
    }

    if (countWords($short) < 35) {
        $reasons[] = 'short_trop_courte';
    }
    if (countWords($long) < 220) {
        $reasons[] = 'long_trop_courte';
    }
    if (isTruncatedTail($short) || isTruncatedTail($long)) {
        $reasons[] = 'phrase_coupee_detectee';
    }
    if (hasPaintTemplateMismatch($row, $long, $cls)) {
        $reasons[] = 'gabarit_peinture_incoherent';
    }

    if ($reasons === []) {
        return ['eligible' => false, 'reasons' => ['aucun_signal']];
    }

    $version = (int) ($row['copy_rewrite_version'] ?? 0);
    $strong = hasPaintTemplateMismatch($row, $long, $cls) || isTruncatedTail($short) || isTruncatedTail($long);
    if ($version >= 2 && !$strong) {
        // Déjà réécrit "v2" et pas de signal "fort" (mismatch / coupure): on évite de retraiter en boucle
        // Note: un CSV seul n'est pas suffisant pour re-boucler indéfiniment.
        return ['eligible' => false, 'reasons' => ['deja_version_2_sans_signal_fort']];
    }

    return ['eligible' => true, 'reasons' => $reasons];
}

function moneyLine(array $row): string
{
    $price = trim((string) ($row['sale_price'] ?? ''));
    $cur = trim((string) ($row['currency'] ?? ''));
    if ($price === '') {
        return 'Prix: non renseigne dans notre base.';
    }
    return 'Prix constate: ' . $price . ($cur !== '' ? ' ' . $cur : '') . '.';
}

function idsLine(array $row): string
{
    $ref = trim((string) ($row['reference'] ?? ''));
    $sku = trim((string) ($row['sku'] ?? ''));
    $ean = trim((string) ($row['ean'] ?? ''));
    $parts = [];
    if ($ref !== '') {
        $parts[] = 'Reference: ' . $ref;
    }
    if ($sku !== '' && $sku !== $ref) {
        $parts[] = 'SKU: ' . $sku;
    }
    if ($ean !== '') {
        $parts[] = 'EAN: ' . $ean;
    }
    return $parts === [] ? 'Identifiants: non renseignes dans notre base.' : implode(' | ', $parts);
}

/**
 * @param array{segment:string,visual:bool,paint:bool} $cls
 * @return list<string>
 */
function buildTags(array $row, array $cls): array
{
    $tags = [];
    $pt = trim((string) ($row['product_type'] ?? ''));
    if ($pt !== '') {
        $tags[] = $pt;
    }
    if ($cls['segment'] === 'basing_pigment') {
        $tags[] = 'soclage';
        $tags[] = 'pigments';
    } elseif ($cls['segment'] === 'tufts_vegetation') {
        $tags[] = 'vegetation';
        $tags[] = 'soclage';
    } elseif ($cls['segment'] === 'basing_texture') {
        $tags[] = 'texture';
        $tags[] = 'soclage';
    } elseif ($cls['segment'] === 'paint') {
        $tags[] = 'peinture';
    } else {
        $tags[] = 'modelisme';
    }

    $brand = trim((string) ($row['brand'] ?? $row['supplier'] ?? ''));
    if ($brand !== '') {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $brand) ?? '');
        if ($slug !== '') {
            $tags[] = trim($slug, '-');
        }
    }

    $out = [];
    foreach ($tags as $t) {
        $t = strtolower(trim((string) $t));
        if ($t === '' || in_array($t, $out, true)) {
            continue;
        }
        $out[] = $t;
        if (count($out) >= 4) {
            break;
        }
    }
    return $out;
}

/**
 * @param array{segment:string,visual:bool,paint:bool} $cls
 */
function buildShortDescription(array $row, array $cls): string
{
    $brand = trim((string) ($row['brand'] ?? $row['supplier'] ?? 'la marque'));
    $name = trim((string) ($row['product_name'] ?? 'Produit modelisme'));
    $cat2 = trim((string) ($row['category_l2'] ?? ''));
    $ctx = $cat2 !== '' ? " (rayon: {$cat2})" : '';

    $transparency = isCsvSource($row)
        ? "Transparence: notre fiche s'appuie sur les informations structurees du catalogue (pas de page fournisseur HTTP disponible ici)."
        : "Transparence: nous restons prudents sur les details non confirmes dans notre base.";

    if ($cls['segment'] === 'basing_pigment') {
        return implode(' ', [
            "{$name} de {$brand}{$ctx} sert a travailler les nuances de sol et les variations de matiere sur socles et decors, avant vernissage.",
            "Interet principal: obtenir des transitions plus naturelles et une lecture plus nette a distance de jeu, sans surcharger la figurine.",
            "Public: wargame, diorama, vitrine; ideal si vous voulez industrialiser une methode simple et reproductible sur beaucoup de socles.",
            "Mise en oeuvre: testez sur un socle témoin, puis validez contraste et saturation sous votre eclairage habituel.",
            $transparency,
        ]);
    }
    if ($cls['segment'] === 'tufts_vegetation') {
        return implode(' ', [
            "{$name} de {$brand}{$ctx} apporte de la matiere et du relief pour dynamiser vos socles et scenes.",
            "Interet principal: casser les surfaces plates, guider le regard et renforcer une ambiance (prairie, broussailles, parc, zone urbaine vegetalisee).",
            "Public: peintres de figurines et createurs de dioramas qui veulent un rendu rapide mais credible.",
            "Conseil: fixez proprement sur une couche adhesive encore active, puis ajustez la hauteur pour garder la lisibilite des silhouettes.",
            $transparency,
        ]);
    }
    if ($cls['segment'] === 'basing_texture') {
        return implode(' ', [
            "{$name} de {$brand}{$ctx} aide a structurer rapidement un socle ou un decor avec du relief et de la coherence visuelle.",
            "Interet principal: preparer une base solide pour peinture, pigments, flocage et finitions, tout en gardant un rendu homogene sur une armee.",
            "Public: joueurs exigeants sur la lisibilite table-top et hobbyistes qui veulent accelerer la preparation des socles.",
            "Conseil: travaillez par etapes, laissez stabiliser, puis verifiez l'accroche avant d'ajouter des elements fragiles.",
            $transparency,
        ]);
    }
    if ($cls['segment'] === 'paint') {
        return implode(' ', [
            "{$name} de {$brand}{$ctx} s'inscrit dans une logique de peinture de figurines orientee rendu lisible et workflow regulier.",
            "Interet principal: stabiliser votre methode (preparation, application, controle) pour reduire les ecarts entre pieces.",
            "Public: debutants comme confirmes, selon votre objectif (table-top rapide ou presentation plus poussee).",
            "Conseil: validez toujours sur une piece test avant d'appliquer sur un lot complet.",
            $transparency,
        ]);
    }
    return implode(' ', [
        "{$name} de {$brand}{$ctx} complete une gamme modelisme / wargame orientee resultat et regularite.",
        "Interet principal: clarifier l'usage reel du produit et reduire les achats hasardeux.",
        "Public: hobbyistes qui veulent une fiche honnete, meme quand certaines donnees techniques ne sont pas disponibles.",
        "Conseil: si un detail manque, faites un test atelier minimal avant engagement sur un gros volume.",
        $transparency,
    ]);
}

/**
 * @param array{segment:string,visual:bool,paint:bool} $cls
 */
function buildLongDescription(array $row, array $cls): string
{
    $brand = trim((string) ($row['brand'] ?? $row['supplier'] ?? 'la marque'));
    $name = trim((string) ($row['product_name'] ?? 'Produit modelisme'));
    $cat1 = trim((string) ($row['category_l1'] ?? ''));
    $cat2 = trim((string) ($row['category_l2'] ?? ''));
    $ptype = trim((string) ($row['product_type'] ?? ''));

    $head = "Pourquoi ce produit\n{$name} ({$brand}) repond a un besoin simple: avancer sur vos projets figurines avec une methode claire, tout en restant transparent sur ce que notre base sait veritablement confirmer.";
    if ($cat1 !== '' || $cat2 !== '' || $ptype !== '') {
        $head .= " Contexte catalogue: " . trim(implode(' / ', array_filter([$cat1, $cat2, $ptype], static fn($v) => $v !== ''))) . ".";
    }

    $usage = match ($cls['segment']) {
        'basing_pigment' => "Contenu / usage (soclage)\nSur le papier, un set de pigments de soclage sert surtout a modifier teinte, saturation et variations de matiere sur une base preparee. "
            . "En pratique, l'objectif est souvent de rendre un sol plus credible: ombres au bord des paves, poussiere sur une route, terre plus riche sous une touffe, nuances sur du sable.\n\n"
            . "Mise en oeuvre recommandee (principe)\n- Preparez une surface propre et mate.\n- Travaillez par zones, en conservant une lecture globale du socle.\n- Progressez par couches legeres et comparations sous votre eclairage habituel (lampe hobby / lumiere du salon).\n\n"
            . "Compatibilites (sans inventer)\nSans fiche fabricant HTTP dans notre base, nous n'affirmons pas de dosage exact ni de compatibilite chimique precise. "
            . "En atelier, ces produits sont souvent combines avec des liants ou mediums adaptes aux pigments, puis scelles selon votre routine (vernis, fixatif, etc.).\n\n"
            . "Limites & transparence\nNous ne deduisons pas de contenance, de nombre de pots, ni de teintes exactes si elles ne sont pas explicitement presentes dans les champs structures. "
            . "Si une information manque, elle doit etre verifiee avant publication grand public.",
        'tufts_vegetation' => "Contenu / usage (vegetation / relief)\nCe type de produit vise a enrichir le soclage: relief, texture, points de contraste et narration visuelle (terrain battu, herbe haute, bordure de chemin, details de decor).\n\n"
            . "Mise en oeuvre recommandee\n- Definissez d'abord la story du socle (sol, poussiere, boue, pave) puis ajoutez la vegetation pour guider le regard.\n- Fixez avec une colle adaptee; evitez la surcharge qui masque la silhouette.\n- Harmonisez avec le flocage et les pigments pour eviter un effet 'collage'.\n\n"
            . "Compatibilites (sans inventer)\nSouvent combine avec colle PVA, textures de sol, peinture acrylique et vernis. Aucune promesse de dimensions exactes ou de quantite precise sans donnee dans la base.\n\n"
            . "Limites & transparence\nSi l'image HD est absente, la selection visuelle reste basee sur l'intitule et la categorie: gardez une verification rapide a reception.",
        'basing_texture' => "Contenu / usage (textures / soclage)\nLes produits de texture aident a poser rapidement une base realiste (relief, grain, ruptures de matiere) avant peinture et finitions.\n\n"
            . "Mise en oeuvre recommandee\n- Structurez le socle (forme, pentes, jonctions).\n- Appliquez proprement, puis laissez stabiliser avant peinture.\n- Terminez par un controle de robustesse (manipulation, transport).\n\n"
            . "Compatibilites (sans inventer)\nFrequemment associes a apprêts, peintures acryliques, pigments secs, vernis. Pas de temps de sechage affiche ici sans source verifiee.\n\n"
            . "Limites & transparence\nNous n'inventons pas de granulometrie ni de contenance: si absent, c'est a confirmer.",
        'paint' => "Contenu / usage (peinture)\nCe produit s'inscrit dans une routine de peinture de figurines: preparation, application, reprise, finition. "
            . "L'enjeu est la regularite (meme rendu sur plusieurs pieces) et la lisibilite en jeu.\n\n"
            . "Mise en oeuvre recommandee\n- Piece test, puis generalisation.\n- Controle sous eclairage stable.\n- Etapes complementaires possibles: lavis, eclaircis, vernis.\n\n"
            . "Limites & transparence\nSans fiche fabricant complete, ne pas inferer la couleur exacte, la contenance, ni la compatibilite airbrush/pinceau si non indique.",
        default => "Contenu / usage\nNous restons generiques car la categorie n'est pas assez discriminante dans notre base. "
            . "L'objectif est toutefois de fournir un cadre d'achat honnete: a quoi ca sert, comment tester, quelles infos manquent.\n\n"
            . "Limites & transparence\nToute spec technique absente doit etre verifiee avant communication publique.",
    };

    $trust = "Approche EEAT (confiance)\n"
        . "- Nous separons faits (champs catalogue) et interpretations (conseils atelier).\n"
        . "- Pas de specifications inventees.\n"
        . (isCsvSource($row) ? "- Source fournisseur: URL non disponible (csv://...), donc pas de scraping ni de copier-coller 'site'.\n" : "- Source: URL fournie dans la base; malgre cela, certaines infos peuvent manquer.\n")
        . (trim((string) ($row['image_url_hd'] ?? '')) === '' ? "- Image HD absente: prudence sur les attentes visuelles.\n" : '');

    $facts = "Bloc identite (fiable)\n" . idsLine($row) . "\n" . moneyLine($row);

    $faq = "Mini-FAQ\n"
        . "Q: Puis-je l'utiliser sur socles de jeu?\nR: Oui dans une logique hobby standard, en testant d'abord une base témoin.\n\n"
        . "Q: Pourquoi certaines infos manquent?\nR: Parce qu'elles ne sont pas dans notre export structure; mieux vaut un trou qu'une invention.\n\n"
        . "Q: Comment eviter les mauvaises surprises?\nR: Validez rendu + tenue sur une piece, puis appliquez la meme methode au lot.\n";

    $body = trim($head) . "\n\n" . trim($usage) . "\n\n" . trim($trust) . "\n\n" . trim($facts) . "\n\n" . trim($faq);
    // Ajuste grossierement la densite: vise ~450-550 mots en enrichissant avec un guide "atelier" non speculatif
    if (countWords($body) < 420) {
        $body .= "\n\nPratique atelier (non speculatif)\n"
            . "Quand une fiche est partielle, la meilleure strategie est de traiter le produit comme un candidat a validation: une base témoin, des photos sous deux eclairages, "
            . "une checklist rapide (tenue, brillance, lisibilite), puis seulement la production en serie. "
            . "Cette discipline est surtout utile en wargame, ou le temps est limite et ou la coherence d'armee prime.";
    }
    return trim($body);
}

/**
 * @param list<string> $reasons
 */
function priorityScore(array $row, array $reasons): int
{
    $score = 0;
    foreach ($reasons as $r) {
        if ($r === 'source_url_csv') {
            $score += 100;
        }
        if ($r === 'gabarit_peinture_incoherent') {
            $score += 80;
        }
        if ($r === 'image_hd_manquante_segment_visuel') {
            $score += 40;
        }
        if ($r === 'phrase_coupee_detectee') {
            $score += 30;
        }
        if ($r === 'short_trop_courte') {
            $score += 20;
        }
        if ($r === 'long_trop_courte') {
            $score += 20;
        }
    }
    // Leger bonus si la marque Gamers Grass (souvent csv:// dans ce dataset)
    $brand = mb_strtolower((string) ($row['brand'] ?? $row['supplier'] ?? ''), 'UTF-8');
    if (str_contains($brand, 'gamers grass')) {
        $score += 5;
    }
    return $score;
}

/** @var list<array{idx:int,score:int}> $eligibleScored */
$eligibleScored = [];
$eligibleMeta = [];
foreach ($items as $idx => $row) {
    if (!is_array($row)) {
        continue;
    }
    $cls = classifyProduct($row);
    $el = eligibility($row, $cls);
    if ($el['eligible']) {
        $i = (int) $idx;
        $eligibleScored[] = ['idx' => $i, 'score' => priorityScore($row, $el['reasons'])];
        $eligibleMeta[$i] = ['reasons' => $el['reasons'], 'class' => $cls];
    }
}

usort(
    $eligibleScored,
    static function (array $a, array $b): int {
        if ($a['score'] === $b['score']) {
            return $a['idx'] <=> $b['idx'];
        }
        return $b['score'] <=> $a['score'];
    }
);

$eligibleIndexes = array_map(static fn(array $e): int => $e['idx'], $eligibleScored);
/** @var array<int,int> $eligibleScoreByIndex */
$eligibleScoreByIndex = [];
foreach ($eligibleScored as $e) {
    $eligibleScoreByIndex[(int) $e['idx']] = (int) $e['score'];
}

$selected = array_slice($eligibleIndexes, $offset, $batchSize);

$report = [
    'generated_at' => (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
    'dry_run' => $dryRun,
    'master' => $masterPath,
    'batch_size' => $batchSize,
    'offset' => $offset,
    'eligible_total' => count($eligibleIndexes),
    'selected' => [],
    'counts' => [
        'scanned' => count($items),
        'processed' => 0,
        'rewritten' => 0,
        'dry_run_planned' => 0,
        'skipped_ok' => 0,
        'failed' => 0,
    ],
];

foreach ($items as $idx => $row) {
    if (!is_array($row)) {
        $report['counts']['failed']++;
        continue;
    }
    if (!in_array((int) $idx, $selected, true)) {
        continue;
    }
    $report['counts']['processed']++;
    $meta = $eligibleMeta[(int) $idx] ?? ['reasons' => ['unknown'], 'class' => classifyProduct($row)];
    /** @var array{segment:string,visual:bool,paint:bool} $cls */
    $cls = $meta['class'];

    $short = fixFrenchAccents(buildShortDescription($row, $cls));
    $long = fixFrenchAccents(buildLongDescription($row, $cls));
    $tags = buildTags($row, $cls);

    $entry = [
        'index' => (int) $idx,
        'sku' => $row['sku'] ?? null,
        'reference' => $row['reference'] ?? null,
        'product_name' => $row['product_name'] ?? null,
        'priority_score' => $eligibleScoreByIndex[(int) $idx] ?? 0,
        'reasons' => $meta['reasons'],
        'segment' => $cls['segment'],
        'word_counts' => [
            'short' => countWords($short),
            'long' => countWords($long),
        ],
    ];
    $report['selected'][] = $entry;

    if ($apply) {
        $row['short_description'] = $short;
        $row['long_description'] = $long;
        $row['description'] = $long;
        $row['tags'] = $tags;
        $row['copy_rewrite_version'] = max(2, (int) ($row['copy_rewrite_version'] ?? 0) + 1);
        $row['copy_rewritten_at'] = $report['generated_at'];
        $items[$idx] = $row;
        $report['counts']['rewritten']++;
    } elseif ($dryRun) {
        $report['counts']['dry_run_planned']++;
    }
}

$report['counts']['skipped_ok'] = max(0, $report['counts']['scanned'] - $report['counts']['processed'] - $report['counts']['failed']);

if ($apply) {
    // Garantir l'accents sur l'ensemble du dataset servi, même pour les produits non éligibles.
    foreach ($items as $i => $row) {
        if (!is_array($row)) continue;
        foreach (['short_description', 'long_description', 'description'] as $k) {
            if (isset($row[$k]) && is_string($row[$k])) {
                $row[$k] = fixFrenchAccents($row[$k]);
            }
        }
        $items[$i] = $row;
    }

    $encoded = json_encode($items, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) {
        fwrite(STDERR, "Encodage JSON impossible.\n");
        exit(1);
    }
    $tmp = $masterPath . '.tmp';
    if (file_put_contents($tmp, $encoded) === false) {
        fwrite(STDERR, "Ecriture tmp impossible.\n");
        exit(1);
    }
    if (!rename($tmp, $masterPath)) {
        fwrite(STDERR, "Remplacement master impossible.\n");
        exit(1);
    }
}

if (file_put_contents($reportPath, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)) === false) {
    fwrite(STDERR, "Rapport impossible a ecrire: {$reportPath}\n");
    exit(1);
}

fwrite(STDOUT, ($dryRun ? "DRY-RUN" : "APPLY") . " termine.\n");
fwrite(STDOUT, "eligible_total={$report['eligible_total']} selected=" . count($selected) . " rewritten={$report['counts']['rewritten']}\n");
fwrite(STDOUT, "Rapport: {$reportPath}\n");
