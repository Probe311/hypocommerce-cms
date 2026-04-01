<?php

declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Usage: php bin/apply_cms_schema.php <host> <db> <user> <password>\n");
    exit(1);
}

$host = $argv[1];
$db = $argv[2];
$user = $argv[3];
$password = $argv[4];

$statements = [
    "CREATE TABLE IF NOT EXISTS cms_pages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(255) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        template VARCHAR(100) NOT NULL,
        status ENUM('draft','published','archived') NOT NULL DEFAULT 'draft',
        meta_title VARCHAR(255) NULL,
        meta_description VARCHAR(255) NULL,
        published_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS cms_page_sections (
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
    )",
    "CREATE TABLE IF NOT EXISTS blog_categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(120) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        description TEXT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS blog_articles (
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
    )",
    "CREATE TABLE IF NOT EXISTS blog_article_blocks (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        article_id INT UNSIGNED NOT NULL,
        block_type VARCHAR(80) NOT NULL,
        order_index INT NOT NULL DEFAULT 0,
        payload JSON NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT fk_blog_blocks_article FOREIGN KEY (article_id) REFERENCES blog_articles(id) ON DELETE CASCADE
    )",
    "CREATE TABLE IF NOT EXISTS faq_categories (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(120) NOT NULL UNIQUE,
        name VARCHAR(120) NOT NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS faq_items (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        category_id INT UNSIGNED NULL,
        question VARCHAR(255) NOT NULL,
        answer TEXT NOT NULL,
        order_index INT NOT NULL DEFAULT 0,
        status ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL,
        CONSTRAINT fk_faq_items_category FOREIGN KEY (category_id) REFERENCES faq_categories(id) ON DELETE SET NULL
    )",
    "CREATE TABLE IF NOT EXISTS legal_pages (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        slug VARCHAR(120) NOT NULL UNIQUE,
        title VARCHAR(255) NOT NULL,
        paragraphs JSON NOT NULL,
        version INT UNSIGNED NOT NULL DEFAULT 1,
        status ENUM('draft','published','archived') NOT NULL DEFAULT 'published',
        published_at DATETIME NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS nav_links (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        label VARCHAR(120) NOT NULL,
        href VARCHAR(255) NOT NULL,
        location ENUM('header','footer') NOT NULL DEFAULT 'header',
        order_index INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )",
    "CREATE TABLE IF NOT EXISTS media_assets (
        id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
        url VARCHAR(255) NOT NULL,
        alt_text VARCHAR(255) NULL,
        media_type VARCHAR(80) NOT NULL DEFAULT 'image',
        metadata JSON NULL,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )",
];

try {
    $pdo = new PDO(
        "mysql:host={$host};port=3306;dbname={$db};charset=utf8mb4",
        $user,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    foreach ($statements as $sql) {
        $pdo->exec($sql);
    }
    echo "CMS schema applique.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "Echec schema CMS: " . $e->getMessage() . "\n");
    exit(1);
}
