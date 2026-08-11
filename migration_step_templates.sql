CREATE TABLE IF NOT EXISTS step_templates (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  step_key ENUM('D1','D2','D3','D4','D5','D6','D7','D8') NOT NULL,
  title VARCHAR(190) NOT NULL,
  description TEXT NULL,
  help_text TEXT NULL,
  required_fields TEXT NULL,
  sort_order TINYINT UNSIGNED NOT NULL DEFAULT 1,
  version_no INT UNSIGNED NOT NULL DEFAULT 1,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_step_templates_active_version (is_active, version_no),
  UNIQUE KEY uq_step_template_version (step_key, version_no)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS step_template_history (
  id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  version_no INT UNSIGNED NOT NULL,
  base_version_no INT UNSIGNED NULL,
  step_key ENUM('D1','D2','D3','D4','D5','D6','D7','D8') NULL,
  field_name VARCHAR(80) NULL,
  old_value MEDIUMTEXT NULL,
  new_value MEDIUMTEXT NULL,
  action VARCHAR(60) NOT NULL DEFAULT 'field_changed',
  changed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_step_template_history_version (version_no),
  KEY idx_step_template_history_step (step_key),
  KEY idx_step_template_history_user (changed_by),
  KEY idx_step_template_history_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Falls noch nicht vorhanden:
-- ALTER TABLE claim_steps ADD COLUMN template_version INT UNSIGNED NULL AFTER step_key;
