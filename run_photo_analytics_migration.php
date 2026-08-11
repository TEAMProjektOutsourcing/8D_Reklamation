<?php
require_once __DIR__ . '/auth.php';
require_admin();

header('Content-Type: text/html; charset=utf-8');

$messages = [];
$errors = [];

function migration_column_exists_local(PDO $db, string $table, string $column): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function migration_index_exists_local(PDO $db, string $table, string $index): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?');
    $stmt->execute([$table, $index]);
    return (int)$stmt->fetchColumn() > 0;
}

try {
    $db = pdo();

    if (!db_table_exists('claim_files')) {
        throw new RuntimeException('Tabelle claim_files wurde nicht gefunden. Bitte zuerst database.sql bzw. die Grundinstallation prüfen.');
    }

    if (!migration_column_exists_local($db, 'claim_files', 'step_key')) {
        $db->exec("ALTER TABLE claim_files ADD step_key ENUM('D1','D2','D3','D4','D5','D6','D7','D8') NULL AFTER claim_id");
        $messages[] = 'claim_files.step_key ergänzt.';
    } else {
        $messages[] = 'claim_files.step_key bereits vorhanden.';
    }

    if (!migration_column_exists_local($db, 'claim_files', 'category')) {
        $db->exec("ALTER TABLE claim_files ADD category ENUM('problem','containment','cause','corrective','proof','other') NOT NULL DEFAULT 'other' AFTER step_key");
        $messages[] = 'claim_files.category ergänzt.';
    } else {
        $messages[] = 'claim_files.category bereits vorhanden.';
    }

    if (!migration_column_exists_local($db, 'claim_files', 'caption')) {
        $db->exec('ALTER TABLE claim_files ADD caption TEXT NULL AFTER category');
        $messages[] = 'claim_files.caption ergänzt.';
    } else {
        $messages[] = 'claim_files.caption bereits vorhanden.';
    }

    if (!migration_index_exists_local($db, 'claim_files', 'idx_files_step')) {
        try {
            $db->exec('ALTER TABLE claim_files ADD INDEX idx_files_step (step_key)');
            $messages[] = 'Index idx_files_step ergänzt.';
        } catch (Throwable $e) {
            $messages[] = 'Index idx_files_step konnte nicht ergänzt werden. Das Tool läuft trotzdem. Hinweis: ' . $e->getMessage();
        }
    } else {
        $messages[] = 'Index idx_files_step bereits vorhanden.';
    }

    $messages[] = 'Fotodokumentation und Auswertung wurden vorbereitet.';
} catch (Throwable $e) {
    $errors[] = 'Migration fehlgeschlagen: ' . $e->getMessage();
}
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Fotodokumentation-Migration</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm">
        <div class="card-header fw-bold">Fotodokumentation & Auswertung vorbereiten</div>
        <div class="card-body">
            <?php foreach ($messages as $msg): ?>
                <div class="alert alert-success mb-2"><?= e($msg) ?></div>
            <?php endforeach; ?>
            <?php foreach ($errors as $err): ?>
                <div class="alert alert-danger mb-2"><?= e($err) ?></div>
            <?php endforeach; ?>
            <p class="text-muted mb-3">Wenn keine rote Fehlermeldung erscheint, kannst du diese Datei danach vom Server löschen.</p>
            <a class="btn btn-primary" href="dashboard.php">Zum Dashboard</a>
            <a class="btn btn-outline-secondary" href="auswertungen.php">Zur Auswertung</a>
        </div>
    </div>
</div>
</body>
</html>
