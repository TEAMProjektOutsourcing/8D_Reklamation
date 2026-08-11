<?php
declare(strict_types=1);

/**
 * Repariert bestehende Workbench->8D Übertragungen:
 *
 * 1. D2-Inhalt: HTML-Tags werden in normalen lesbaren Text umgewandelt.
 * 2. Bilder: falscher alter Pfad
 *      uploads/claims/claim_<id>/<datei>
 *    oder
 *      claim_<id>/<datei>
 *    wird auf das 8D-Standardformat gebracht:
 *      <id>/<datei>
 *
 * Speicherort:
 *   Ins 8D-Hauptverzeichnis hochladen:
 *   /repair_workbench_transfer_text_files.php
 *
 * Aufruf:
 *   https://portfolio.your-workbench.de/repair_workbench_transfer_text_files.php
 *
 * Danach wieder löschen.
 */

require_once __DIR__ . '/auth.php';
require_login();

if (!is_admin()) {
    http_response_code(403);
    die('Nur Admins dürfen diese Reparatur ausführen.');
}

$db = pdo();

function clean_transfer_text(string $value): string {
    $value = str_replace(["\r\n", "\r"], "\n", $value);
    $value = preg_replace('/<\/p>\s*<p[^>]*>/i', "\n\n", $value);
    $value = preg_replace('/<br\s*\/?>/i', "\n", $value);
    $value = preg_replace('/<\/li>\s*<li[^>]*>/i', "\n", $value);
    $value = preg_replace('/<li[^>]*>/i', '', $value);
    $value = preg_replace('/<\/li>/i', "\n", $value);
    $value = preg_replace('/<\/?(ul|ol)[^>]*>/i', "\n", $value);
    $value = preg_replace('/<\/p>/i', "\n\n", $value);
    $value = preg_replace('/<p[^>]*>/i', '', $value);
    $value = preg_replace('/<\/?strong[^>]*>/i', '', $value);
    $value = strip_tags((string)$value);
    $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $value = preg_replace("/[ \t]+\n/", "\n", $value);
    $value = preg_replace("/\n{3,}/", "\n\n", $value);
    return trim($value);
}

$user = current_user();
$userId = (int)$user['id'];

$updatedTexts = 0;
$movedFiles = 0;
$fixedFilePaths = 0;
$missingFiles = 0;

/*
 * 1. D2-Texte reparieren.
 */
$stmt = $db->query("
    SELECT s.id, s.claim_id, s.content
    FROM claim_steps s
    INNER JOIN claims c ON c.id = s.claim_id
    WHERE s.step_key = 'D2'
      AND c.source_module = 'schadensmeldung'
      AND s.content LIKE '%<%'
");

$rows = $stmt->fetchAll();

$upd = $db->prepare("UPDATE claim_steps SET content = ?, updated_at = NOW() WHERE id = ?");
$hist = $db->prepare("INSERT INTO claim_history (claim_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, NOW())");

foreach ($rows as $row) {
    $newText = clean_transfer_text((string)$row['content']);
    $upd->execute([$newText, (int)$row['id']]);
    $hist->execute([(int)$row['claim_id'], $userId, 'D2-Text repariert', 'HTML-Tags aus Workbench-Übertragung wurden in normalen Text umgewandelt.']);
    $updatedTexts++;
}

/*
 * 2. Datei-Pfade reparieren.
 *
 * upload_file.php speichert physisch:
 *   APP_UPLOAD_DIR / claim_id / stored_name
 *
 * und in DB:
 *   file_path = claim_id/stored_name
 */
$fileStmt = $db->query("
    SELECT f.id, f.claim_id, f.file_path, f.stored_name
    FROM claim_files f
    INNER JOIN claims c ON c.id = f.claim_id
    WHERE c.source_module = 'schadensmeldung'
");

$files = $fileStmt->fetchAll();
$fileUpd = $db->prepare("UPDATE claim_files SET file_path = ? WHERE id = ?");

foreach ($files as $file) {
    $fileId = (int)$file['id'];
    $claimId = (int)$file['claim_id'];
    $oldPath = trim((string)$file['file_path']);
    $storedName = basename((string)($file['stored_name'] ?: $oldPath));

    if ($storedName === '') {
        continue;
    }

    $correctRel = $claimId . '/' . $storedName;
    $correctAbsDir = APP_UPLOAD_DIR . '/' . $claimId;
    $correctAbs = $correctAbsDir . '/' . $storedName;

    $possibleOldAbsPaths = [
        APP_UPLOAD_DIR . '/' . $oldPath,
        APP_UPLOAD_DIR . '/claim_' . $claimId . '/' . $storedName,
        APP_UPLOAD_DIR . '/uploads/claims/claim_' . $claimId . '/' . $storedName,
        dirname(APP_UPLOAD_DIR) . '/claims/claim_' . $claimId . '/' . $storedName,
    ];

    if (!is_dir($correctAbsDir)) {
        @mkdir($correctAbsDir, 0775, true);
    }

    if (!is_file($correctAbs)) {
        foreach ($possibleOldAbsPaths as $oldAbs) {
            if (is_file($oldAbs)) {
                if (@rename($oldAbs, $correctAbs)) {
                    $movedFiles++;
                } elseif (@copy($oldAbs, $correctAbs)) {
                    $movedFiles++;
                }
                break;
            }
        }
    }

    if ($oldPath !== $correctRel) {
        $fileUpd->execute([$correctRel, $fileId]);
        $fixedFilePaths++;
    }

    if (!is_file($correctAbs)) {
        $missingFiles++;
    }
}

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>Workbench-Transfer repariert</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3">Workbench-Transfer repariert</h1>

            <div class="alert alert-success">
                Reparatur wurde ausgeführt.
            </div>

            <table class="table table-sm">
                <tr>
                    <th>D2-Texte bereinigt</th>
                    <td><?= (int)$updatedTexts ?></td>
                </tr>
                <tr>
                    <th>Dateien verschoben/kopiert</th>
                    <td><?= (int)$movedFiles ?></td>
                </tr>
                <tr>
                    <th>DB-Dateipfade korrigiert</th>
                    <td><?= (int)$fixedFilePaths ?></td>
                </tr>
                <tr>
                    <th>Dateien weiterhin nicht gefunden</th>
                    <td><?= (int)$missingFiles ?></td>
                </tr>
            </table>

            <?php if ($missingFiles > 0): ?>
                <div class="alert alert-warning">
                    Einige Dateien wurden in der Datenbank gefunden, aber nicht als echte Datei auf dem Server.
                    Dann war der alte Upload-Pfad in <code>transfer_to_8d.php</code> vermutlich falsch.
                    Neue Übertragungen funktionieren mit der neuen Transfer-Datei korrekt.
                </div>
            <?php endif; ?>

            <div class="alert alert-danger">
                Bitte diese Datei jetzt wieder löschen:
                <code>repair_workbench_transfer_text_files.php</code>
            </div>

            <a href="claims.php" class="btn btn-primary">Zur Reklamationsliste</a>
        </div>
    </div>
</div>
</body>
</html>
