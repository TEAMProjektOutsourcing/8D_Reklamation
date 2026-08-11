CREATE TABLE IF NOT EXISTS claim_groups (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  standort_id INT UNSIGNED NULL,
  name VARCHAR(120) NOT NULL,
  description TEXT NULL,
  color VARCHAR(30) NOT NULL DEFAULT 'secondary',
  active TINYINT(1) NOT NULL DEFAULT 1,
  notify_on_assign TINYINT(1) NOT NULL DEFAULT 0,
  create_action_on_assign TINYINT(1) NOT NULL DEFAULT 1,
  default_due_days INT UNSIGNED NOT NULL DEFAULT 2,
  created_by INT UNSIGNED NULL,
  updated_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_claim_groups_standort (standort_id),
  KEY idx_claim_groups_active (active),
  UNIQUE KEY uq_claim_groups_scope_name (standort_id, name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claim_group_members (
  group_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (group_id, user_id),
  KEY idx_claim_group_members_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS claim_group_assignments (
  claim_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  assigned_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (claim_id, group_id),
  KEY idx_claim_group_assignments_group (group_id),
  KEY idx_claim_group_assignments_claim (claim_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- Falls claim_groups bereits existiert, diese Spalten ergänzen:
-- ALTER TABLE claim_groups ADD COLUMN notify_on_assign TINYINT(1) NOT NULL DEFAULT 0 AFTER active;
-- ALTER TABLE claim_groups ADD COLUMN create_action_on_assign TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_on_assign;
-- ALTER TABLE claim_groups ADD COLUMN default_due_days INT UNSIGNED NOT NULL DEFAULT 2 AFTER create_action_on_assign;
