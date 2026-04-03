<?php

declare(strict_types=1);

namespace App\Application\Catalog;

final class ProductNormalizationService
{
    /**
     * Ligne master JSON curated (product_name, description, tags, product_type, etc.).
     *
     * @param array<string,mixed> $row
     * @return array{
     *   displayNameFr:string,
     *   categoryPath:list<array{slug:string,name:string}>,
     *   color:?string,
     *   tags:list<array{slug:string,name:string,type:string}>,
     *   taxonomy_leaf_slug:string
     * }
     */
    public function normalizeFromMasterRow(array $row): array
    {
        $long = trim((string) ($row['long_description'] ?? ''));
        $desc = $long !== '' ? $long : trim((string) ($row['description'] ?? ''));

        return $this->normalize([
            'name' => (string) ($row['product_name'] ?? ''),
            'description' => $desc,
            'brand_name' => (string) ($row['brand'] ?? ''),
            'product_type' => $row['product_type'] ?? null,
            'tags' => $row['tags'] ?? null,
            'category_l2' => $row['category_l2'] ?? null,
        ]);
    }

    /**
     * @param array<string,mixed> $product
     * @return array{
     *   displayNameFr:string,
     *   categoryPath:list<array{slug:string,name:string}>,
     *   color:?string,
     *   tags:list<array{slug:string,name:string,type:string}>,
     *   taxonomy_leaf_slug:string
     * }
     */
    public function normalize(array $product): array
    {
        $name = trim((string) ($product['name'] ?? ''));
        $description = trim((string) ($product['description'] ?? ''));
        $brand = trim((string) ($product['brand_name'] ?? ''));
        $extra = $this->flattenMasterContextForHaystack($product);
        $signalHaystack = $this->simplify($name . ' ' . $description . ' ' . $extra);
        $haystack = $this->simplify($signalHaystack . ' ' . $this->simplify($brand));

        $productType = isset($product['product_type']) ? trim((string) $product['product_type']) : '';
        $leafSlug = $this->resolveLeafSlug($haystack, $productType !== '' ? $productType : null, $signalHaystack);
        $categoryPath = $this->pathForLeaf($leafSlug);
        $color = $this->detectColor($haystack);
        $displayNameFr = $this->francizeName($name);

        $rootName = $categoryPath[0]['name'] ?? 'Basing & décors';
        $leafName = $categoryPath[count($categoryPath) - 1]['name'] ?? 'Divers';

        $tags = [
            ['slug' => $this->slugify($rootName), 'name' => $rootName, 'type' => 'famille'],
            ['slug' => $this->slugify($leafName), 'name' => $leafName, 'type' => 'sous-famille'],
        ];
        if ($color !== null) {
            $tags[] = ['slug' => $this->slugify($color), 'name' => $color, 'type' => 'couleur'];
        }
        $tags[] = ['slug' => 'usage-' . $this->slugify($leafName), 'name' => $leafName, 'type' => 'usage'];

        return [
            'displayNameFr' => $displayNameFr,
            'categoryPath' => $categoryPath,
            'color' => $color,
            'tags' => $this->dedupeTags($tags),
            'taxonomy_leaf_slug' => $leafSlug,
        ];
    }

    /**
     * @param array<string,mixed> $product
     */
    private function flattenMasterContextForHaystack(array $product): string
    {
        $parts = [];
        $pt = trim((string) ($product['product_type'] ?? ''));
        if ($pt !== '') {
            $parts[] = $pt;
        }
        $l2 = trim((string) ($product['category_l2'] ?? ''));
        if ($l2 !== '') {
            $parts[] = $l2;
        }
        $rawTags = $product['tags'] ?? null;
        if (is_array($rawTags)) {
            foreach ($rawTags as $t) {
                if (is_string($t) || is_numeric($t)) {
                    $parts[] = (string) $t;
                } elseif (is_array($t)) {
                    foreach (['name', 'slug', 'label'] as $k) {
                        if (isset($t[$k]) && (is_string($t[$k]) || is_numeric($t[$k]))) {
                            $parts[] = (string) $t[$k];
                        }
                    }
                }
            }
        }

        return trim(implode(' ', $parts));
    }

