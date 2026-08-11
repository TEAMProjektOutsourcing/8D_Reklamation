<?php
declare(strict_types=1);

/**
 * Repariert bestehende 8D-Reklamationen, denen D1-D8 fehlen.
 *
 * Speicherort:
 *   Ins 8D-Tool-Hauptverzeichnis hochladen:
 *   /repair_missing_8d_steps.php
 *
 * Danach im Browser als Admin aufrufen:
 *   https://portfolio.your-workbench.de/repair_missing_8d_steps.php
 *
 * Nach erfolgreicher Reparatur wieder löschen.
 */

require_once __DIR__ . '/auth.php';
require_login();

if (!is_admin()) {
    http_response_code(403);
    die('Nur Admins dürfen diese Reparatur ausführen.');
}

$db = pdo();

$stepDefs = [
    'D1' => ['Team bilden', 'Team, Verantwortlichkeiten und Beteiligte festlegen.'],
    'D2' => ['Problem beschreiben', 'Problem, Ort, Zeitpunkt, Umfang und betroffene Teile beschreiben.'],
    'D3' => ['Sofortmaßnahmen', 'Sofortmaßnahmen zur Eingrenzung und Absicherung festlegen.'],
    'D4' => ['Ursachenanalyse', 'Hauptursache mit geeigneter Methode ermitteln.'],
    'D5' => ['Korrekturmaßnahmen planen', 'Dauerhafte Maßnahmen gegen die Ursache planen.'],
    'D6' => ['Maßnahmen umsetzen', 'Umsetzung dokumentieren und Nachweise sammeln.'],
    'D7' => ['Wiederholfehler verhindern', 'Vorbeugende Maßnahmen und Standards ableiten.'],
    'D8' => ['Abschluss', 'Wirksamkeit prüfen und Reklamation abschließen.'],
];

$user = current_user();
$userId = (int)$user['id'];

$stmt = $db->query("
    SELECT c.id, c.claim_number, c.problem_description,
           COUNT(s.id) AS step_count
    FROM claims c
    LEFT JOIN claim_steps s ON s.claim_id = c.id
    GROUP BY c.id, c.claim_number, c.problem_description
    HAVING step_count < 8
    ORDER BY c.id DESC
");

$claims = $stmt->fetchAll();

$insert = $db->prepare("
    INSERT INTO claim_steps
        (claim_id, step_key, title, description, content, status, completed_by, completed_at, created_at, updated_at)
    VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ON DUPLICATE KEY UPDATE
        title = VALUES(title),
        description = VALUES(description),
        updated_at = NOW()
");

$history = $db->prepare("
    INSERT INTO claim_history (claim_id, user_id, action, details, created_at)
    VALUES (?, ?, ?, ?, NOW())
");

$fixedClaims = 0;
$fixedSteps = 0;

foreach ($claims as $claim) {
    $claimId = (int)$claim['id'];
    $claimNumber = (string)$claim['claim_number'];

    $existingStmt = $db->prepare("SELECT step_key FROM claim_steps WHERE claim_id = ?");
    $existingStmt->execute([$claimId]);
    $existing = array_flip($existingStmt->fetchAll(PDO::FETCH_COLUMN));

    $addedForClaim = 0;

    foreach ($stepDefs as $stepKey => [$title, $description]) {
        if (isset($existing[$stepKey])) {
            continue;
        }

        $content = '';
        $status = 'open';
        $completedBy = null;
        $completedAt = null;

        if ($stepKey === 'D1') {
            $content = 'Dieser D-Schritt wurde nachträglich automatisch ergänzt.';
            $status = 'done';
            $completedBy = $userId;
            $completedAt = date('Y-m-d H:i:s');
        }

        if ($stepKey === 'D2') {
            $content = (string)($claim['problem_description'] ?? '');
            $status = $content !== '' ? 'in_progress' : 'open';
        }

        $insert->execute([
            $claimId,
            $stepKey,
            $title,
            $description,
            $content,
            $status,
            $completedBy,
            $completedAt,
        ]);

        $addedForClaim++;
        $fixedSteps++;
    }

    if ($addedForClaim > 0) {
        $history->execute([
            $claimId,
            $userId,
            'D1-D8 Schritte repariert',
            "{$addedForClaim} fehlende D-Schritte wurden nachträglich ergänzt.",
        ]);

        $fixedClaims++;
    }
}

?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <title>8D-Schritte repariert</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<div class="container py-5">
    <div class="card shadow-sm">
        <div class="card-body">
            <h1 class="h4 mb-3">8D-Schritte repariert</h1>
            <p>Geprüfte fehlerhafte Reklamationen: <strong><?= count($claims) ?></strong></p>
            <p>Reparierte Reklamationen: <strong><?= $fixedClaims ?></strong></p>
            <p>Ergänzte D-Schritte: <strong><?= $fixedSteps ?></strong></p>
            <div class="alert alert-warning">
                Bitte diese Datei jetzt wieder vom Server löschen:
                <code>repair_missing_8d_steps.php</code>
            </div>
            <a href="claims.php" class="btn btn-primary">Zur Reklamationsliste</a>
        </div>
    </div>
</div>
</body>
</html>
