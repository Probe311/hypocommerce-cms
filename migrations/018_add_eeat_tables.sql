CREATE TABLE IF NOT EXISTS eeat_analysis_runs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_type VARCHAR(32) NOT NULL,
    scope_json JSON NOT NULL,
    stats_json JSON NULL,
    status VARCHAR(32) NOT NULL DEFAULT 'running',
    started_at DATETIME NOT NULL,
    ended_at DATETIME NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_eeat_runs_status_started (status, started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eeat_scores (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    run_id BIGINT UNSIGNED NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id VARCHAR(128) NOT NULL,
    entity_slug VARCHAR(255) NULL,
    locale VARCHAR(8) NOT NULL DEFAULT 'fr',
    score_global DECIMAL(5,2) NOT NULL,
    score_experience DECIMAL(5,2) NOT NULL,
    score_expertise DECIMAL(5,2) NOT NULL,
    score_authoritativeness DECIMAL(5,2) NOT NULL,
    score_trust DECIMAL(5,2) NOT NULL,
    grade CHAR(1) NOT NULL,
    blockers_count INT UNSIGNED NOT NULL DEFAULT 0,
    signals_json JSON NOT NULL,
    version VARCHAR(32) NOT NULL,
    computed_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_eeat_scores_entity (entity_type, entity_id, locale),
    KEY idx_eeat_scores_computed (computed_at),
    KEY idx_eeat_scores_grade (grade),
    KEY idx_eeat_scores_run (run_id),
    CONSTRAINT fk_eeat_scores_run
        FOREIGN KEY (run_id) REFERENCES eeat_analysis_runs(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eeat_recommendations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    score_id BIGINT UNSIGNED NOT NULL,
    entity_type VARCHAR(32) NOT NULL,
    entity_id VARCHAR(128) NOT NULL,
    rule_code VARCHAR(64) NOT NULL,
    severity VARCHAR(16) NOT NULL,
    impact VARCHAR(16) NOT NULL,
    effort VARCHAR(16) NOT NULL,
    message TEXT NOT NULL,
    fix_suggestion TEXT NOT NULL,
    status VARCHAR(16) NOT NULL DEFAULT 'open',
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_eeat_reco_severity_status (severity, status),
    KEY idx_eeat_reco_entity (entity_type, entity_id),
    CONSTRAINT fk_eeat_reco_score
        FOREIGN KEY (score_id) REFERENCES eeat_scores(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS eeat_score_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    score_id BIGINT UNSIGNED NOT NULL,
    run_id BIGINT UNSIGNED NULL,
    score_global DECIMAL(5,2) NOT NULL,
    score_experience DECIMAL(5,2) NOT NULL,
    score_expertise DECIMAL(5,2) NOT NULL,
    score_authoritativeness DECIMAL(5,2) NOT NULL,
    score_trust DECIMAL(5,2) NOT NULL,
    grade CHAR(1) NOT NULL,
    signals_json JSON NOT NULL,
    computed_at DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_eeat_history_computed (computed_at),
    CONSTRAINT fk_eeat_history_score
        FOREIGN KEY (score_id) REFERENCES eeat_scores(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_eeat_history_run
        FOREIGN KEY (run_id) REFERENCES eeat_analysis_runs(id)
        ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
