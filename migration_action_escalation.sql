-- Ampel-Eskalation für offene Maßnahmen

ALTER TABLE claim_groups
ADD COLUMN escalate_yellow TINYINT(1) NOT NULL DEFAULT 0 AFTER default_due_days;

ALTER TABLE claim_groups
ADD COLUMN escalate_red TINYINT(1) NOT NULL DEFAULT 0 AFTER escalate_yellow;

CREATE TABLE IF NOT EXISTS claim_action_escalation_log (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    claim_id INT UNSIGNED NOT NULL,
    action_id INT UNSIGNED NOT NULL,
    escalation_level ENUM('yellow','red') NOT NULL,
    recipient_user_id INT UNSIGNED NOT NULL,
    recipient_email VARCHAR(190) NOT NULL,
    group_names VARCHAR(500) NULL,
    sent TINYINT(1) NOT NULL DEFAULT 0,
    error_message TEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_action_escalation_once (action_id, escalation_level, recipient_user_id),
    KEY idx_action_escalation_claim (claim_id),
    KEY idx_action_escalation_action (action_id),
    KEY idx_action_escalation_level (escalation_level),
    KEY idx_action_escalation_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
