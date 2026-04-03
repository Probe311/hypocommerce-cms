CREATE TABLE IF NOT EXISTS seo_site_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    site_name VARCHAR(160) NOT NULL DEFAULT 'Nexora',
    title_template VARCHAR(255) NOT NULL DEFAULT '{title} | {site_name}',
    meta_description_template VARCHAR(255) NOT NULL DEFAULT '{title} - {site_name}',
    canonical_base VARCHAR(255) NULL,
    robots_default VARCHAR(20) NOT NULL DEFAULT 'index,follow',
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_social_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    og_site_name VARCHAR(160) NULL,
    default_og_image_url VARCHAR(255) NULL,
    twitter_card VARCHAR(40) NOT NULL DEFAULT 'summary_large_image',
    twitter_site VARCHAR(120) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_schema_settings (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    organization_name VARCHAR(180) NULL,
    organization_url VARCHAR(255) NULL,
    organization_logo_url VARCHAR(255) NULL,
    website_name VARCHAR(180) NULL,
    website_url VARCHAR(255) NULL,
    search_url_template VARCHAR(255) NULL,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_indexation_rules (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    entity_type VARCHAR(40) NOT NULL,
    robots_directive VARCHAR(20) NOT NULL DEFAULT 'index,follow',
    include_in_sitemap TINYINT(1) NOT NULL DEFAULT 1,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_seo_indexation_entity_type (entity_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS seo_eeat_defaults (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    default_author_name VARCHAR(180) NULL,
    default_author_role VARCHAR(180) NULL,
    trust_statement TEXT NULL,
    faq_template TEXT NULL,
    min_score_default INT NOT NULL DEFAULT 60,
    updated_by BIGINT UNSIGNED NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO seo_site_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id;
INSERT INTO seo_social_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id;
INSERT INTO seo_schema_settings (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id;
INSERT INTO seo_eeat_defaults (id) VALUES (1) ON DUPLICATE KEY UPDATE id = id;

INSERT INTO seo_indexation_rules (entity_type, robots_directive, include_in_sitemap)
VALUES
('product', 'index,follow', 1),
('category', 'index,follow', 1),
('cms_page', 'index,follow', 1),
('blog_article', 'index,follow', 1),
('faq_item', 'index,follow', 1),
('legal_page', 'index,follow', 1)
ON DUPLICATE KEY UPDATE
robots_directive = VALUES(robots_directive),
include_in_sitemap = VALUES(include_in_sitemap);

