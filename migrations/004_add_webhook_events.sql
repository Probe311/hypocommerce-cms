CREATE TABLE IF NOT EXISTS webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    provider VARCHAR(32) NOT NULL,
    event_id VARCHAR(191) NOT NULL,
    event_type VARCHAR(191) DEFAULT NULL,
    payload JSON NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'received',
    attempt_count INT UNSIGNED NOT NULL DEFAULT 1,
    last_error TEXT NULL,
    next_retry_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uniq_webhook_provider_event (provider, event_id),
    KEY idx_webhook_status_next_retry (status, next_retry_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
