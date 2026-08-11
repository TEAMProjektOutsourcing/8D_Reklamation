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
$actionId = (int)($_POST['action_id'] ?? 0);
$status = (string)($_POST['status'] ?? 'open');

if (!in_array($status, ['open','in_progress','done','cancelled'], true)) {
    $status = 'open';
}

$stmt = pdo()->prepare('SELECT title, status FROM claim_actions WHERE id = ? AND claim_id = ?');
$stmt->execute([$actionId, $claimId]);
$old = $stmt->fetch();

if (!$old) {
    flash('danger', 'Maßnahme wurde nicht gefunden.');
    redirect('claim_view.php?id=' . $claimId . '#actions');
}

$stmt = pdo()->prepare("UPDATE claim_actions SET status = ?, completed_at = CASE WHEN ? = 'done' THEN NOW() ELSE NULL END WHERE id = ? AND claim_id = ?");
$stmt->execute([$status, $status, $actionId, $claimId]);

if ((string)$old['status'] !== $status) {
    $event = $status === 'done' ? 'Maßnahme erledigt' : 'Maßnahme aktualisiert';
    $details = 'Maßnahme #' . $actionId . ': ' . $old['title'] . "\n" . build_change_details([
        'Status' => [status_label((string)$old['status']), status_label($status)],
    ]);
    log_history($claimId, $event, $details);
}

flash('success', 'Maßnahme wurde aktualisiert.');

$returnTo = safe_action_return_to((string)($_POST['return_to'] ?? ''));
if ($returnTo !== '') {
    redirect($returnTo);
}

redirect('claim_view.php?id=' . $claimId . '#actions');
