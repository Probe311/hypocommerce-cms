-- Uniformisation catalogue: champs normalises produit

ALTER TABLE products
    ADD COLUMN normalized_name_fr VARCHAR(255) NULL AFTER name,
    ADD COLUMN main_category_id INT UNSIGNED NULL AFTER updated_at,
    ADD COLUMN sub_category_id INT UNSIGNED NULL AFTER main_category_id,
    ADD COLUMN normalized_color VARCHAR(80) NULL AFTER sub_category_id;

ALTER TABLE products
    ADD CONSTRAINT fk_products_main_category
        FOREIGN KEY (main_category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_products_sub_category
        FOREIGN KEY (sub_category_id) REFERENCES product_categories(id) ON DELETE SET NULL;

CREATE INDEX idx_products_main_category_id ON products(main_category_id);
CREATE INDEX idx_products_sub_category_id ON products(sub_category_id);
CREATE INDEX idx_products_normalized_color ON products(normalized_color);

