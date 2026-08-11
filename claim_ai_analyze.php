<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/claim_group_helper.php';
require_once __DIR__ . '/qm_helper.php';

require_login();

$db = pdo();

$claimId = (int)($_POST['claim_id'] ?? $_GET['claim_id'] ?? 0);

if ($claimId <= 0) {
    $_SESSION['flash_error'] = 'Reklamations-ID fehlt.';
    header('Location: dashboard.php');
    exit;
}

if (function_exists('require_claim_access')) {
    require_claim_access($claimId);
}

try {
    $result = qm_run_local_ai_analysis($db, $claimId, 180);

    if (!empty($result['ok'])) {
        $_SESSION['flash_success'] = 'KI-Light Analyse wurde erstellt. Ähnliche Fälle: ' . (int)($result['similar_count'] ?? 0) . '.';
    } else {
        $_SESSION['flash_error'] = (string)($result['message'] ?? 'KI-Light Analyse konnte nicht erstellt werden.');
    }
} catch (Throwable $e) {
    error_log('KI-Light Analyse Fehler: ' . $e->getMessage());
    $_SESSION['flash_error'] = 'KI-Light Analyse konnte nicht erstellt werden. Bitte Server-Log prüfen.';
}

header('Location: claim_view.php?id=' . $claimId);
exit;
