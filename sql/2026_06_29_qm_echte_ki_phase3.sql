-- 8D Reklamationstool
-- Echte KI Phase 3 - optionale Zusatzfelder für KI-Auswertung

SET @db_name := DATABASE();

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claim_ai_analysis' AND COLUMN_NAME = 'ai_provider') = 0,
    'ALTER TABLE claim_ai_analysis ADD COLUMN ai_provider VARCHAR(50) NULL AFTER claim_id',
    'SELECT "claim_ai_analysis.ai_provider already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claim_ai_analysis' AND COLUMN_NAME = 'ai_model') = 0,
    'ALTER TABLE claim_ai_analysis ADD COLUMN ai_model VARCHAR(120) NULL AFTER ai_provider',
    'SELECT "claim_ai_analysis.ai_model already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claim_ai_analysis' AND COLUMN_NAME = 'confidence_score') = 0,
    'ALTER TABLE claim_ai_analysis ADD COLUMN confidence_score DECIMAL(5,2) NULL AFTER effectiveness_warning',
    'SELECT "claim_ai_analysis.confidence_score already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claim_ai_analysis' AND COLUMN_NAME = 'ai_raw_json') = 0,
    'ALTER TABLE claim_ai_analysis ADD COLUMN ai_raw_json LONGTEXT NULL AFTER recommendation',
    'SELECT "claim_ai_analysis.ai_raw_json already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = @db_name AND TABLE_NAME = 'claim_ai_similarity' AND COLUMN_NAME = 'source_provider') = 0,
    'ALTER TABLE claim_ai_similarity ADD COLUMN source_provider VARCHAR(50) NULL AFTER reason',
    'SELECT "claim_ai_similarity.source_provider already exists"'
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
