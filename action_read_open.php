<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/action_read_helper.php';

require_login();

$db = pdo();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

$actionId = (int)($_GET['action_id'] ?? 0);
$claimId = (int)($_GET['claim_id'] ?? 0);

if ($actionId <= 0) {
    $_SESSION['flash_error'] = 'Maßnahmen-ID fehlt.';
    header('Location: my_actions.php');
    exit;
}

$stmt = $db->prepare("SELECT a.id, a.claim_id, a.responsible_user_id
    FROM claim_actions a
    WHERE a.id = ?
    LIMIT 1");
$stmt->execute([$actionId]);
$action = $stmt->fetch();

if (!$action) {
    $_SESSION['flash_error'] = 'Maßnahme wurde nicht gefunden.';
    header('Location: my_actions.php');
    exit;
}

$claimId = $claimId > 0 ? $claimId : (int)$action['claim_id'];

if (function_exists('require_claim_access')) {
    require_claim_access($claimId);
}

/**
 * Gelesen markieren:
 * - wenn der aktuelle User der Verantwortliche ist
 * - oder wenn Admin/Bearbeiter die Maßnahme öffnet
 */
if ((int)$action['responsible_user_id'] === $userId || can_edit()) {
    mark_action_read($db, $actionId, $userId);
}

header('Location: claim_view.php?id=' . $claimId . '#actions');
exit;
