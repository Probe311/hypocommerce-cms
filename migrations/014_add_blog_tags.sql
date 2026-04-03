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

CREATE INDEX idx_blog_article_tags_tag_id ON blog_article_tags(tag_id);

INSERT INTO blog_tags (slug, name, created_at, updated_at)
VALUES ('general', 'General', NOW(), NOW())
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    updated_at = VALUES(updated_at);

INSERT INTO blog_article_tags (article_id, tag_id)
SELECT a.id, t.id
FROM blog_articles a
INNER JOIN blog_tags t ON t.slug = 'general'
LEFT JOIN blog_article_tags bat ON bat.article_id = a.id
WHERE bat.article_id IS NULL;
