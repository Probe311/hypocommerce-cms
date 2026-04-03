CREATE TABLE IF NOT EXISTS product_tags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS product_tag_pivot (
    product_id CHAR(36) NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, tag_id),
    CONSTRAINT fk_ptp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_ptp_tag FOREIGN KEY (tag_id) REFERENCES product_tags(id) ON DELETE CASCADE
);

CREATE INDEX idx_product_tags_slug ON product_tags(slug);
CREATE INDEX idx_product_tags_type ON product_tags(type);

INSERT INTO product_tags (slug, name, type, created_at, updated_at)
SELECT 'non-defini', 'Non defini', 'finitions', NOW(), NOW()
WHERE NOT EXISTS (
    SELECT 1
    FROM product_tags
    WHERE slug = 'non-defini'
);

INSERT IGNORE INTO product_tag_pivot (product_id, tag_id)
SELECT p.id, t.id
FROM products p
INNER JOIN product_tags t ON t.slug = 'non-defini'
LEFT JOIN product_tag_pivot ptp ON ptp.product_id = p.id
WHERE p.status = 'published'
  AND ptp.product_id IS NULL;
