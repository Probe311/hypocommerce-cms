INSERT INTO product_categories (parent_id, name, slug)
SELECT NULL, 'Peintures', 'peintures'
WHERE NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures');

INSERT INTO product_categories (parent_id, name, slug)
SELECT NULL, 'Basing & décors', 'basing-decors'
WHERE NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Pack & Set', 'peintures-pack-set' FROM product_categories p WHERE p.slug = 'peintures'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-pack-set');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Acryliques', 'peintures-acryliques' FROM product_categories p WHERE p.slug = 'peintures'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-acryliques');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Lavis & encres', 'peintures-lavis-encres' FROM product_categories p WHERE p.slug = 'peintures'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-lavis-encres');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Métalliques', 'peintures-metalliques' FROM product_categories p WHERE p.slug = 'peintures'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-metalliques');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Effets spéciaux', 'peintures-effets-speciaux' FROM product_categories p WHERE p.slug = 'peintures'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-effets-speciaux');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Pigments', 'peintures-pigments' FROM product_categories p WHERE p.slug = 'peintures'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-pigments');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Base', 'peintures-acryliques-base' FROM product_categories p WHERE p.slug = 'peintures-acryliques'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-acryliques-base');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Layer', 'peintures-acryliques-layer' FROM product_categories p WHERE p.slug = 'peintures-acryliques'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-acryliques-layer');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Airbrush', 'peintures-acryliques-airbrush' FROM product_categories p WHERE p.slug = 'peintures-acryliques'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-acryliques-airbrush');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Sang', 'peintures-effets-speciaux-sang' FROM product_categories p WHERE p.slug = 'peintures-effets-speciaux'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-effets-speciaux-sang');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Rouille', 'peintures-effets-speciaux-rouille' FROM product_categories p WHERE p.slug = 'peintures-effets-speciaux'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-effets-speciaux-rouille');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Fluorescent', 'peintures-effets-speciaux-fluorescent' FROM product_categories p WHERE p.slug = 'peintures-effets-speciaux'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-effets-speciaux-fluorescent');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Caméléon', 'peintures-effets-speciaux-cameleon' FROM product_categories p WHERE p.slug = 'peintures-effets-speciaux'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'peintures-effets-speciaux-cameleon');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Végétation', 'basing-decors-vegetation' FROM product_categories p WHERE p.slug = 'basing-decors'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-vegetation');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Textures & sols', 'basing-decors-textures-sols' FROM product_categories p WHERE p.slug = 'basing-decors'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-textures-sols');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Éléments de décor', 'basing-decors-elements-decor' FROM product_categories p WHERE p.slug = 'basing-decors'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-elements-decor');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Socles', 'basing-decors-socles' FROM product_categories p WHERE p.slug = 'basing-decors'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-socles');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Sets & bundles', 'basing-decors-sets-bundles' FROM product_categories p WHERE p.slug = 'basing-decors'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-sets-bundles');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Biomes', 'basing-decors-biomes' FROM product_categories p WHERE p.slug = 'basing-decors'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Tufts (herbes)', 'basing-decors-vegetation-tufts-herbes' FROM product_categories p WHERE p.slug = 'basing-decors-vegetation'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-vegetation-tufts-herbes');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Fleurs', 'basing-decors-vegetation-fleurs' FROM product_categories p WHERE p.slug = 'basing-decors-vegetation'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-vegetation-fleurs');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Buissons', 'basing-decors-vegetation-buissons' FROM product_categories p WHERE p.slug = 'basing-decors-vegetation'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-vegetation-buissons');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Sable / gravier', 'basing-decors-textures-sols-sable-gravier' FROM product_categories p WHERE p.slug = 'basing-decors-textures-sols'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-textures-sols-sable-gravier');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Flocage', 'basing-decors-textures-sols-flocage' FROM product_categories p WHERE p.slug = 'basing-decors-textures-sols'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-textures-sols-flocage');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Texture paint', 'basing-decors-textures-sols-texture-paint' FROM product_categories p WHERE p.slug = 'basing-decors-textures-sols'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-textures-sols-texture-paint');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Neige / boue / eau', 'basing-decors-textures-sols-neige-boue-eau' FROM product_categories p WHERE p.slug = 'basing-decors-textures-sols'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-textures-sols-neige-boue-eau');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Rochers', 'basing-decors-elements-decor-rochers' FROM product_categories p WHERE p.slug = 'basing-decors-elements-decor'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-elements-decor-rochers');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Ruines', 'basing-decors-elements-decor-ruines' FROM product_categories p WHERE p.slug = 'basing-decors-elements-decor'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-elements-decor-ruines');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Débris', 'basing-decors-elements-decor-debris' FROM product_categories p WHERE p.slug = 'basing-decors-elements-decor'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-elements-decor-debris');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Arbres', 'basing-decors-elements-decor-arbres' FROM product_categories p WHERE p.slug = 'basing-decors-elements-decor'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-elements-decor-arbres');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Divers', 'basing-decors-elements-decor-divers' FROM product_categories p WHERE p.slug = 'basing-decors-elements-decor'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-elements-decor-divers');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Socles nus', 'basing-decors-socles-nus' FROM product_categories p WHERE p.slug = 'basing-decors-socles'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-socles-nus');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Socles texturés', 'basing-decors-socles-textures' FROM product_categories p WHERE p.slug = 'basing-decors-socles'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-socles-textures');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Socles premium', 'basing-decors-socles-premium' FROM product_categories p WHERE p.slug = 'basing-decors-socles'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-socles-premium');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Kits de démarrage', 'basing-decors-sets-bundles-kits-demarrage' FROM product_categories p WHERE p.slug = 'basing-decors-sets-bundles'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-sets-bundles-kits-demarrage');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Kits peinture débutant', 'basing-decors-sets-bundles-kits-peinture-debutant' FROM product_categories p WHERE p.slug = 'basing-decors-sets-bundles'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-sets-bundles-kits-peinture-debutant');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Kits basing', 'basing-decors-sets-bundles-kits-basing' FROM product_categories p WHERE p.slug = 'basing-decors-sets-bundles'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-sets-bundles-kits-basing');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Désert', 'basing-decors-biomes-desert' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-desert');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Jungle', 'basing-decors-biomes-jungle' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-jungle');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Urbain', 'basing-decors-biomes-urbain' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-urbain');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Neige', 'basing-decors-biomes-neige' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-neige');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Forêt', 'basing-decors-biomes-foret' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-foret');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Marécage', 'basing-decors-biomes-marecage' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-marecage');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Montagne', 'basing-decors-biomes-montagne' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-montagne');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Plaines', 'basing-decors-biomes-plaines' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-plaines');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Volcanique', 'basing-decors-biomes-volcanique' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-volcanique');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Toundra', 'basing-decors-biomes-toundra' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-toundra');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Côtier', 'basing-decors-biomes-cotier' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-cotier');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Savane', 'basing-decors-biomes-savane' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-savane');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Steppe', 'basing-decors-biomes-steppe' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-steppe');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Arctique', 'basing-decors-biomes-arctique' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-arctique');

INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Ruines (biome)', 'basing-decors-biomes-ruines' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-ruines');

INSERT INTO product_category_translations (category_id, locale, name, description)
SELECT c.id, 'fr', c.name, CONCAT('Produits : ', c.name, '.')
FROM product_categories c
WHERE c.slug IN (
    'peintures','basing-decors',
    'peintures-pack-set','peintures-acryliques','peintures-lavis-encres','peintures-metalliques','peintures-effets-speciaux','peintures-pigments',
    'peintures-acryliques-base','peintures-acryliques-layer','peintures-acryliques-airbrush',
    'peintures-effets-speciaux-sang','peintures-effets-speciaux-rouille','peintures-effets-speciaux-fluorescent','peintures-effets-speciaux-cameleon',
    'basing-decors-vegetation','basing-decors-textures-sols','basing-decors-elements-decor','basing-decors-socles','basing-decors-sets-bundles','basing-decors-biomes',
    'basing-decors-vegetation-tufts-herbes','basing-decors-vegetation-fleurs','basing-decors-vegetation-buissons',
    'basing-decors-textures-sols-sable-gravier','basing-decors-textures-sols-flocage','basing-decors-textures-sols-texture-paint','basing-decors-textures-sols-neige-boue-eau',
    'basing-decors-elements-decor-rochers','basing-decors-elements-decor-ruines','basing-decors-elements-decor-debris','basing-decors-elements-decor-arbres','basing-decors-elements-decor-divers',
    'basing-decors-socles-nus','basing-decors-socles-textures','basing-decors-socles-premium',
    'basing-decors-sets-bundles-kits-demarrage','basing-decors-sets-bundles-kits-peinture-debutant','basing-decors-sets-bundles-kits-basing',
    'basing-decors-biomes-desert','basing-decors-biomes-jungle','basing-decors-biomes-urbain','basing-decors-biomes-neige',
    'basing-decors-biomes-foret','basing-decors-biomes-marecage','basing-decors-biomes-montagne','basing-decors-biomes-plaines',
    'basing-decors-biomes-volcanique','basing-decors-biomes-toundra','basing-decors-biomes-cotier','basing-decors-biomes-savane',
    'basing-decors-biomes-steppe','basing-decors-biomes-arctique','basing-decors-biomes-ruines'
)
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);
