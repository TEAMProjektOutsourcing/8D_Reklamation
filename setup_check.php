<?php
declare(strict_types=1);
require_once __DIR__ . '/db.php';

header('Content-Type: text/html; charset=utf-8');

echo '<!doctype html><html lang="de"><head><meta charset="utf-8"><title>8D Setup Check</title>';
echo '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">';
echo '</head><body class="bg-light"><main class="container py-5">';
echo '<div class="card shadow-sm"><div class="card-body">';
echo '<h1 class="h4 mb-3">8D Setup Check</h1>';

try {
    $pdo = pdo();
    echo '<div class="alert alert-success">Datenbankverbindung erfolgreich.</div>';

    $tables = ['users','standorte','user_standorte','customers','claims','claim_steps','claim_actions','claim_files','claim_history'];
    echo '<h2 class="h6">Tabellenprüfung</h2><ul class="list-group mb-3">';
    foreach ($tables as $table) {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
        $stmt->execute([$table]);
        $exists = ((int)$stmt->fetchColumn() > 0);
        echo '<li class="list-group-item d-flex justify-content-between align-items-center">' . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') .
            ($exists ? '<span class="badge text-bg-success">OK</span>' : '<span class="badge text-bg-danger">fehlt</span>') . '</li>';
    }
    echo '</ul>';

    if (is_writable(APP_UPLOAD_DIR)) {
        echo '<div class="alert alert-success">Upload-Ordner ist beschreibbar.</div>';
    } else {
        echo '<div class="alert alert-warning">Upload-Ordner ist nicht beschreibbar: <code>' . htmlspecialchars(APP_UPLOAD_DIR, ENT_QUOTES, 'UTF-8') . '</code></div>';
    }

    echo '<a class="btn btn-primary" href="login.php">Zum Login</a> ';
    echo '<a class="btn btn-outline-secondary" href="database.sql">database.sql ansehen</a>';
} catch (Throwable $e) {
    echo '<div class="alert alert-danger"><strong>Fehler:</strong><br>' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . '</div>';
    echo '<p class="mb-0">Wenn hier <strong>Unknown database</strong> steht, ist vermutlich <code>DB_NAME</code> in <code>config.php</code> nicht der echte Datenbankname. Die Beschreibung im Hosting-Panel ist oft nur ein Anzeigename.</p>';
}

echo '</div></div></main></body></html>';
