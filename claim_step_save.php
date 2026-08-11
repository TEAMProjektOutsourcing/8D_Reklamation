<?php
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!can_edit()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$claimId = (int)($_POST['claim_id'] ?? 0);
require_claim_access($claimId);
$stepKey = (string)($_POST['step_key'] ?? '');
$content = trim((string)($_POST['content'] ?? ''));
$status = (string)($_POST['status'] ?? 'open');
$user = current_user();

if ($claimId <= 0 || !array_key_exists($stepKey, claim_step_definitions())) {
    die('Ungültige Daten.');
}

if (!in_array($status, ['open','in_progress','done'], true)) {
    $status = 'open';
}

$stmt = pdo()->prepare('SELECT * FROM claim_steps WHERE claim_id = ? AND step_key = ?');
$stmt->execute([$claimId, $stepKey]);
$oldStep = $stmt->fetch();

if (!$oldStep) {
    die('8D-Schritt wurde nicht gefunden.');
}

if ($status === 'done') {
    $stmt = pdo()->prepare('UPDATE claim_steps SET content = ?, status = ?, completed_by = ?, completed_at = IF(completed_at IS NULL, NOW(), completed_at) WHERE claim_id = ? AND step_key = ?');
    $stmt->execute([$content, $status, (int)$user['id'], $claimId, $stepKey]);
} else {
    $stmt = pdo()->prepare('UPDATE claim_steps SET content = ?, status = ?, completed_by = NULL, completed_at = NULL WHERE claim_id = ? AND step_key = ?');
    $stmt->execute([$content, $status, $claimId, $stepKey]);
}

pdo()->prepare("UPDATE claims SET status = CASE WHEN status = 'new' THEN 'in_progress' ELSE status END WHERE id = ?")->execute([$claimId]);

$oldStatus = (string)$oldStep['status'];
$oldContent = trim((string)($oldStep['content'] ?? ''));

if ($oldStatus !== $status) {
    $details = build_change_details([
        'Status' => [status_label($oldStatus), status_label($status)],
    ]);
    if ($content !== '' && $content !== $oldContent) {
        $details .= ($details !== '' ? "\n" : '') . 'Dokumentation wurde aktualisiert.';
    }
    log_history($claimId, step_audit_title($stepKey, $status), $details ?: null);
} elseif ($content !== $oldContent) {
    log_history($claimId, $stepKey . ' ' . (claim_step_definitions()[$stepKey]['title'] ?? '') . ' bearbeitet', 'Dokumentation wurde aktualisiert.');
}

flash('success', $stepKey . ' wurde gespeichert.');
redirect('claim_view.php?id=' . $claimId . '#step' . $stepKey);
