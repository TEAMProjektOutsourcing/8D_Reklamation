-- 8D Reklamationstool
-- Maßnahmen gelesen / ungelesen für NAV-Badge

CREATE TABLE IF NOT EXISTS claim_action_reads (
    id INT AUTO_INCREMENT PRIMARY KEY,
    action_id INT NOT NULL,
    user_id INT NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_claim_action_read_user (action_id, user_id),
    INDEX idx_claim_action_reads_action (action_id),
    INDEX idx_claim_action_reads_user (user_id),
    INDEX idx_claim_action_reads_read_at (read_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
