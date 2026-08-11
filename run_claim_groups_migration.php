<?php
require_once __DIR__ . '/auth.php';
require_admin();

$db = pdo();

function claim_group_migration_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $db->exec("CREATE TABLE IF NOT EXISTS claim_groups (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!claim_group_migration_column_exists($db, 'claim_groups', 'notify_on_assign')) {
        $db->exec("ALTER TABLE claim_groups ADD COLUMN notify_on_assign TINYINT(1) NOT NULL DEFAULT 0 AFTER active");
    }
    if (!claim_group_migration_column_exists($db, 'claim_groups', 'create_action_on_assign')) {
        $db->exec("ALTER TABLE claim_groups ADD COLUMN create_action_on_assign TINYINT(1) NOT NULL DEFAULT 1 AFTER notify_on_assign");
    }
    if (!claim_group_migration_column_exists($db, 'claim_groups', 'default_due_days')) {
        $db->exec("ALTER TABLE claim_groups ADD COLUMN default_due_days INT UNSIGNED NOT NULL DEFAULT 2 AFTER create_action_on_assign");
    }

    $db->exec("CREATE TABLE IF NOT EXISTS claim_group_members (
        group_id INT UNSIGNED NOT NULL,
        user_id INT UNSIGNED NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (group_id, user_id),
        KEY idx_claim_group_members_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $db->exec("CREATE TABLE IF NOT EXISTS claim_group_assignments (
        claim_id INT UNSIGNED NOT NULL,
        group_id INT UNSIGNED NOT NULL,
        assigned_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (claim_id, group_id),
        KEY idx_claim_group_assignments_group (group_id),
        KEY idx_claim_group_assignments_claim (claim_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Demo-/Standardgruppen nur anlegen, wenn noch keine Gruppe existiert.
    $count = (int)$db->query('SELECT COUNT(*) FROM claim_groups')->fetchColumn();
    if ($count === 0) {
        $standortId = null;
        if (locations_enabled()) {
            $stmt = $db->query("SELECT id FROM standorte WHERE aktiv = 1 ORDER BY FIELD(kuerzel, 'WUN') DESC, id ASC LIMIT 1");
            $standortId = (int)($stmt->fetchColumn() ?: 0) ?: null;
        }

        $insert = $db->prepare('INSERT INTO claim_groups (standort_id, name, description, color, active, notify_on_assign, create_action_on_assign, default_due_days, created_by, updated_by, created_at, updated_at) VALUES (?, ?, ?, ?, 1, ?, ?, ?, ?, ?, NOW(), NOW())');
        $userId = (int)(current_user()['id'] ?? 0) ?: null;
        $insert->execute([$standortId, 'Qualität', 'Standardgruppe für Qualitäts-/8D-Bearbeitung.', 'primary', 1, 1, 2, $userId, $userId]);
        $insert->execute([$standortId, 'Logistik', 'Gruppe für logistische Reklamationen und Workbench-Fälle.', 'success', 0, 1, 2, $userId, $userId]);
        $insert->execute([null, 'Management', 'Standortübergreifende Freigabe-/Eskalationsgruppe.', 'danger', 1, 1, 1, $userId, $userId]);
    }

    flash('success', 'Gruppen-Migration inklusive Benachrichtigungen wurde erfolgreich ausgeführt.');
    redirect('groups.php');
} catch (Throwable $e) {
    if (defined('APP_DEBUG') && APP_DEBUG) {
        die('Migration fehlgeschlagen: ' . e($e->getMessage()));
    }
    flash('danger', 'Gruppen-Migration konnte nicht ausgeführt werden.');
    redirect('dashboard.php');
}
