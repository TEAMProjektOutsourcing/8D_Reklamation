<?php
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!can_edit()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$db = pdo();
$claimId = (int)($_POST['claim_id'] ?? 0);
$claimAccess = require_claim_access($claimId);
$actionId = (int)($_POST['action_id'] ?? 0);
$stepKey = (string)($_POST['step_key'] ?? '');
$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? ''));
$responsible = ($_POST['responsible_user_id'] ?? '') !== '' ? (int)$_POST['responsible_user_id'] : null;
$dueDate = trim((string)($_POST['due_date'] ?? ''));
$status = (string)($_POST['status'] ?? 'open');

if ($claimId <= 0 || $actionId <= 0 || $title === '' || !array_key_exists($stepKey, claim_step_definitions())) {
    flash('danger', 'Maßnahme konnte nicht gespeichert werden.');
    redirect('claim_view.php?id=' . $claimId . '#actions');
}

if (!in_array($status, ['open','in_progress','done','cancelled'], true)) {
    $status = 'open';
}

if ($responsible !== null && locations_enabled()) {
    $availableUserIds = array_map(static fn(array $row): int => (int)$row['id'], get_users_for_select(isset($claimAccess['standort_id']) ? (int)$claimAccess['standort_id'] : null));
    if (!in_array($responsible, $availableUserIds, true)) {
        $responsible = null;
    }
}

if ($dueDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
    flash('danger', 'Bitte ein gültiges Fristdatum eintragen.');
    redirect('claim_view.php?id=' . $claimId . '#actions');
}

$stmt = $db->prepare("SELECT a.*, u.name AS responsible_name FROM claim_actions a LEFT JOIN users u ON u.id = a.responsible_user_id WHERE a.id = ? AND a.claim_id = ?");
$stmt->execute([$actionId, $claimId]);
$old = $stmt->fetch();

if (!$old) {
    flash('danger', 'Maßnahme wurde nicht gefunden.');
    redirect('claim_view.php?id=' . $claimId . '#actions');
}

$stmt = $db->prepare("UPDATE claim_actions
    SET step_key = ?, title = ?, description = ?, responsible_user_id = ?, due_date = ?, status = ?,
        completed_at = CASE WHEN ? = 'done' THEN COALESCE(completed_at, NOW()) ELSE NULL END
    WHERE id = ? AND claim_id = ?");
$stmt->execute([
    $stepKey,
    $title,
    $description !== '' ? $description : null,
    $responsible,
    $dueDate !== '' ? $dueDate : null,
    $status,
    $status,
    $actionId,
    $claimId,
]);

$newResponsibleName = '-';
if ($responsible) {
    $userStmt = $db->prepare('SELECT name FROM users WHERE id = ?');
    $userStmt->execute([$responsible]);
    $newResponsibleName = (string)($userStmt->fetchColumn() ?: '-');
}

$details = build_change_details([
    'D-Schritt' => [(string)$old['step_key'], $stepKey],
    'Titel' => [(string)$old['title'], $title],
    'Verantwortlich' => [(string)($old['responsible_name'] ?? ''), $newResponsibleName],
    'Frist' => [(string)($old['due_date'] ?? ''), $dueDate],
    'Status' => [status_label((string)$old['status']), status_label($status)],
]);

$oldDescription = trim((string)($old['description'] ?? ''));
if ($oldDescription !== $description) {
    $details .= ($details !== '' ? "\n" : '') . 'Beschreibung wurde aktualisiert.';
}

if ($details !== '') {
    $event = $status === 'done' && $old['status'] !== 'done' ? 'Maßnahme erledigt' : 'Maßnahme bearbeitet';
    log_history($claimId, $event, 'Maßnahme #' . $actionId . "\n" . $details);
}

flash('success', 'Maßnahme wurde gespeichert.');
redirect('claim_view.php?id=' . $claimId . '#actions');
