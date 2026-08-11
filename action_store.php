<?php
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!can_edit()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$claimId = (int)($_POST['claim_id'] ?? 0);
$claimAccess = require_claim_access($claimId);
$stepKey = (string)($_POST['step_key'] ?? '');
$title = trim((string)($_POST['title'] ?? ''));
$description = trim((string)($_POST['description'] ?? '')) ?: null;
$responsible = ($_POST['responsible_user_id'] ?? '') !== '' ? (int)$_POST['responsible_user_id'] : null;
$dueDate = ($_POST['due_date'] ?? '') !== '' ? (string)$_POST['due_date'] : null;
$user = current_user();

if ($responsible !== null && locations_enabled()) {
    $availableUserIds = array_map(static fn(array $row): int => (int)$row['id'], get_users_for_select(isset($claimAccess['standort_id']) ? (int)$claimAccess['standort_id'] : null));
    if (!in_array($responsible, $availableUserIds, true)) {
        $responsible = null;
    }
}

if ($claimId <= 0 || $title === '' || !array_key_exists($stepKey, claim_step_definitions())) {
    flash('danger', 'Maßnahme konnte nicht angelegt werden.');
    redirect('claim_view.php?id=' . $claimId);
}

$stmt = pdo()->prepare('INSERT INTO claim_actions (claim_id, step_key, title, description, responsible_user_id, due_date, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
$stmt->execute([$claimId, $stepKey, $title, $description, $responsible, $dueDate, (int)$user['id']]);
$actionId = (int)pdo()->lastInsertId();

$responsibleName = '-';
if ($responsible) {
    $userStmt = pdo()->prepare('SELECT name FROM users WHERE id = ?');
    $userStmt->execute([$responsible]);
    $responsibleName = (string)($userStmt->fetchColumn() ?: '-');
}

$details = "D-Schritt: {$stepKey}\nMaßnahme: {$title}\nVerantwortlich: {$responsibleName}\nFrist: " . ($dueDate ?: 'keine Frist');
log_history($claimId, 'Maßnahme erstellt', $details);
flash('success', 'Maßnahme wurde hinzugefügt.');
redirect('claim_view.php?id=' . $claimId . '#actions');
