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
require_claim_access($claimId);
$actionId = (int)($_POST['action_id'] ?? 0);

if ($claimId <= 0 || $actionId <= 0) {
    flash('danger', 'Maßnahme konnte nicht gelöscht werden.');
    redirect('claims.php');
}

$stmt = $db->prepare('SELECT title FROM claim_actions WHERE id = ? AND claim_id = ?');
$stmt->execute([$actionId, $claimId]);
$action = $stmt->fetch();

if (!$action) {
    flash('danger', 'Maßnahme wurde nicht gefunden.');
    redirect('claim_view.php?id=' . $claimId . '#actions');
}

$stmt = $db->prepare('DELETE FROM claim_actions WHERE id = ? AND claim_id = ?');
$stmt->execute([$actionId, $claimId]);

log_history($claimId, 'Maßnahme gelöscht', 'Maßnahme #' . $actionId . ': ' . $action['title']);
flash('success', 'Maßnahme wurde gelöscht.');
redirect('claim_view.php?id=' . $claimId . '#actions');
