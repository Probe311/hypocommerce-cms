ALTER TABLE eeat_recommendations
    ADD COLUMN owner VARCHAR(120) NULL AFTER status,
    ADD COLUMN due_date DATE NULL AFTER owner,
    ADD COLUMN note TEXT NULL AFTER due_date,
    ADD COLUMN last_status_change_at DATETIME NULL AFTER note;

CREATE INDEX idx_eeat_reco_owner_status ON eeat_recommendations(owner, status);
CREATE INDEX idx_eeat_reco_due_date ON eeat_recommendations(due_date);
