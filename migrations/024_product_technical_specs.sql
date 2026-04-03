-- Caractéristiques techniques produits (spécifications)

CREATE TABLE IF NOT EXISTS product_technical_specs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id CHAR(36) NOT NULL,
    label VARCHAR(255) NOT NULL,
    value TEXT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT NOW(),
    updated_at DATETIME NOT NULL DEFAULT NOW(),
    CONSTRAINT fk_product_technical_specs_product
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_technical_specs_product_id (product_id),
    INDEX idx_product_technical_specs_product_id_position (product_id, position)
);

