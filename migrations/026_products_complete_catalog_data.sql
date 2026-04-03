-- Données catalogue complètes (colonnes additionnelles). Contraintes FK / index catégories: voir 025_catalog_product_normalization.sql.
-- Idempotent: doublons ignorés par migrate_versioned.

ALTER TABLE products ADD COLUMN gtin VARCHAR(32) NULL;
ALTER TABLE products ADD COLUMN mpn VARCHAR(64) NULL;
ALTER TABLE products ADD COLUMN editorial_author VARCHAR(120) NULL;
ALTER TABLE products ADD COLUMN editorial_reviewer VARCHAR(120) NULL;
ALTER TABLE products ADD COLUMN reviewed_at DATETIME NULL;
ALTER TABLE products ADD COLUMN normalized_name_fr VARCHAR(255) NULL;
ALTER TABLE products ADD COLUMN main_category_id INT UNSIGNED NULL;
ALTER TABLE products ADD COLUMN sub_category_id INT UNSIGNED NULL;
ALTER TABLE products ADD COLUMN normalized_color VARCHAR(80) NULL;
ALTER TABLE products ADD COLUMN source_url VARCHAR(2048) NULL;
ALTER TABLE products ADD COLUMN supplier_image_url VARCHAR(2048) NULL;
ALTER TABLE products ADD COLUMN supplier_reference VARCHAR(128) NULL;
ALTER TABLE products ADD COLUMN currency CHAR(3) NOT NULL DEFAULT 'EUR';
ALTER TABLE products ADD COLUMN is_featured TINYINT(1) NOT NULL DEFAULT 0;
ALTER TABLE products ADD COLUMN weight_kg DECIMAL(10,4) NULL;
ALTER TABLE products ADD COLUMN length_cm DECIMAL(10,2) NULL;
ALTER TABLE products ADD COLUMN width_cm DECIMAL(10,2) NULL;
ALTER TABLE products ADD COLUMN height_cm DECIMAL(10,2) NULL;
