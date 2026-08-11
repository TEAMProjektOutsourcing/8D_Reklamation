<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/claim_group_helper.php';
require_once __DIR__ . '/qm_helper.php';

require_login();

$db = pdo();

$claimId = (int)($_POST['claim_id'] ?? 0);
$analysisId = (int)($_POST['analysis_id'] ?? 0);
$feedback = trim((string)($_POST['feedback'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));

if ($claimId <= 0) {
    $_SESSION['flash_error'] = 'Reklamations-ID fehlt.';
    header('Location: dashboard.php');
    exit;
}

if (function_exists('require_claim_access')) {
    require_claim_access($claimId);
}

$user = current_user();
$userId = (int)($user['id'] ?? 0);

try {
    qm_save_ai_feedback($db, $claimId, $analysisId > 0 ? $analysisId : null, $userId, $feedback, $note);
    $_SESSION['flash_success'] = 'QM-Feedback wurde gespeichert. Damit wird die KI-Light Bewertung für spätere Lernlogik nutzbar.';
} catch (Throwable $e) {
    error_log('QM Feedback speichern fehlgeschlagen: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'QM-Feedback konnte nicht gespeichert werden.';
}

header('Location: claim_view.php?id=' . $claimId);
exit;
