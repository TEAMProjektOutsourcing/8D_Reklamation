-- Optionaler Duplikat-Fix für claim_groups
-- Sicherer Ansatz: nicht löschen, sondern doppelte globale Standardgruppen deaktivieren,
-- wenn bereits eine aktive Standortgruppe mit gleichem Namen existiert.
-- Vorher bitte DB-Backup machen.

UPDATE claim_groups g
SET g.active = 0,
    g.updated_at = NOW()
WHERE g.standort_id IS NULL
  AND LOWER(g.name) IN ('logistik', 'qualität', 'qualitaet', 'verkauf', 'vertrieb', 'management', 'managment')
  AND EXISTS (
      SELECT 1
      FROM claim_groups s
      WHERE s.standort_id IS NOT NULL
        AND s.active = 1
        AND (
            LOWER(s.name) = LOWER(g.name)
            OR (LOWER(g.name) IN ('verkauf','vertrieb') AND LOWER(s.name) IN ('verkauf','vertrieb'))
            OR (LOWER(g.name) IN ('management','managment') AND LOWER(s.name) IN ('management','managment'))
            OR (LOWER(g.name) IN ('qualität','qualitaet') AND LOWER(s.name) IN ('qualität','qualitaet'))
        )
  );

-- Kontrolle:
SELECT id, standort_id, name, description, active, created_at
FROM claim_groups
WHERE LOWER(name) IN ('logistik', 'qualität', 'qualitaet', 'verkauf', 'vertrieb', 'management', 'managment')
ORDER BY name, standort_id IS NULL, standort_id, id;
