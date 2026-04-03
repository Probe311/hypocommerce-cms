-- Schéma SQL principal pour le backend e-commerce

CREATE TABLE products (
    id CHAR(36) NOT NULL PRIMARY KEY,
    sku VARCHAR(64) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    normalized_name_fr VARCHAR(255) NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    description TEXT NULL,
    price DECIMAL(10,2) NOT NULL,
    sale_price DECIMAL(10,2) NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    type ENUM('simple','variable','digital','bundle') NOT NULL DEFAULT 'simple',
    seo_title VARCHAR(255) NULL,
    seo_description VARCHAR(255) NULL,
    gtin VARCHAR(32) NULL,
    mpn VARCHAR(64) NULL,
    editorial_author VARCHAR(120) NULL,
    editorial_reviewer VARCHAR(120) NULL,
    reviewed_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    main_category_id INT UNSIGNED NULL,
    sub_category_id INT UNSIGNED NULL,
    normalized_color VARCHAR(80) NULL,
    source_url VARCHAR(2048) NULL,
    supplier_image_url VARCHAR(2048) NULL,
    supplier_reference VARCHAR(128) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    is_featured TINYINT(1) NOT NULL DEFAULT 0,
    weight_kg DECIMAL(10,4) NULL,
    length_cm DECIMAL(10,2) NULL,
    width_cm DECIMAL(10,2) NULL,
    height_cm DECIMAL(10,2) NULL,
    CONSTRAINT fk_products_main_category FOREIGN KEY (main_category_id) REFERENCES product_categories(id) ON DELETE SET NULL,
    CONSTRAINT fk_products_sub_category FOREIGN KEY (sub_category_id) REFERENCES product_categories(id) ON DELETE SET NULL
);

CREATE TABLE product_variants (
    id CHAR(36) NOT NULL PRIMARY KEY,
    product_id CHAR(36) NOT NULL,
    sku VARCHAR(64) NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    stock_qty INT NOT NULL DEFAULT 0,
    weight DECIMAL(10,3) NULL,
    attributes JSON NULL,
    CONSTRAINT fk_product_variants_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);

CREATE TABLE product_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE,
    CONSTRAINT fk_product_categories_parent FOREIGN KEY (parent_id) REFERENCES product_categories(id) ON DELETE SET NULL
);

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

CREATE TABLE product_category_pivot (
    product_id CHAR(36) NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, category_id),
    CONSTRAINT fk_pcp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_pcp_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE
);

CREATE TABLE product_tags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    name VARCHAR(255) NOT NULL,
    type VARCHAR(80) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE product_tag_pivot (
    product_id CHAR(36) NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (product_id, tag_id),
    CONSTRAINT fk_ptp_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_ptp_tag FOREIGN KEY (tag_id) REFERENCES product_tags(id) ON DELETE CASCADE
);

CREATE TABLE product_category_translations (
    category_id INT UNSIGNED NOT NULL,
    locale VARCHAR(8) NOT NULL,
    name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    PRIMARY KEY (category_id, locale),
    CONSTRAINT fk_product_category_translations_category FOREIGN KEY (category_id) REFERENCES product_categories(id) ON DELETE CASCADE
);

CREATE TABLE product_images (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id CHAR(36) NOT NULL,
    url VARCHAR(255) NOT NULL,
    alt VARCHAR(255) NULL,
    position INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_product_images_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
);


-- Caracteristiques techniques (voir migrations/024_product_technical_specs.sql)
CREATE TABLE product_technical_specs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id CHAR(36) NOT NULL,
    label VARCHAR(255) NOT NULL,
    value TEXT NOT NULL,
    position INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_product_technical_specs_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    INDEX idx_product_technical_specs_product_id (product_id),
    INDEX idx_product_technical_specs_product_id_position (product_id, position)
)

CREATE TABLE product_attributes (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL UNIQUE
);

CREATE TABLE product_attribute_values (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    attribute_id INT UNSIGNED NOT NULL,
    value VARCHAR(255) NOT NULL,
    slug VARCHAR(255) NOT NULL,
    CONSTRAINT fk_pav_attribute FOREIGN KEY (attribute_id) REFERENCES product_attributes(id) ON DELETE CASCADE
);

