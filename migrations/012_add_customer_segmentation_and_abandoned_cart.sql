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
