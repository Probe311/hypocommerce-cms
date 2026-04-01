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

CREATE INDEX idx_order_refunds_order_status ON order_refunds (order_id, status);
