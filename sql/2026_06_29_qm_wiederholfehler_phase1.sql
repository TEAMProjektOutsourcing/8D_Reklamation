-- 8D Reklamationstool
-- QM Wiederholfehler & Maßnahmenwirksamkeit - Phase 1
-- Bitte einmalig in der Datenbank ausführen.

-- Neue strukturierte QM-Felder an claims ergänzen.
SET @db_name := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claims' AND COLUMN_NAME = 'error_category') = 0,
    'ALTER TABLE claims ADD COLUMN error_category VARCHAR(120) NULL AFTER problem_description',
    'SELECT "claims.error_category already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claims' AND COLUMN_NAME = 'error_pattern') = 0,
    'ALTER TABLE claims ADD COLUMN error_pattern VARCHAR(120) NULL AFTER error_category',
    'SELECT "claims.error_pattern already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claims' AND COLUMN_NAME = 'process_area') = 0,
    'ALTER TABLE claims ADD COLUMN process_area VARCHAR(120) NULL AFTER error_pattern',
    'SELECT "claims.process_area already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claims' AND COLUMN_NAME = 'root_cause_category') = 0,
    'ALTER TABLE claims ADD COLUMN root_cause_category VARCHAR(120) NULL AFTER process_area',
    'SELECT "claims.root_cause_category already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Indexe für schnelle Auswertung / Wiederholfehler-Erkennung.
SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claims' AND INDEX_NAME = 'idx_claims_qm_error') = 0,
    'CREATE INDEX idx_claims_qm_error ON claims (error_category, error_pattern, process_area)',
    'SELECT "idx_claims_qm_error already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claims' AND INDEX_NAME = 'idx_claims_qm_repeat') = 0,
    'CREATE INDEX idx_claims_qm_repeat ON claims (claim_date, article_number, partner_name)',
    'SELECT "idx_claims_qm_repeat already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- KI-Vorbereitung: Ergebnisse später speichern, ohne jetzt schon eine KI-Anbindung zu erzwingen.
CREATE TABLE IF NOT EXISTS claim_ai_analysis (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    ai_summary TEXT NULL,
    detected_error_pattern VARCHAR(190) NULL,
    effectiveness_warning VARCHAR(50) NULL,
    recommendation TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_claim_ai_analysis_claim (claim_id),
    CONSTRAINT fk_claim_ai_analysis_claim
        FOREIGN KEY (claim_id) REFERENCES claims(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claim_ai_similarity (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    similar_claim_id INT NOT NULL,
    similarity_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    reason TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_claim_ai_similarity_claim (claim_id),
    INDEX idx_claim_ai_similarity_similar (similar_claim_id),
    CONSTRAINT fk_claim_ai_similarity_claim
        FOREIGN KEY (claim_id) REFERENCES claims(id)
        ON DELETE CASCADE,
    CONSTRAINT fk_claim_ai_similarity_similar_claim
        FOREIGN KEY (similar_claim_id) REFERENCES claims(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
