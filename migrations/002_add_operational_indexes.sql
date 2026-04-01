CREATE INDEX idx_pages_slug ON pages(slug);
CREATE INDEX idx_pages_published_at ON pages(published_at);
CREATE INDEX idx_blog_articles_status_published_at ON blog_articles(status, published_at);
CREATE INDEX idx_blog_articles_slug ON blog_articles(slug);
CREATE INDEX idx_faq_items_status_order ON faq_items(status, order_index);
CREATE INDEX idx_legal_pages_slug_status ON legal_pages(slug, status);
