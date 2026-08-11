<?php
declare(strict_types=1);

/**
 * Fix: fehlende Benachrichtigungs-Spalten für claim_groups ergänzen
 *
 * Speicherort:
 *   /run_claim_groups_notify_columns_fix.php
 *
 * Aufruf als Admin:
 *   https://portfolio.your-workbench.de/run_claim_groups_notify_columns_fix.php
 *
 * Danach diese Datei wieder vom Server löschen.
 */

require_once __DIR__ . '/auth.php';
require_admin();

$db = pdo();

function fix_column_exists(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function fix_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

$messages = [];
$errors = [];

try {
    if (!fix_table_exists($db, 'claim_groups')) {
        $db->exec("
            CREATE TABLE claim_groups (
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
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $messages[] = 'Tabelle claim_groups wurde neu angelegt.';
    } else {
        $messages[] = 'Tabelle claim_groups ist vorhanden.';
    }

    if (!fix_column_exists($db, 'claim_groups', 'notify_on_assign')) {
        $db->exec("ALTER TABLE claim_groups ADD COLUMN notify_on_assign TINYINT(1) NOT NULL DEFAULT 0 AFTER active");
        $messages[] = 'Spalte notify_on_assign wurde ergänzt.';
    } else {
        $messages[] = 'Spalte notify_on_assign war bereits vorhanden.';
    }

    if (!fix_column_exists($db, 'claim_groups', 'create_action_on_assign')) {
        $after = fix_column_exists($db, 'claim_groups', 'notify_on_assign') ? 'AFTER notify_on_assign' : 'AFTER active';
        $db->exec("ALTER TABLE claim_groups ADD COLUMN create_action_on_assign TINYINT(1) NOT NULL DEFAULT 1 {$after}");
        $messages[] = 'Spalte create_action_on_assign wurde ergänzt.';
    } else {
        $messages[] = 'Spalte create_action_on_assign war bereits vorhanden.';
    }

    if (!fix_column_exists($db, 'claim_groups', 'default_due_days')) {
        $after = fix_column_exists($db, 'claim_groups', 'create_action_on_assign') ? 'AFTER create_action_on_assign' : 'AFTER active';
        $db->exec("ALTER TABLE claim_groups ADD COLUMN default_due_days INT UNSIGNED NOT NULL DEFAULT 2 {$after}");
        $messages[] = 'Spalte default_due_days wurde ergänzt.';
    } else {
        $messages[] = 'Spalte default_due_days war bereits vorhanden.';
    }

    if (!fix_table_exists($db, 'claim_group_members')) {
        $db->exec("
            CREATE TABLE claim_group_members (
              group_id INT UNSIGNED NOT NULL,
              user_id INT UNSIGNED NOT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (group_id, user_id),
              KEY idx_claim_group_members_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $messages[] = 'Tabelle claim_group_members wurde angelegt.';
    } else {
        $messages[] = 'Tabelle claim_group_members ist vorhanden.';
    }

    if (!fix_table_exists($db, 'claim_group_assignments')) {
        $db->exec("
            CREATE TABLE claim_group_assignments (
              claim_id INT UNSIGNED NOT NULL,
              group_id INT UNSIGNED NOT NULL,
              assigned_by INT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (claim_id, group_id),
              KEY idx_claim_group_assignments_group (group_id),
              KEY idx_claim_group_assignments_claim (claim_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
        $messages[] = 'Tabelle claim_group_assignments wurde angelegt.';
    } else {
        $messages[] = 'Tabelle claim_group_assignments ist vorhanden.';
    }

} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

function h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Gruppen-Spalten-Fix</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <?php if (file_exists(__DIR__ . '/header.php')): ?>
        <?php
        // Header wird hier bewusst NICHT eingebunden, weil manche header.php direkt Layout öffnet.
        ?>
    <?php endif; ?>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm border-0">
        <div class="card-body">
            <h1 class="h4 mb-3">Gruppen-Benachrichtigungs-Spalten Fix</h1>

            <?php if ($errors): ?>
                <div class="alert alert-danger">
                    <strong>Fehler:</strong>
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?= h($error) ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="alert alert-success">
                    Fix wurde erfolgreich ausgeführt.
                </div>
            <?php endif; ?>

            <ul class="list-group mb-4">
                <?php foreach ($messages as $message): ?>
                    <li class="list-group-item"><?= h($message) ?></li>
                <?php endforeach; ?>
            </ul>

            <div class="alert alert-warning">
                Bitte diese Datei nach erfolgreicher Ausführung wieder vom Server löschen:
                <code>run_claim_groups_notify_columns_fix.php</code>
            </div>

            <a href="groups.php" class="btn btn-primary">Zur Gruppenverwaltung</a>
        </div>
    </div>
</div>
</body>
</html>
