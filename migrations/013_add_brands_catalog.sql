CREATE TABLE brands (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    logo_url VARCHAR(255) NULL,
    favicon_url VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE product_brand_pivot (
    product_id CHAR(36) NOT NULL,
    brand_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, brand_id),
    CONSTRAINT fk_pbp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_pbp_brand FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE
);

CREATE INDEX idx_brands_slug ON brands(slug);

INSERT INTO brands (slug, name, description, created_at, updated_at)
SELECT DISTINCT
    LOWER(SUBSTRING_INDEX(p.slug, '-', 1)) AS slug,
    CONCAT(UCASE(LEFT(LOWER(SUBSTRING_INDEX(p.slug, '-', 1)), 1)), SUBSTRING(LOWER(SUBSTRING_INDEX(p.slug, '-', 1)), 2)) AS name,
    CONCAT('Produits de la marque ', CONCAT(UCASE(LEFT(LOWER(SUBSTRING_INDEX(p.slug, '-', 1)), 1)), SUBSTRING(LOWER(SUBSTRING_INDEX(p.slug, '-', 1)), 2)), '.'),
    NOW(),
    NOW()
FROM products p
WHERE TRIM(COALESCE(p.slug, '')) <> ''
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    updated_at = VALUES(updated_at);

INSERT INTO product_brand_pivot (product_id, brand_id)
SELECT
    p.id,
    b.id
FROM products p
INNER JOIN brands b ON b.slug = LOWER(SUBSTRING_INDEX(p.slug, '-', 1))
LEFT JOIN product_brand_pivot pbp ON pbp.product_id = p.id AND pbp.brand_id = b.id
WHERE pbp.product_id IS NULL;
