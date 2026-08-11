-- Standardgruppen für 8D Claim-Gruppen
-- Nur nötig, falls du die Gruppen manuell in der DB anlegen willst.
-- Die neue claim_create.php legt fehlende globale Standardgruppen beim Öffnen automatisch an.

INSERT INTO claim_groups
    (standort_id, name, description, color, active, notify_on_assign, create_action_on_assign, default_due_days, created_by)
SELECT NULL, 'Logistik', 'Operations Leitung · Manuel Edel', 'primary', 1, 0, 1, 2, NULL
WHERE NOT EXISTS (
    SELECT 1 FROM claim_groups WHERE standort_id IS NULL AND LOWER(name) = LOWER('Logistik')
);

INSERT INTO claim_groups
    (standort_id, name, description, color, active, notify_on_assign, create_action_on_assign, default_due_days, created_by)
SELECT NULL, 'Qualität', 'Moritz Maucher', 'success', 1, 0, 1, 2, NULL
WHERE NOT EXISTS (
    SELECT 1 FROM claim_groups WHERE standort_id IS NULL AND LOWER(name) = LOWER('Qualität')
);

INSERT INTO claim_groups
    (standort_id, name, description, color, active, notify_on_assign, create_action_on_assign, default_due_days, created_by)
SELECT NULL, 'Verkauf', 'Marvin Maier · Rachid Kadi', 'info', 1, 0, 1, 2, NULL
WHERE NOT EXISTS (
    SELECT 1 FROM claim_groups WHERE standort_id IS NULL AND LOWER(name) = LOWER('Verkauf')
);

INSERT INTO claim_groups
    (standort_id, name, description, color, active, notify_on_assign, create_action_on_assign, default_due_days, created_by)
SELECT NULL, 'Management', 'Christian Besier · Andreas Klug', 'dark', 1, 0, 1, 2, NULL
WHERE NOT EXISTS (
    SELECT 1 FROM claim_groups WHERE standort_id IS NULL AND LOWER(name) = LOWER('Management')
);

UPDATE claim_groups
SET active = 1, description = 'Christian Besier · Andreas Klug', color = 'dark'
WHERE standort_id IS NULL AND LOWER(name) = LOWER('Management');

UPDATE claim_groups
SET active = 1, description = 'Marvin Maier · Rachid Kadi', color = 'info'
WHERE standort_id IS NULL AND LOWER(name) = LOWER('Verkauf');
