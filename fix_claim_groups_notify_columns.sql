-- Fix für fehlende Spalten in claim_groups
-- Nur ausführen, wenn die Spalten noch fehlen.

ALTER TABLE claim_groups
ADD COLUMN notify_on_assign TINYINT(1) NOT NULL DEFAULT 0 AFTER active;

ALTER TABLE claim_groups
ADD COLUMN create_action_on_assign TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_on_assign;

ALTER TABLE claim_groups
ADD COLUMN default_due_days INT UNSIGNED NOT NULL DEFAULT 2 AFTER create_action_on_assign;
