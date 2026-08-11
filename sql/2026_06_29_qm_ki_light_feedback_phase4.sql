-- 8D Reklamationstool
-- KI-Light Feedback & Trainingsdatenbasis Phase 4
--
-- Ziel:
-- QM kann KI-Light Bewertungen bestätigen/korrigieren.
-- Daraus entsteht später eine eigene Trainingsdatenbasis.

CREATE TABLE IF NOT EXISTS claim_ai_feedback (
    id INT AUTO_INCREMENT PRIMARY KEY,
    claim_id INT NOT NULL,
    analysis_id INT NULL,
    user_id INT NULL,
    feedback_type VARCHAR(60) NOT NULL,
    feedback_value VARCHAR(60) NOT NULL,
    note TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_claim_ai_feedback_claim (claim_id),
    INDEX idx_claim_ai_feedback_analysis (analysis_id),
    INDEX idx_claim_ai_feedback_type (feedback_type),
    INDEX idx_claim_ai_feedback_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Optionaler Export-/Lernstatus direkt an der Analyse.
SET @db_name := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claim_ai_analysis' AND COLUMN_NAME = 'feedback_status') = 0,
    'ALTER TABLE claim_ai_analysis ADD COLUMN feedback_status VARCHAR(60) NULL AFTER effectiveness_warning',
    'SELECT "claim_ai_analysis.feedback_status already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claim_ai_analysis' AND COLUMN_NAME = 'feedback_note') = 0,
    'ALTER TABLE claim_ai_analysis ADD COLUMN feedback_note TEXT NULL AFTER feedback_status',
    'SELECT "claim_ai_analysis.feedback_note already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claim_ai_analysis' AND COLUMN_NAME = 'feedback_at') = 0,
    'ALTER TABLE claim_ai_analysis ADD COLUMN feedback_at DATETIME NULL AFTER feedback_note',
    'SELECT "claim_ai_analysis.feedback_at already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claim_ai_analysis' AND COLUMN_NAME = 'feedback_user_id') = 0,
    'ALTER TABLE claim_ai_analysis ADD COLUMN feedback_user_id INT NULL AFTER feedback_at',
    'SELECT "claim_ai_analysis.feedback_user_id already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