CREATE TABLE product_related (
    product_id CHAR(36) NOT NULL,
    related_id CHAR(36) NOT NULL,
    type ENUM('related','upsell','cross_sell') NOT NULL DEFAULT 'related',
    PRIMARY KEY (product_id, related_id, type),
    CONSTRAINT fk_pr_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_pr_related FOREIGN KEY (related_id) REFERENCES products(id) ON DELETE CASCADE
);

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

CREATE TABLE customers (
    id CHAR(36) NOT NULL PRIMARY KEY,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    first_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NULL,
    default_billing_id INT UNSIGNED NULL,
    default_shipping_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL
);

CREATE TABLE customer_addresses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id CHAR(36) NOT NULL,
    label VARCHAR(100) NULL,
    type ENUM('billing','shipping') NOT NULL,
    line1 VARCHAR(255) NOT NULL,
    line2 VARCHAR(255) NULL,
    city VARCHAR(100) NOT NULL,
    postcode VARCHAR(20) NOT NULL,
    state VARCHAR(100) NULL,
    country VARCHAR(2) NOT NULL,
    phone VARCHAR(50) NULL,
    CONSTRAINT fk_customer_addresses_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

ALTER TABLE customers
    ADD CONSTRAINT fk_customers_default_billing FOREIGN KEY (default_billing_id) REFERENCES customer_addresses(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_customers_default_shipping FOREIGN KEY (default_shipping_id) REFERENCES customer_addresses(id) ON DELETE SET NULL;

CREATE TABLE password_resets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id CHAR(36) NOT NULL,
    token VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    used_at DATETIME NULL,
    CONSTRAINT fk_password_resets_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE carts (
    id CHAR(36) NOT NULL PRIMARY KEY,
    customer_id CHAR(36) NULL,
    session_id VARCHAR(128) NULL,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    created_at DATETIME NOT NULL,
    expires_at DATETIME NULL,
    CONSTRAINT fk_carts_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

CREATE TABLE cart_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cart_id CHAR(36) NOT NULL,
    product_id CHAR(36) NOT NULL,
    variant_id CHAR(36) NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_cart_items_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_cart_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
);

CREATE TABLE orders (
    id CHAR(36) NOT NULL PRIMARY KEY,
    number VARCHAR(50) NOT NULL UNIQUE,
    customer_id CHAR(36) NULL,
    status ENUM('pending','paid','shipped','cancelled','refunded') NOT NULL DEFAULT 'pending',
    total DECIMAL(10,2) NOT NULL,
    sub_total DECIMAL(10,2) NOT NULL,
    tax_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    shipping_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    discount_total DECIMAL(10,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'EUR',
    payment_method VARCHAR(50) NULL,
    shipping_method VARCHAR(50) NULL,
    placed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_orders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

CREATE TABLE order_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    product_id CHAR(36) NULL,
    variant_id CHAR(36) NULL,
    name VARCHAR(255) NOT NULL,
    quantity INT NOT NULL,
    unit_price DECIMAL(10,2) NOT NULL,
    total DECIMAL(10,2) NOT NULL,
    meta JSON NULL,
    CONSTRAINT fk_order_items_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE SET NULL,
    CONSTRAINT fk_order_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL
);

CREATE TABLE order_addresses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    type ENUM('billing','shipping') NOT NULL,
    line1 VARCHAR(255) NOT NULL,
    line2 VARCHAR(255) NULL,
    city VARCHAR(100) NOT NULL,
    postcode VARCHAR(20) NOT NULL,
    state VARCHAR(100) NULL,
    country VARCHAR(2) NOT NULL,
    phone VARCHAR(50) NULL,
    company VARCHAR(255) NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    CONSTRAINT fk_order_addresses_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE order_payments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    provider VARCHAR(50) NOT NULL,
    provider_ref VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NOT NULL,
    status VARCHAR(50) NOT NULL,
    raw_payload JSON NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_order_payments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE order_refunds (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    provider VARCHAR(50) NOT NULL,
    provider_ref VARCHAR(255) NULL,
    amount DECIMAL(10,2) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
    reason VARCHAR(255) NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'succeeded',
    raw_payload JSON NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_order_refunds_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE order_shipments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    order_id CHAR(36) NOT NULL,
    warehouse_code VARCHAR(32) NULL,
    carrier VARCHAR(100) NULL,
    tracking_number VARCHAR(255) NULL,
    status VARCHAR(50) NOT NULL,
    shipped_at DATETIME NULL,
    CONSTRAINT fk_order_shipments_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
);

CREATE TABLE order_shipment_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    shipment_id INT UNSIGNED NOT NULL,
    order_item_id INT UNSIGNED NOT NULL,
    quantity INT NOT NULL,
    CONSTRAINT fk_order_shipment_items_shipment FOREIGN KEY (shipment_id) REFERENCES order_shipments(id) ON DELETE CASCADE,
    CONSTRAINT fk_order_shipment_items_order_item FOREIGN KEY (order_item_id) REFERENCES order_items(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_shipment_item (shipment_id, order_item_id)
);

CREATE TABLE warehouses (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(32) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    country VARCHAR(2) NOT NULL DEFAULT 'FR',
    city VARCHAR(100) NULL,
    active TINYINT(1) NOT NULL DEFAULT 1
);

CREATE TABLE inventory_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    product_id CHAR(36) NOT NULL,
    variant_id CHAR(36) NULL,
    warehouse_id INT UNSIGNED NULL,
    stock_qty INT NOT NULL DEFAULT 0,
    backorder_allowed TINYINT(1) NOT NULL DEFAULT 0,
    low_stock_threshold INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_inventory_items_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_inventory_items_variant FOREIGN KEY (variant_id) REFERENCES product_variants(id) ON DELETE SET NULL,
    CONSTRAINT fk_inventory_items_warehouse FOREIGN KEY (warehouse_id) REFERENCES warehouses(id) ON DELETE SET NULL
);

CREATE TABLE inventory_movements (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    inventory_item_id INT UNSIGNED NOT NULL,
    type ENUM('order','cancel','manual') NOT NULL,
    quantity_delta INT NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_inventory_movements_item FOREIGN KEY (inventory_item_id) REFERENCES inventory_items(id) ON DELETE CASCADE
);

CREATE TABLE coupons (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(64) NOT NULL UNIQUE,
    type ENUM('percent','fixed') NOT NULL,
    value DECIMAL(10,2) NOT NULL,
    min_order_total DECIMAL(10,2) NULL,
    max_uses INT NULL,
    max_uses_per_user INT NULL,
    starts_at DATETIME NULL,
    ends_at DATETIME NULL,
    applies_to JSON NULL
);

CREATE TABLE newsletter_subscribers (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(180) NOT NULL UNIQUE,
    subscribed_at DATETIME NOT NULL,
    unsubscribed_at DATETIME NULL
);

CREATE TABLE customer_segments (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    customer_id CHAR(36) NOT NULL,
    segment_code VARCHAR(64) NOT NULL,
    score INT NOT NULL DEFAULT 0,
    computed_at DATETIME NOT NULL,
    UNIQUE KEY uniq_customer_segment (customer_id, segment_code),
    CONSTRAINT fk_customer_segments_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
);

CREATE TABLE abandoned_cart_reminders (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    cart_id CHAR(36) NOT NULL,
    customer_id CHAR(36) NULL,
    email VARCHAR(180) NULL,
    sent_at DATETIME NOT NULL,
    status VARCHAR(32) NOT NULL,
    payload JSON NULL,
    CONSTRAINT fk_abandoned_cart_reminders_cart FOREIGN KEY (cart_id) REFERENCES carts(id) ON DELETE CASCADE,
    CONSTRAINT fk_abandoned_cart_reminders_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE SET NULL
);

CREATE TABLE pages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    content TEXT NOT NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    published_at DATETIME NULL
);

