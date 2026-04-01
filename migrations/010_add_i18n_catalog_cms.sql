CREATE TABLE product_translations (
    product_id CHAR(36) NOT NULL,
    locale VARCHAR(8) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    seo_title VARCHAR(255) NULL,
    seo_description VARCHAR(255) NULL,
    PRIMARY KEY (product_id, locale),
    CONSTRAINT fk_product_translations_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE product_category_translations (
    category_id INT UNSIGNED NOT NULL,
    locale VARCHAR(8) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    PRIMARY KEY (category_id, locale),
    CONSTRAINT fk_product_category_translations_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE
);

CREATE TABLE cms_page_translations (
    page_id INT UNSIGNED NOT NULL,
    locale VARCHAR(8) NOT NULL,
    title VARCHAR(255) NOT NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    PRIMARY KEY (page_id, locale),
    CONSTRAINT fk_cms_page_translations_page FOREIGN KEY (page_id) REFERENCES cms_pages(id) ON DELETE CASCADE
);
