CREATE INDEX idx_products_updated_at ON products(updated_at);
CREATE INDEX idx_orders_placed_at ON orders(placed_at);
CREATE INDEX idx_orders_updated_at ON orders(updated_at);
CREATE INDEX idx_cms_pages_status_updated ON cms_pages(status, updated_at);
CREATE INDEX idx_blog_articles_status_published ON blog_articles(status, published_at);
