<?php
declare(strict_types=1);

/**
 * Migration für Ampel-Eskalation.
 *
 * Aufruf:
 *   /run_action_escalation_migration.php
 *
 * Danach löschen.
 */

require_once __DIR__ . '/auth.php';
require_admin();

$db = pdo();

function esc_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function esc_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

$messages = [];

try {
    if (!esc_table_exists($db, 'claim_groups')) {
        throw new RuntimeException('Tabelle claim_groups fehlt. Bitte zuerst die Gruppen-Migration ausführen.');
    }

    if (!esc_column_exists($db, 'claim_groups', 'escalate_yellow')) {
        $db->exec("ALTER TABLE claim_groups ADD COLUMN escalate_yellow TINYINT(1) NOT NULL DEFAULT 0 AFTER default_due_days");
        $messages[] = 'Spalte claim_groups.escalate_yellow ergänzt.';
    } else {
        $messages[] = 'Spalte claim_groups.escalate_yellow ist vorhanden.';
    }

    if (!esc_column_exists($db, 'claim_groups', 'escalate_red')) {
        $db->exec("ALTER TABLE claim_groups ADD COLUMN escalate_red TINYINT(1) NOT NULL DEFAULT 0 AFTER escalate_yellow");
        $messages[] = 'Spalte claim_groups.escalate_red ergänzt.';
    } else {
        $messages[] = 'Spalte claim_groups.escalate_red ist vorhanden.';
    }

    $db->exec("
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    $messages[] = 'Tabelle claim_action_escalation_log ist vorhanden.';

    flash('success', 'Ampel-Eskalation wurde vorbereitet.');
} catch (Throwable $e) {
    flash('danger', defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Migration konnte nicht ausgeführt werden.');
}

require __DIR__ . '/header.php';
?>
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <h1 class="h4 mb-3">Ampel-Eskalation Migration</h1>

        <?php if ($messages): ?>
            <ul class="list-group mb-4">
                <?php foreach ($messages as $msg): ?>
                    <li class="list-group-item"><?= e($msg) ?></li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <div class="alert alert-warning">
            Bitte diese Datei nach erfolgreicher Ausführung wieder löschen:
            <code>run_action_escalation_migration.php</code>
        </div>

        <a href="action_escalation_run.php" class="btn btn-primary">Zur Ampel-Eskalation</a>
        <a href="groups.php" class="btn btn-outline-secondary">Gruppen verwalten</a>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
