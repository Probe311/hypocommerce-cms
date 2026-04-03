INSERT INTO product_categories (parent_id, name, slug)
SELECT p.id, 'Marin', 'basing-decors-biomes-marin' FROM product_categories p WHERE p.slug = 'basing-decors-biomes'
AND NOT EXISTS (SELECT 1 FROM product_categories WHERE slug = 'basing-decors-biomes-marin');

UPDATE product_categories SET name = 'Herbes' WHERE slug = 'basing-decors-vegetation-tufts-herbes';

UPDATE product_categories SET name = 'Kits & pack' WHERE slug = 'basing-decors-sets-bundles';

UPDATE product_categories SET name = 'Pack débutant' WHERE slug = 'basing-decors-sets-bundles-kits-demarrage';

UPDATE product_categories SET name = 'Pack peinture' WHERE slug = 'basing-decors-sets-bundles-kits-peinture-debutant';

UPDATE product_categories SET name = 'Pack basing' WHERE slug = 'basing-decors-sets-bundles-kits-basing';

UPDATE product_category_translations t
INNER JOIN product_categories c ON c.id = t.category_id
SET t.name = c.name, t.description = CONCAT('Produits : ', c.name, '.')
WHERE t.locale = 'fr' AND c.slug IN (
    'basing-decors-vegetation-tufts-herbes',
    'basing-decors-sets-bundles',
    'basing-decors-sets-bundles-kits-demarrage',
    'basing-decors-sets-bundles-kits-peinture-debutant',
    'basing-decors-sets-bundles-kits-basing'
);

INSERT INTO product_category_translations (category_id, locale, name, description)
SELECT c.id, 'fr', c.name, CONCAT('Produits : ', c.name, '.')
FROM product_categories c
WHERE c.slug = 'basing-decors-biomes-marin'
ON DUPLICATE KEY UPDATE name = VALUES(name), description = VALUES(description);