CREATE TABLE settings (
    `key` VARCHAR(100) NOT NULL PRIMARY KEY,
    value JSON NOT NULL
);

CREATE TABLE admin_users (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('super_admin','admin','manager') NOT NULL DEFAULT 'admin',
    created_at DATETIME NOT NULL,
    last_login_at DATETIME NULL
);

CREATE TABLE audit_logs (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NULL,
    action VARCHAR(255) NOT NULL,
    entity_type VARCHAR(100) NOT NULL,
    entity_id VARCHAR(100) NOT NULL,
    data JSON NULL,
    created_at DATETIME NOT NULL,
    ip VARCHAR(45) NULL,
    CONSTRAINT fk_audit_logs_user FOREIGN KEY (user_id) REFERENCES admin_users(id) ON DELETE SET NULL
);

CREATE INDEX idx_products_slug ON products(slug);
CREATE INDEX idx_products_status ON products(status);
CREATE INDEX idx_products_type ON products(type);
CREATE INDEX idx_products_main_category_id ON products(main_category_id);
CREATE INDEX idx_products_sub_category_id ON products(sub_category_id);
CREATE INDEX idx_products_normalized_color ON products(normalized_color);
CREATE INDEX idx_brands_slug ON brands(slug);
CREATE INDEX idx_product_tags_slug ON product_tags(slug);
CREATE INDEX idx_product_tags_type ON product_tags(type);

