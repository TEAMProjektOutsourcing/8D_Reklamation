<?php
require_once __DIR__ . '/auth.php';
require_admin();

$db = pdo();
$messages = [];

function migration_msg(array &$messages, string $type, string $text): void
{
    $messages[] = ['type' => $type, 'text' => $text];
}

function migration_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn() > 0);
}

function migration_index_exists(PDO $db, string $table, string $index): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return ((int)$stmt->fetchColumn() > 0);
}

function migration_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn() > 0);
}

function migration_add_column_if_missing(PDO $db, array &$messages, string $table, string $column, string $definition): void
{
    if (!migration_column_exists($db, $table, $column)) {
        $db->exec("ALTER TABLE `$table` ADD `$column` $definition");
        migration_msg($messages, 'success', "$table.$column ergänzt.");
    } else {
        migration_msg($messages, 'info', "$table.$column war bereits vorhanden.");
    }
}

try {
    $db->beginTransaction();

    $db->exec("CREATE TABLE IF NOT EXISTS standorte (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      kuerzel VARCHAR(20) NOT NULL,
      adresse VARCHAR(255) NULL,
      aktiv TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uq_standorte_kuerzel (kuerzel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    migration_msg($messages, 'success', 'Tabelle standorte geprüft/erstellt.');

    $stmt = $db->prepare("INSERT INTO standorte (name, kuerzel, adresse, aktiv)
        VALUES (?, ?, ?, 1)
        ON DUPLICATE KEY UPDATE name = VALUES(name), adresse = VALUES(adresse), aktiv = 1");
    $stmt->execute(['Wunstorf', 'WUN', 'Wunstorf']);
    $stmt->execute(['Hannover', 'HAN', 'Hannover']);
    migration_msg($messages, 'success', 'Standorte Wunstorf und Hannover geprüft/erstellt.');

    // Tabelle ohne Foreign Keys erstellen. Foreign Keys sind optional; so bleibt die Migration
    // auch dann stabil, wenn eine bestehende Installation leicht andere Tabellentypen hat.
    $db->exec("CREATE TABLE IF NOT EXISTS user_standorte (
      id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
      user_id INT UNSIGNED NOT NULL,
      standort_id INT UNSIGNED NOT NULL,
      standort_role ENUM('admin','standortleiter','quality','employee','viewer') NOT NULL DEFAULT 'employee',
      is_default TINYINT(1) NOT NULL DEFAULT 0,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uq_user_standort (user_id, standort_id),
      INDEX idx_user_standorte_standort (standort_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    migration_msg($messages, 'success', 'Tabelle user_standorte geprüft/erstellt.');

    // Reparatur für bereits halb angelegte Tabellen aus einem vorherigen Migrationslauf.
    migration_add_column_if_missing($db, $messages, 'user_standorte', 'standort_role', "ENUM('admin','standortleiter','quality','employee','viewer') NOT NULL DEFAULT 'employee' AFTER standort_id");
    migration_add_column_if_missing($db, $messages, 'user_standorte', 'is_default', "TINYINT(1) NOT NULL DEFAULT 0 AFTER standort_role");
    migration_add_column_if_missing($db, $messages, 'user_standorte', 'created_at', "DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER is_default");

    if (migration_column_exists($db, 'user_standorte', 'role')) {
        try {
            $db->exec("UPDATE user_standorte SET standort_role = role WHERE role IS NOT NULL AND standort_role = 'employee'");
            migration_msg($messages, 'info', 'Vorhandene Spalte user_standorte.role wurde nach standort_role übernommen.');
        } catch (Throwable $e) {
            migration_msg($messages, 'warning', 'Alte role-Spalte konnte nicht übernommen werden. Das Tool läuft trotzdem. Hinweis: ' . $e->getMessage());
        }
    }

    if (!migration_index_exists($db, 'user_standorte', 'uq_user_standort')) {
        try {
            $db->exec('ALTER TABLE user_standorte ADD UNIQUE KEY uq_user_standort (user_id, standort_id)');
            migration_msg($messages, 'success', 'Unique Index user_standorte ergänzt.');
        } catch (Throwable $e) {
            migration_msg($messages, 'warning', 'Unique Index user_standorte konnte nicht ergänzt werden. Hinweis: ' . $e->getMessage());
        }
    }
    if (!migration_index_exists($db, 'user_standorte', 'idx_user_standorte_standort')) {
        try {
            $db->exec('ALTER TABLE user_standorte ADD INDEX idx_user_standorte_standort (standort_id)');
            migration_msg($messages, 'success', 'Index user_standorte.standort_id ergänzt.');
        } catch (Throwable $e) {
            migration_msg($messages, 'warning', 'Index user_standorte.standort_id konnte nicht ergänzt werden. Hinweis: ' . $e->getMessage());
        }
    }

    if (!migration_column_exists($db, 'claims', 'standort_id')) {
        $db->exec('ALTER TABLE claims ADD standort_id INT UNSIGNED NULL AFTER id');
        migration_msg($messages, 'success', 'claims.standort_id ergänzt.');
    } else {
        migration_msg($messages, 'info', 'claims.standort_id war bereits vorhanden.');
    }

    foreach ([
        'source_module' => "ALTER TABLE claims ADD source_module VARCHAR(50) NULL AFTER responsible_user_id",
        'source_type' => "ALTER TABLE claims ADD source_type VARCHAR(50) NULL AFTER source_module",
        'source_id' => "ALTER TABLE claims ADD source_id INT UNSIGNED NULL AFTER source_type",
        'source_number' => "ALTER TABLE claims ADD source_number VARCHAR(100) NULL AFTER source_id",
        'source_url' => "ALTER TABLE claims ADD source_url VARCHAR(500) NULL AFTER source_number",
    ] as $column => $sql) {
        if (!migration_column_exists($db, 'claims', $column)) {
            $db->exec($sql);
            migration_msg($messages, 'success', 'claims.' . $column . ' ergänzt.');
        } else {
            migration_msg($messages, 'info', 'claims.' . $column . ' war bereits vorhanden.');
        }
    }

    $wunId = (int)$db->query("SELECT id FROM standorte WHERE kuerzel = 'WUN' LIMIT 1")->fetchColumn();
    $hanId = (int)$db->query("SELECT id FROM standorte WHERE kuerzel = 'HAN' LIMIT 1")->fetchColumn();

    if ($wunId > 0) {
        $upd = $db->prepare('UPDATE claims SET standort_id = ? WHERE standort_id IS NULL');
        $upd->execute([$wunId]);
        migration_msg($messages, 'success', 'Bestehende Reklamationen wurden Wunstorf zugeordnet.');
    }

    if (!migration_index_exists($db, 'claims', 'idx_claims_standort')) {
        $db->exec('ALTER TABLE claims ADD INDEX idx_claims_standort (standort_id)');
        migration_msg($messages, 'success', 'Index idx_claims_standort ergänzt.');
    }

    // Kein Foreign-Key-Zwang: Auf manchen IONOS/MariaDB-Installationen sind bestehende
    // Tabellen nicht exakt gleich definiert (SIGNED/UNSIGNED, Engine, Collation).
    // Die Standort-Zuordnung läuft stabil über standort_id + Index.
    migration_msg($messages, 'info', 'Foreign Key wurde bewusst übersprungen. Standort-Zuordnung läuft über claims.standort_id und den Index idx_claims_standort.');

    if ($wunId > 0) {
        $insert = $db->prepare("INSERT IGNORE INTO user_standorte (user_id, standort_id, standort_role, is_default)
            SELECT id, ?, CASE WHEN role IN ('admin','quality','employee','viewer') THEN role ELSE 'employee' END, 1 FROM users");
        $insert->execute([$wunId]);
        $db->prepare('UPDATE user_standorte SET is_default = 1 WHERE standort_id = ? AND user_id IN (SELECT id FROM users)')->execute([$wunId]);
        migration_msg($messages, 'success', 'Alle vorhandenen Benutzer wurden Wunstorf zugeordnet.');
    }

    if ($hanId > 0) {
        $adminInsert = $db->prepare("INSERT IGNORE INTO user_standorte (user_id, standort_id, standort_role, is_default)
            SELECT id, ?, 'admin', 0 FROM users WHERE role = 'admin'");
        $adminInsert->execute([$hanId]);
        migration_msg($messages, 'success', 'Admins wurden zusätzlich Hannover zugeordnet.');
    }

    $db->commit();
    unset($_SESSION['standort_id']);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    migration_msg($messages, 'danger', 'Migration fehlgeschlagen: ' . $e->getMessage());
}

require __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">Standort-Migration</h1>
        <div class="text-muted">Mehrstandort-Funktion für das 8D-Tool vorbereiten.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="locations.php" class="btn btn-outline-primary">Standorte öffnen</a>
        <a href="dashboard.php" class="btn btn-primary">Zum Dashboard</a>
    </div>
</div>
<div class="card"><div class="card-body">
    <?php foreach ($messages as $msg): ?>
        <div class="alert alert-<?= e($msg['type'] === 'warning' ? 'warning' : $msg['type']) ?> mb-2"><?= e($msg['text']) ?></div>
    <?php endforeach; ?>
    <div class="alert alert-info mt-3 mb-0">
        Danach kannst du oben im Header den Standort wechseln. Diese Datei kannst du nach erfolgreicher Migration löschen.
    </div>
</div></div>
<?php require __DIR__ . '/footer.php'; ?>