    private function resolveLeafSlug(string $haystack, ?string $productType, string $signalHaystack): string
    {
        if ($this->isBundlePaintPackCandidate($productType, $signalHaystack)) {
            return 'peintures-pack-set';
        }

        return $this->classifyLeaf($haystack);
    }

    /**
     * Chemin nomenclature pour une feuille connue (override JSON, tests).
     *
     * @return non-empty-list<array{slug:string,name:string}>
     */
    public function taxonomyPathForLeafSlug(string $leafSlug): array
    {
        return $this->pathForLeaf($leafSlug);
    }

    /**
     * Le nom de marque (ex. « Army Painter ») ne doit pas déclencher seul un pack peinture.
     */
    private function isBundlePaintPackCandidate(?string $productType, string $signalHaystack): bool
    {
        if ($productType === null) {
            return false;
        }
        $type = $this->simplify($productType);
        if ($type !== 'bundle' && $type !== 'pack') {
            return false;
        }
        foreach (
            [
                'paint', 'peinture', 'acrylic', 'acrylique', 'couleur', 'color', '3gen', 'citadel',
                'layer', 'basecoat', 'wargame', 'pot', 'bottle', 'flacon', 'shade', 'contrast',
            ] as $sig
        ) {
            if (str_contains($signalHaystack, $sig)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return non-empty-list<array{slug:string,name:string}>
     */
    private function pathForLeaf(string $leafSlug): array
    {
        /** @var array<string, list<array{slug:string,name:string}>> $map */
        $map = [
            'peintures-pack-set' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-pack-set', 'name' => 'Pack & Set'],
            ],
            'peintures-lavis-encres' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-lavis-encres', 'name' => 'Lavis & encres'],
            ],
            'peintures-metalliques' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-metalliques', 'name' => 'Métalliques'],
            ],
            'peintures-pigments' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-pigments', 'name' => 'Pigments'],
            ],
            'peintures-acryliques-base' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-acryliques', 'name' => 'Acryliques'],
                ['slug' => 'peintures-acryliques-base', 'name' => 'Base'],
            ],
            'peintures-acryliques-layer' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-acryliques', 'name' => 'Acryliques'],
                ['slug' => 'peintures-acryliques-layer', 'name' => 'Layer'],
            ],
            'peintures-acryliques-airbrush' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-acryliques', 'name' => 'Acryliques'],
                ['slug' => 'peintures-acryliques-airbrush', 'name' => 'Airbrush'],
            ],
            'peintures-effets-speciaux-sang' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-effets-speciaux', 'name' => 'Effets spéciaux'],
                ['slug' => 'peintures-effets-speciaux-sang', 'name' => 'Sang'],
            ],
            'peintures-effets-speciaux-rouille' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-effets-speciaux', 'name' => 'Effets spéciaux'],
                ['slug' => 'peintures-effets-speciaux-rouille', 'name' => 'Rouille'],
            ],
            'peintures-effets-speciaux-fluorescent' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-effets-speciaux', 'name' => 'Effets spéciaux'],
                ['slug' => 'peintures-effets-speciaux-fluorescent', 'name' => 'Fluorescent'],
            ],
            'peintures-effets-speciaux-cameleon' => [
                ['slug' => 'peintures', 'name' => 'Peintures'],
                ['slug' => 'peintures-effets-speciaux', 'name' => 'Effets spéciaux'],
                ['slug' => 'peintures-effets-speciaux-cameleon', 'name' => 'Caméléon'],
            ],
            'basing-decors-vegetation-tufts-herbes' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-vegetation', 'name' => 'Végétation'],
                ['slug' => 'basing-decors-vegetation-tufts-herbes', 'name' => 'Herbes'],
            ],
            'basing-decors-vegetation-fleurs' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-vegetation', 'name' => 'Végétation'],
                ['slug' => 'basing-decors-vegetation-fleurs', 'name' => 'Fleurs'],
            ],
            'basing-decors-vegetation-buissons' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-vegetation', 'name' => 'Végétation'],
                ['slug' => 'basing-decors-vegetation-buissons', 'name' => 'Buissons'],
            ],
            'basing-decors-textures-sols-sable-gravier' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-textures-sols', 'name' => 'Textures & sols'],
                ['slug' => 'basing-decors-textures-sols-sable-gravier', 'name' => 'Sable / gravier'],
            ],
            'basing-decors-textures-sols-flocage' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-textures-sols', 'name' => 'Textures & sols'],
                ['slug' => 'basing-decors-textures-sols-flocage', 'name' => 'Flocage'],
            ],
            'basing-decors-textures-sols-texture-paint' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-textures-sols', 'name' => 'Textures & sols'],
                ['slug' => 'basing-decors-textures-sols-texture-paint', 'name' => 'Texture paint'],
            ],
            'basing-decors-textures-sols-neige-boue-eau' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-textures-sols', 'name' => 'Textures & sols'],
                ['slug' => 'basing-decors-textures-sols-neige-boue-eau', 'name' => 'Neige / boue / eau'],
            ],
            'basing-decors-elements-decor-rochers' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-elements-decor', 'name' => 'Éléments de décor'],
                ['slug' => 'basing-decors-elements-decor-rochers', 'name' => 'Rochers'],
            ],
            'basing-decors-elements-decor-ruines' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-elements-decor', 'name' => 'Éléments de décor'],
                ['slug' => 'basing-decors-elements-decor-ruines', 'name' => 'Ruines'],
            ],
            'basing-decors-elements-decor-debris' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-elements-decor', 'name' => 'Éléments de décor'],
                ['slug' => 'basing-decors-elements-decor-debris', 'name' => 'Débris'],
            ],
            'basing-decors-elements-decor-arbres' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-elements-decor', 'name' => 'Éléments de décor'],
                ['slug' => 'basing-decors-elements-decor-arbres', 'name' => 'Arbres'],
            ],
            'basing-decors-elements-decor-divers' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-elements-decor', 'name' => 'Éléments de décor'],
                ['slug' => 'basing-decors-elements-decor-divers', 'name' => 'Divers'],
            ],
            'basing-decors-socles-nus' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-socles', 'name' => 'Socles'],
                ['slug' => 'basing-decors-socles-nus', 'name' => 'Socles nus'],
            ],
            'basing-decors-socles-textures' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-socles', 'name' => 'Socles'],
                ['slug' => 'basing-decors-socles-textures', 'name' => 'Socles texturés'],
            ],
            'basing-decors-socles-premium' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-socles', 'name' => 'Socles'],
                ['slug' => 'basing-decors-socles-premium', 'name' => 'Socles premium'],
            ],
            'basing-decors-sets-bundles-kits-demarrage' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-sets-bundles', 'name' => 'Kits & pack'],
                ['slug' => 'basing-decors-sets-bundles-kits-demarrage', 'name' => 'Pack débutant'],
            ],
            'basing-decors-sets-bundles-kits-peinture-debutant' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-sets-bundles', 'name' => 'Kits & pack'],
                ['slug' => 'basing-decors-sets-bundles-kits-peinture-debutant', 'name' => 'Pack peinture'],
            ],
            'basing-decors-sets-bundles-kits-basing' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-sets-bundles', 'name' => 'Kits & pack'],
                ['slug' => 'basing-decors-sets-bundles-kits-basing', 'name' => 'Pack basing'],
            ],
            'basing-decors-biomes-desert' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-desert', 'name' => 'Désert'],
            ],
            'basing-decors-biomes-jungle' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-jungle', 'name' => 'Jungle'],
            ],
            'basing-decors-biomes-urbain' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-urbain', 'name' => 'Urbain'],
            ],
            'basing-decors-biomes-neige' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-neige', 'name' => 'Neige'],
            ],
            'basing-decors-biomes-foret' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-foret', 'name' => 'Forêt'],
            ],
            'basing-decors-biomes-marecage' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-marecage', 'name' => 'Marécage'],
            ],
            'basing-decors-biomes-montagne' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-montagne', 'name' => 'Montagne'],
            ],
            'basing-decors-biomes-plaines' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-plaines', 'name' => 'Plaines'],
            ],
            'basing-decors-biomes-volcanique' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-volcanique', 'name' => 'Volcanique'],
            ],
            'basing-decors-biomes-toundra' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-toundra', 'name' => 'Toundra'],
            ],
            'basing-decors-biomes-cotier' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-cotier', 'name' => 'Côtier'],
            ],
            'basing-decors-biomes-marin' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-marin', 'name' => 'Marin'],
            ],
            'basing-decors-biomes-savane' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-savane', 'name' => 'Savane'],
            ],
            'basing-decors-biomes-steppe' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-steppe', 'name' => 'Steppe'],
            ],
            'basing-decors-biomes-arctique' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-arctique', 'name' => 'Arctique'],
            ],
            'basing-decors-biomes-ruines' => [
                ['slug' => 'basing-decors', 'name' => 'Basing & décors'],
                ['slug' => 'basing-decors-biomes', 'name' => 'Biomes'],
                ['slug' => 'basing-decors-biomes-ruines', 'name' => 'Ruines (biome)'],
            ],
        ];

        return $map[$leafSlug] ?? $map['basing-decors-elements-decor-divers'];
    }

    private function classifyLeaf(string $haystack): string
    {
        $rules = [
            [['airbrush', 'aerographe'], 'peintures-acryliques-airbrush'],
            [
                [
                    'paint set', 'set de peinture', 'paint bundle', 'acrylic set', '3gen', 'paint box',
                    'real colors', 'colors for aircraft', 'colour set', 'color set', 'panzer color',
                    'couleurs pour', 'set couleur', 'collection de peinture', 'paint collection',
                ],
                'peintures-pack-set',
            ],
            [['wash', 'lavis', 'shade', 'encre ', ' ink '], 'peintures-lavis-encres'],
            [['pigment'], 'peintures-pigments'],
            [['blood', 'sang '], 'peintures-effets-speciaux-sang'],
            [['rust', 'rouille'], 'peintures-effets-speciaux-rouille'],
            [['fluorescent', 'neon ', ' uv '], 'peintures-effets-speciaux-fluorescent'],
            [['chameleon', 'cameleon', 'color shift', 'flip paint'], 'peintures-effets-speciaux-cameleon'],
            [['metallic', 'metal paint', 'gold paint', 'silver paint', 'steel paint', 'brass'], 'peintures-metalliques'],
            [['basecoat', 'base coat', 'undercoat', 'primer', 'primaire', 'sous couche', 'sous-couche'], 'peintures-acryliques-base'],
            [['layer paint', 'layer ', 'highlight'], 'peintures-acryliques-layer'],
            [['tuft', 'touffe', 'static grass', 'herbe statique'], 'basing-decors-vegetation-tufts-herbes'],
            [['flower', 'fleur '], 'basing-decors-vegetation-fleurs'],
            [['bush', 'buisson', 'hedge'], 'basing-decors-vegetation-buissons'],
            [['sand ', 'gravel', 'sable ', ' gravier'], 'basing-decors-textures-sols-sable-gravier'],
            [['flock', 'flocage'], 'basing-decors-textures-sols-flocage'],
            [
                [
                    'texture paint', 'technical paint', 'mud crack', 'crackle',
                    'concrete ', 'wargame terrain', 'wargame terrains', 'terrain paste', 'pate terrain', 'pâte terrain',
                ],
                'basing-decors-textures-sols-texture-paint',
            ],
            [['cork granul', 'liege granul', ' liège ', ' cork sheet'], 'basing-decors-textures-sols-sable-gravier'],
            [['snow effect', ' neige', 'water effect', 'still water', ' realistic water', 'boue', ' mud '], 'basing-decors-textures-sols-neige-boue-eau'],
            [['rock ', 'rocher', 'stone scatter', 'boulder'], 'basing-decors-elements-decor-rochers'],
            [['ruin ', 'ruine', 'ruined'], 'basing-decors-elements-decor-ruines'],
            [['debris', 'scrap pile', 'battle damage bits'], 'basing-decors-elements-decor-debris'],
            [['tree ', 'arbre ', 'twig', 'branch scenic'], 'basing-decors-elements-decor-arbres'],
            [['starter kit', 'start collecting', 'kit demarrage', 'beginner set'], 'basing-decors-sets-bundles-kits-demarrage'],
            [['basing kit', 'terrain kit', 'scenic kit'], 'basing-decors-sets-bundles-kits-basing'],
            [['paint beginner', 'debutant peinture', 'apprendre la peinture'], 'basing-decors-sets-bundles-kits-peinture-debutant'],
            [['textured base', 'socle texture', 'pre textured'], 'basing-decors-socles-textures'],
            [['premium base', 'display plinth', 'socle premium'], 'basing-decors-socles-premium'],
            [['round base', 'lipped base', 'socle rond', 'bases 25mm', 'bases 32mm', 'bases 40mm', 'socle nu'], 'basing-decors-socles-nus'],
            [['desert', 'désert', 'arid'], 'basing-decors-biomes-desert'],
            [['jungle', 'tropical forest'], 'basing-decors-biomes-jungle'],
            [['urban', 'urbain', 'city ', 'ville ', 'street ', 'rue '], 'basing-decors-biomes-urbain'],
            [['tundra'], 'basing-decors-biomes-toundra'],
            [['volcan', 'volcanic', 'lava basing'], 'basing-decors-biomes-volcanique'],
            [['forest', 'forêt', 'foret ', 'woodland'], 'basing-decors-biomes-foret'],
            [['swamp', 'marsh', 'marecage', 'marécage', 'bayou'], 'basing-decors-biomes-marecage'],
            [['mountain', 'montagne', 'alpine'], 'basing-decors-biomes-montagne'],
            [['plains', 'prairie', 'steppe grass'], 'basing-decors-biomes-plaines'],
            [['marine', 'ocean', 'underwater', 'sous marin', 'sous-marin', 'fond marin', 'abyss', 'deep sea', 'pelagic', 'ocean floor', 'fond ocean', 'submarine', 'nautical', 'abyssal'], 'basing-decors-biomes-marin'],
            [['coast', 'côtier', 'cotier', 'beach basing', 'plage '], 'basing-decors-biomes-cotier'],
            [['savanna', 'savane'], 'basing-decors-biomes-savane'],
            [['steppe'], 'basing-decors-biomes-steppe'],
            [['arctic', 'arctique', 'ice world'], 'basing-decors-biomes-arctique'],
            [['snow ', ' neige', ' winter biome', ' icy '], 'basing-decors-biomes-neige'],
            [['ruins biome', 'biome ruine', 'wasteland biome'], 'basing-decors-biomes-ruines'],
            [['socle', 'base 25', 'base 32', 'base 40', 'slotta'], 'basing-decors-socles-nus'],
        ];

        foreach ($rules as [$needles, $leaf]) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $leaf;
                }
            }
        }

        if ($this->haystackLooksLikeAcrylicPaintPot($haystack)) {
            return 'peintures-acryliques-layer';
        }

        $diversAccessoryRules = [
            [['brush', 'pinceau', 'kolinsky', 'sable hair'], 'basing-decors-elements-decor-divers'],
            [['glue', 'colle ', 'cyano', 'super glue'], 'basing-decors-elements-decor-divers'],
            [['tool', 'outil', 'palette', 'cutter'], 'basing-decors-elements-decor-divers'],
            [['terrain', 'scenic ', 'diorama', 'decor element'], 'basing-decors-elements-decor-divers'],
        ];
        foreach ($diversAccessoryRules as [$needles, $leaf]) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return $leaf;
                }
            }
        }

        return 'basing-decors-elements-decor-divers';
    }

    /**
     * Pots / références peinture monocolore (surtout textes FR EEAT) après exclusion colles, décals, etc.
     */
    private function haystackLooksLikeAcrylicPaintPot(string $h): bool
    {
        foreach (
            [
                'cement', 'glue', 'cyano', 'colle ', 'colle-', 'decal', 'decals', 'softener',
                'adhesive', 'calcas', 'adapter solution', 'conditioning fluid', 'extra thin',
                'plastic cement', ' filler ', 'masque ', 'masking',
            ] as $exclude
        ) {
            if (str_contains($h, $exclude)) {
                return false;
            }
        }

        foreach (
            [
                'peinture', 'acrylique', 'acrylic', ' gouache ', ' contrast', 'citadel',
                ' standard', 'dual exo', ' layer ', ' paint ', 'metal colour', 'metal color',
                'colour paint', 'color paint', 'wargame colour', 'wargame color', 'figurine paint',
                'aircraft colour', 'aircraft color', 'pigment line',
            ] as $sig
        ) {
            if (str_contains($h, $sig)) {
                return true;
            }
        }

        return false;
    }

    private function francizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return 'Produit hobby';
        }

        $map = [
            '/\bset\b/i' => 'Set',
            '/\band\b/i' => 'et',
            '/\bgreen creatures\b/i' => 'creatures vertes',
            '/\borcs\b/i' => 'Orcs',
            '/\bpaints?\b/i' => 'Peinture',
            '/\bacrylics?\b/i' => 'Acrylique',
            '/\btools?\b/i' => 'Outils',
            '/\btufts?\b/i' => 'Touffes',
            '/\bbases?\b/i' => 'Socles',
        ];

        $normalized = $name;
        foreach ($map as $pattern => $replacement) {
            $normalized = preg_replace($pattern, $replacement, $normalized) ?? $normalized;
        }

        $normalized = preg_replace('/\s+/', ' ', trim($normalized)) ?? $normalized;
        return $normalized;
    }

    private function detectColor(string $haystack): ?string
    {
        $colors = [
            'vert' => ['green', 'vert', 'olive', 'orc'],
            'bleu' => ['blue', 'bleu', 'cyan'],
            'rouge' => ['red', 'rouge', 'scarlet', 'crimson'],
            'jaune' => ['yellow', 'jaune', 'ocre'],
            'violet' => ['purple', 'violet', 'magenta'],
            'marron' => ['brown', 'marron', 'terre'],
            'gris' => ['gray', 'grey', 'gris'],
            'blanc' => ['white', 'blanc', 'ivoire'],
            'noir' => ['black', 'noir', 'charbon'],
        ];

        foreach ($colors as $label => $needles) {
            foreach ($needles as $needle) {
                if (str_contains($haystack, $needle)) {
                    return ucfirst($label);
                }
            }
        }

        return null;
    }

    /**
     * @param list<array{slug:string,name:string,type:string}> $tags
     * @return list<array{slug:string,name:string,type:string}>
     */
    private function dedupeTags(array $tags): array
    {
        $seen = [];
        $out = [];
        foreach ($tags as $tag) {
            $slug = trim($tag['slug']);
            if ($slug === '' || isset($seen[$slug])) {
                continue;
            }
            $seen[$slug] = true;
            $out[] = $tag;
        }
        return $out;
    }

    private function simplify(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $value = strtr($value, [
            'à' => 'a', 'â' => 'a', 'ä' => 'a',
            'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
            'î' => 'i', 'ï' => 'i',
            'ô' => 'o', 'ö' => 'o',
            'ù' => 'u', 'û' => 'u', 'ü' => 'u',
            'ç' => 'c',
        ]);
        $value = preg_replace('/[^a-z0-9]+/u', ' ', $value) ?? $value;
        return trim($value);
    }

    private function slugify(string $value): string
    {
        $value = $this->simplify($value);
        $value = preg_replace('/\s+/', '-', $value) ?? $value;
        $value = trim($value, '-');
        return $value !== '' ? $value : 'non-classe';
    }
}