CREATE INDEX idx_customers_email ON customers(email);
CREATE INDEX idx_orders_status ON orders(status);
CREATE INDEX idx_orders_customer ON orders(customer_id);
CREATE INDEX idx_order_refunds_order_status ON order_refunds(order_id, status);

-- CMS structure pour contenus administrables
CREATE TABLE IF NOT EXISTS cms_pages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(255) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    template VARCHAR(100) NOT NULL,
    status ENUM('draft','in_review','scheduled','published','archived') NOT NULL DEFAULT 'draft',
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    published_at DATETIME NULL,
    reviewed_at DATETIME NULL,
    scheduled_at DATETIME NULL,
    review_note VARCHAR(255) NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS cms_page_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_id INT UNSIGNED NOT NULL,
    section_key VARCHAR(120) NOT NULL,
    section_type VARCHAR(120) NOT NULL,
    order_index INT NOT NULL DEFAULT 0,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_page_section (page_id, section_key),
    CONSTRAINT fk_cms_sections_page FOREIGN KEY (page_id) REFERENCES cms_pages(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS cms_page_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_cms_page_versions_page FOREIGN KEY (page_id) REFERENCES cms_pages(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_cms_page_version (page_id, version)
);

CREATE TABLE IF NOT EXISTS cms_page_translations (
    page_id INT UNSIGNED NOT NULL,
    locale VARCHAR(8) NOT NULL,
    title VARCHAR(255) NOT NULL,
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    PRIMARY KEY (page_id, locale),
    CONSTRAINT fk_cms_page_translations_page FOREIGN KEY (page_id) REFERENCES cms_pages(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS blog_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS blog_articles (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    slug VARCHAR(255) NOT NULL,
    title VARCHAR(255) NOT NULL,
    excerpt TEXT NULL,
    body LONGTEXT NOT NULL,
    author_name VARCHAR(120) NULL,
    author_job_title VARCHAR(120) NULL,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
    meta_title VARCHAR(255) NULL,
    meta_description VARCHAR(255) NULL,
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_blog_category_slug (category_id, slug),
    CONSTRAINT fk_blog_articles_category FOREIGN KEY (category_id) REFERENCES blog_categories(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS blog_tags (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS blog_article_tags (
    article_id INT UNSIGNED NOT NULL,
    tag_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (article_id, tag_id),
    CONSTRAINT fk_blog_article_tags_article FOREIGN KEY (article_id) REFERENCES blog_articles(id) ON DELETE CASCADE,
    CONSTRAINT fk_blog_article_tags_tag FOREIGN KEY (tag_id) REFERENCES blog_tags(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS blog_article_blocks (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    article_id INT UNSIGNED NOT NULL,
    block_type VARCHAR(80) NOT NULL,
    order_index INT NOT NULL DEFAULT 0,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_blog_blocks_article FOREIGN KEY (article_id) REFERENCES blog_articles(id) ON DELETE CASCADE
);

CREATE INDEX idx_blog_article_tags_tag_id ON blog_article_tags(tag_id);

CREATE TABLE IF NOT EXISTS faq_categories (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS faq_items (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NULL,
    question VARCHAR(255) NOT NULL,
    answer TEXT NOT NULL,
    order_index INT NOT NULL DEFAULT 0,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_faq_items_category FOREIGN KEY (category_id) REFERENCES faq_categories(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS legal_pages (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    slug VARCHAR(120) NOT NULL UNIQUE,
    title VARCHAR(255) NOT NULL,
    paragraphs JSON NOT NULL,
    version INT UNSIGNED NOT NULL DEFAULT 1,
    status ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
    published_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS nav_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    label VARCHAR(120) NOT NULL,
    href VARCHAR(255) NOT NULL,
    location ENUM('header','footer') NOT NULL DEFAULT 'header',
    order_index INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE TABLE IF NOT EXISTS media_assets (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    url VARCHAR(255) NOT NULL,
    alt_text VARCHAR(255) NULL,
    media_type VARCHAR(80) NOT NULL DEFAULT 'image',
    metadata JSON NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

