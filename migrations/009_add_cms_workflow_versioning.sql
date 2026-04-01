ALTER TABLE cms_pages
    MODIFY status ENUM('draft','in_review','scheduled','published','archived') NOT NULL DEFAULT 'draft',
    ADD COLUMN reviewed_at DATETIME NULL AFTER published_at,
    ADD COLUMN scheduled_at DATETIME NULL AFTER reviewed_at,
    ADD COLUMN review_note VARCHAR(255) NULL AFTER scheduled_at;

CREATE TABLE cms_page_versions (
    id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    page_id INT UNSIGNED NOT NULL,
    version INT UNSIGNED NOT NULL,
    payload JSON NOT NULL,
    created_at DATETIME NOT NULL,
    CONSTRAINT fk_cms_page_versions_page FOREIGN KEY (page_id) REFERENCES cms_pages(id) ON DELETE CASCADE,
    UNIQUE KEY uniq_cms_page_version (page_id, version)
);
