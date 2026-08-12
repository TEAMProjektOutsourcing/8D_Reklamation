<?php
require_once __DIR__ . '/security_bootstrap.php';

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/claim_group_helper.php';
require_once __DIR__ . '/qm_helper.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    die('Methode nicht erlaubt.');
}

require_csrf();

$db = pdo();

$claimId = (int)($_POST['claim_id'] ?? 0);
$analysisId = (int)($_POST['analysis_id'] ?? 0);
$feedback = trim((string)($_POST['feedback'] ?? ''));
$note = trim((string)($_POST['note'] ?? ''));
$returnTo = trim((string)($_POST['return_to'] ?? ''));

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

// Standard: Bei Feedback direkt aus einer Reklamation dort bleiben.
$redirectUrl = 'claim_view.php?id=' . $claimId;

// Feedback aus der Auswertung kehrt gezielt zur offenen KI-Light-Liste zurück.
// Bewusst nur diesen internen Rücksprung erlauben, damit kein Open Redirect möglich ist.
if ($returnTo === 'auswertungen.php#ki-light-bewertungen') {
    $redirectUrl = 'auswertungen.php#ki-light-bewertungen';
}

header('Location: ' . $redirectUrl);
exit;
