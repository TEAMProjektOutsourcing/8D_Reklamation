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
$returnTo = safe_action_return_to((string)($_POST['return_to'] ?? ''));

if ($claimId <= 0 || $actionId <= 0) {
    flash('danger', 'Erinnerung konnte nicht gesendet werden.');
    redirect($returnTo !== '' ? $returnTo : 'claims.php');
}

$stmt = $db->prepare("SELECT
        a.*,
        c.claim_number,
        c.short_description,
        c.partner_name,
        u.name AS responsible_name,
        u.email AS responsible_email
    FROM claim_actions a
    JOIN claims c ON c.id = a.claim_id
    LEFT JOIN users u ON u.id = a.responsible_user_id
    WHERE a.id = ? AND a.claim_id = ?");
$stmt->execute([$actionId, $claimId]);
$action = $stmt->fetch();

if (!$action) {
    flash('danger', 'Maßnahme wurde nicht gefunden.');
    redirect($returnTo !== '' ? $returnTo : 'claim_view.php?id=' . $claimId . '#actions');
}

if (action_is_closed($action)) {
    flash('warning', 'Für erledigte oder abgebrochene Maßnahmen wird keine Erinnerung gesendet.');
    redirect($returnTo !== '' ? $returnTo : 'claim_view.php?id=' . $claimId . '#actions');
}

$email = trim((string)($action['responsible_email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('warning', 'Der Verantwortliche hat keine gültige E-Mail-Adresse.');
    redirect($returnTo !== '' ? $returnTo : 'claim_view.php?id=' . $claimId . '#actions');
}

$subject = '8D-Erinnerung: ' . $action['claim_number'] . ' · ' . $action['title'];
$baseUrl = '';
if (!empty($_SERVER['HTTP_HOST'])) {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
    $baseUrl = $scheme . '://' . $_SERVER['HTTP_HOST'] . ($path !== '' ? $path : '');
}
$link = $baseUrl !== '' ? $baseUrl . '/claim_view.php?id=' . $claimId . '#actions' : 'claim_view.php?id=' . $claimId . '#actions';

$message = "Hallo " . ($action['responsible_name'] ?: 'zusammen') . ",\n\n";
$message .= "dies ist eine Erinnerung zu einer offenen 8D-Maßnahme.\n\n";
$message .= "Reklamation: " . $action['claim_number'] . "\n";
$message .= "Partner: " . $action['partner_name'] . "\n";
$message .= "Problem: " . $action['short_description'] . "\n";
$message .= "D-Schritt: " . $action['step_key'] . "\n";
$message .= "Maßnahme: " . $action['title'] . "\n";
$message .= "Status: " . status_label((string)$action['status']) . "\n";
$message .= "Frist: " . ($action['due_date'] ?: 'keine Frist') . "\n";
$message .= "Ampel: " . action_traffic_text($action) . "\n\n";
if (!empty($action['description'])) {
    $message .= "Beschreibung:\n" . $action['description'] . "\n\n";
}
$message .= "Direkt öffnen:\n" . $link . "\n\n";
$message .= "Viele Grüße\n" . MAIL_FROM_NAME . "\n";

$fromEmail = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'noreply@example.com';
$fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : APP_NAME;
$headers = [];
$headers[] = 'MIME-Version: 1.0';
$headers[] = 'Content-Type: text/plain; charset=UTF-8';
$headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
$headers[] = 'Reply-To: ' . $fromEmail;

$sent = @mail($email, $subject, $message, implode("\r\n", $headers));

if ($sent) {
    log_history($claimId, 'E-Mail-Erinnerung gesendet', 'Maßnahme #' . $actionId . ' an ' . $email);
    flash('success', 'E-Mail-Erinnerung wurde gesendet.');
} else {
    log_history($claimId, 'E-Mail-Erinnerung fehlgeschlagen', 'Maßnahme #' . $actionId . ' an ' . $email);
    flash('warning', 'E-Mail konnte vom Server nicht gesendet werden. Prüfe die Mailfunktion/Absenderadresse im Hosting.');
}

redirect($returnTo !== '' ? $returnTo : 'claim_view.php?id=' . $claimId . '#actions');
