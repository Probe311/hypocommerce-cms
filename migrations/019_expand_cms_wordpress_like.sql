ALTER TABLE blog_categories
    ADD COLUMN parent_id INT UNSIGNED NULL AFTER id,
    ADD COLUMN status ENUM('draft','published','archived') NOT NULL DEFAULT 'published' AFTER description,
    ADD COLUMN meta_title VARCHAR(255) NULL AFTER status,
    ADD COLUMN meta_description VARCHAR(255) NULL AFTER meta_title;

ALTER TABLE blog_categories
    ADD CONSTRAINT fk_blog_categories_parent FOREIGN KEY (parent_id) REFERENCES blog_categories(id) ON DELETE SET NULL;

ALTER TABLE blog_articles
    ADD COLUMN featured_media_id INT UNSIGNED NULL AFTER author_job_title,
    ADD COLUMN updated_by_admin_id INT UNSIGNED NULL AFTER updated_at;

CREATE INDEX idx_blog_categories_status ON blog_categories(status);
CREATE INDEX idx_blog_articles_status_published_at ON blog_articles(status, published_at);

CREATE TABLE IF NOT EXISTS cms_content_revisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('page','article','article_category','menu','site_setting') NOT NULL,
    entity_id VARCHAR(128) NOT NULL,
    version INT UNSIGNED NOT NULL,
    title VARCHAR(255) NULL,
    payload JSON NOT NULL,
    created_by_admin_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    UNIQUE KEY uniq_cms_content_revision (entity_type, entity_id, version),
    KEY idx_cms_content_revision_entity (entity_type, entity_id, created_at),
    CONSTRAINT fk_cms_content_revisions_admin FOREIGN KEY (created_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_workflow_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    entity_type ENUM('page','article','article_category') NOT NULL,
    entity_id VARCHAR(128) NOT NULL,
    from_status VARCHAR(32) NULL,
    to_status VARCHAR(32) NOT NULL,
    note VARCHAR(255) NULL,
    changed_by_admin_id INT UNSIGNED NULL,
    changed_at DATETIME NOT NULL,
    KEY idx_cms_workflow_entity (entity_type, entity_id, changed_at),
    CONSTRAINT fk_cms_workflow_events_admin FOREIGN KEY (changed_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_media_library (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    path VARCHAR(255) NOT NULL,
    url VARCHAR(255) NOT NULL,
    filename VARCHAR(255) NOT NULL,
    extension VARCHAR(16) NOT NULL,
    mime_type VARCHAR(120) NOT NULL,
    size_bytes BIGINT UNSIGNED NOT NULL DEFAULT 0,
    width INT UNSIGNED NULL,
    height INT UNSIGNED NULL,
    title VARCHAR(255) NULL,
    alt_text VARCHAR(255) NULL,
    caption TEXT NULL,
    description TEXT NULL,
    folder VARCHAR(120) NOT NULL DEFAULT 'general',
    uploaded_by_admin_id INT UNSIGNED NULL,
    status ENUM('active','archived') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_cms_media_folder_status (folder, status),
    KEY idx_cms_media_mime_status (mime_type, status),
    CONSTRAINT fk_cms_media_admin FOREIGN KEY (uploaded_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_media_usages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    media_id BIGINT UNSIGNED NOT NULL,
    entity_type ENUM('page','article','article_category','site_setting') NOT NULL,
    entity_id VARCHAR(128) NOT NULL,
    field_name VARCHAR(64) NOT NULL,
    created_at DATETIME NOT NULL,
    KEY idx_cms_media_usages_lookup (entity_type, entity_id),
    CONSTRAINT fk_cms_media_usages_media FOREIGN KEY (media_id) REFERENCES cms_media_library(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_navigation_menus (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    menu_key VARCHAR(80) NOT NULL UNIQUE,
    label VARCHAR(120) NOT NULL,
    location ENUM('header','footer','secondary') NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    active_version INT UNSIGNED NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_navigation_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    menu_id INT UNSIGNED NOT NULL,
    parent_id BIGINT UNSIGNED NULL,
    label VARCHAR(120) NOT NULL,
    target_type ENUM('page','article','article_category','external_url','anchor') NOT NULL,
    target_ref VARCHAR(255) NOT NULL,
    icon VARCHAR(64) NULL,
    order_index INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_cms_nav_items_menu_order (menu_id, order_index),
    CONSTRAINT fk_cms_nav_items_menu FOREIGN KEY (menu_id) REFERENCES cms_navigation_menus(id) ON DELETE CASCADE,
    CONSTRAINT fk_cms_nav_items_parent FOREIGN KEY (parent_id) REFERENCES cms_navigation_items(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_site_social_links (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    platform VARCHAR(60) NOT NULL,
    icon_key VARCHAR(60) NOT NULL,
    url VARCHAR(255) NOT NULL,
    order_index INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    UNIQUE KEY uniq_cms_social_platform (platform)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_site_footer_sections (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    section_key VARCHAR(80) NOT NULL UNIQUE,
    title VARCHAR(120) NOT NULL,
    order_index INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_site_footer_links (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    section_id INT UNSIGNED NOT NULL,
    label VARCHAR(120) NOT NULL,
    href VARCHAR(255) NOT NULL,
    target VARCHAR(16) NOT NULL DEFAULT '_self',
    order_index INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    KEY idx_cms_footer_links_section_order (section_id, order_index),
    CONSTRAINT fk_cms_footer_links_section FOREIGN KEY (section_id) REFERENCES cms_site_footer_sections(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cms_site_settings (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value JSON NOT NULL,
    updated_by_admin_id INT UNSIGNED NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    CONSTRAINT fk_cms_site_settings_admin FOREIGN KEY (updated_by_admin_id) REFERENCES admin_users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
