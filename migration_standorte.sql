-- Migration: Mehrere Standorte für das 8D-Reklamationstool
-- Diese Datei kann gefahrlos mehrfach importiert werden.

CREATE TABLE IF NOT EXISTS standorte (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(100) NOT NULL,
  kuerzel VARCHAR(20) NOT NULL,
  adresse VARCHAR(255) NULL,
  aktiv TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_standorte_kuerzel (kuerzel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO standorte (name, kuerzel, adresse, aktiv)
VALUES
('Wunstorf', 'WUN', 'Wunstorf', 1),
('Hannover', 'HAN', 'Hannover', 1)
ON DUPLICATE KEY UPDATE name = VALUES(name), adresse = VALUES(adresse), aktiv = VALUES(aktiv);

CREATE TABLE IF NOT EXISTS user_standorte (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  standort_id INT UNSIGNED NOT NULL,
  standort_role ENUM('admin','standortleiter','quality','employee','viewer') NOT NULL DEFAULT 'employee',
  is_default TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_user_standort (user_id, standort_id),
  INDEX idx_user_standorte_standort (standort_id),
  CONSTRAINT fk_user_standorte_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_user_standorte_standort FOREIGN KEY (standort_id) REFERENCES standorte(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Die ALTERs werden im PHP-Migrationsskript sicher geprüft.
-- Falls du manuell importierst und die Spalten fehlen, nutze besser run_location_migration.php.
