<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/claim_group_helper.php';
require_once __DIR__ . '/qm_helper.php';
require_once __DIR__ . '/analytics_access_helper.php';
require_once __DIR__ . '/claim_responsible_action_helper.php';
require_login();

if (!can_edit()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

require_csrf();

$db = pdo();
$claimId = (int)($_POST['claim_id'] ?? 0);

if ($claimId <= 0) {
    redirect('claims.php');
}

require_claim_access($claimId);

$stmt = $db->prepare('SELECT * FROM claims WHERE id = ?');
$stmt->execute([$claimId]);
$old = $stmt->fetch();

if (!$old) {
    http_response_code(404);
    die('Reklamation nicht gefunden.');
}

function claim_meta_clean(?string $value): string
{
    return trim((string)$value);
}

function claim_meta_null_if_empty(?string $value): ?string
{
    $value = trim((string)$value);
    return $value === '' ? null : $value;
}

function claim_meta_column_exists(PDO $db, string $table, string $column): bool
{
    if (function_exists('db_column_exists')) {
        return db_column_exists($table, $column);
    }

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function claim_meta_user_label(PDO $db, ?int $userId): string
{
    if (!$userId) {
        return '-';
    }

    $stmt = $db->prepare('SELECT name FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $name = trim((string)($stmt->fetchColumn() ?: ''));

    return $name !== '' ? $name : ('Benutzer #' . $userId);
}



function claim_meta_priority_processing_label(string $priority): string
{
    return match ($priority) {
        'low' => '10 Tage (2 Arbeitswochen)',
        'medium' => '7 Tage',
        'high' => '5 Tage',
        'critical' => '2 Tage',
        default => '7 Tage',
    };
}

function claim_meta_user_row(PDO $db, int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }

    $stmt = $db->prepare('SELECT id, name, email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function claim_meta_base_url(): string
{
    if (function_exists('claim_group_base_url')) {
        return rtrim(claim_group_base_url(), '/');
    }

    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . ($path !== '' ? $path : '');
    }

    return '';
}

function claim_meta_send_plain_mail(string $to, string $subject, string $message): bool
{
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    if (function_exists('claim_group_send_mail')) {
        return claim_group_send_mail($to, $subject, $message);
    }

    $fromEmail = defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'noreply@example.com';
    $fromName = defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : (defined('APP_NAME') ? APP_NAME : '8D Tool');

    $headers = [];
    $headers[] = 'MIME-Version: 1.0';
    $headers[] = 'Content-Type: text/plain; charset=UTF-8';
    $headers[] = 'From: ' . $fromName . ' <' . $fromEmail . '>';
    $headers[] = 'Reply-To: ' . $fromEmail;

    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function claim_meta_notify_new_responsible(PDO $db, int $claimId, array $oldClaim, int $newUserId, int $changedByUserId): array
{
    $result = [
        'attempted' => false,
        'sent' => false,
        'email' => '',
        'name' => '',
        'message' => '',
    ];

    $user = claim_meta_user_row($db, $newUserId);

    if (!$user) {
        $result['message'] = 'Neuer Verantwortlicher wurde nicht gefunden.';
        return $result;
    }

    $email = trim((string)($user['email'] ?? ''));
    $name = trim((string)($user['name'] ?? ''));

    $result['name'] = $name !== '' ? $name : ('Benutzer #' . $newUserId);
    $result['email'] = $email;

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $result['message'] = 'Beim neuen Verantwortlichen ist keine gültige E-Mail hinterlegt.';
        return $result;
    }

    $result['attempted'] = true;

    $baseUrl = claim_meta_base_url();
    $link = $baseUrl !== '' ? ($baseUrl . '/claim_view.php?id=' . $claimId) : ('claim_view.php?id=' . $claimId);

    $changedBy = claim_meta_user_label($db, $changedByUserId);

    $subject = 'Neue Verantwortlichkeit: ' . (string)($oldClaim['claim_number'] ?? ('Reklamation #' . $claimId));

    $message = "Hallo " . $result['name'] . ",\n\n";
    $message .= "du wurdest als verantwortliche Person für eine 8D-Reklamation eingetragen.\n\n";
    $message .= "Reklamation: " . (string)($oldClaim['claim_number'] ?? ('#' . $claimId)) . "\n";
    $message .= "Titel: " . (string)($oldClaim['short_description'] ?? '-') . "\n";
    $message .= "Partner/Bereich: " . (string)($oldClaim['partner_name'] ?? '-') . "\n";
    $priority = (string)($oldClaim['priority'] ?? 'medium');
    $message .= "Priorität: " . priority_label($priority) . " · " . claim_meta_priority_processing_label($priority) . "\n";
    $message .= "Geändert durch: " . $changedBy . "\n\n";

    if (!empty($oldClaim['problem_description'])) {
        $message .= "Problembeschreibung:\n" . (string)$oldClaim['problem_description'] . "\n\n";
    }

    $message .= "Direkt öffnen:\n" . $link . "\n\n";
    $message .= "Viele Grüße\n";
    $message .= defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : (defined('APP_NAME') ? APP_NAME : '8D Tool');

    $sent = claim_meta_send_plain_mail($email, $subject, $message);

    $result['sent'] = $sent;
    $result['message'] = $sent
        ? 'E-Mail an neuen Verantwortlichen wurde gesendet.'
        : 'E-Mail an neuen Verantwortlichen konnte nicht gesendet werden.';

    return $result;
}

function claim_meta_group_names(PDO $db, array $ids): string
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

    if (!$ids) {
        return '-';
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("SELECT name FROM claim_groups WHERE id IN ({$placeholders}) ORDER BY name ASC");
    $stmt->execute($ids);

    $names = $stmt->fetchAll(PDO::FETCH_COLUMN);

    return $names ? implode(', ', $names) : '-';
}

$claimTypes = ['customer', 'supplier', 'internal'];
$priorities = ['low', 'medium', 'high', 'critical'];

$shortDescription = claim_meta_clean($_POST['short_description'] ?? '');
$partnerName = claim_meta_clean($_POST['partner_name'] ?? '');
$claimType = claim_meta_clean($_POST['claim_type'] ?? '');
$priority = claim_meta_clean($_POST['priority'] ?? '');
$claimDate = claim_meta_clean($_POST['claim_date'] ?? '');

if ($shortDescription === '' || $partnerName === '' || $claimDate === '') {
    flash('error', 'Titel, Partner/Bereich und Reklamationsdatum sind Pflichtfelder.');
    redirect('claim_view.php?id=' . $claimId);
}

if (!in_array($claimType, $claimTypes, true)) {
    flash('error', 'Ungültige Reklamationsart.');
    redirect('claim_view.php?id=' . $claimId);
}

if (!in_array($priority, $priorities, true)) {
    flash('error', 'Ungültige Priorität.');
    redirect('claim_view.php?id=' . $claimId);
}

$responsibleUserId = (int)($_POST['responsible_user_id'] ?? 0);
$responsibleUserId = $responsibleUserId > 0 ? $responsibleUserId : null;

$oldResponsibleUserId = (int)($old['responsible_user_id'] ?? 0);
$newResponsibleUserId = (int)($responsibleUserId ?? 0);
$responsibleChangedToNewPerson = $newResponsibleUserId > 0 && $newResponsibleUserId !== $oldResponsibleUserId;

$quantityRaw = claim_meta_clean($_POST['quantity_affected'] ?? '');
$quantity = $quantityRaw === '' ? null : str_replace(',', '.', $quantityRaw);

$data = [
    'short_description' => $shortDescription,
    'claim_type' => $claimType,
    'priority' => $priority,
    'partner_name' => $partnerName,
    'claim_date' => $claimDate,
    'responsible_user_id' => $responsibleUserId,
    'article_number' => claim_meta_null_if_empty($_POST['article_number'] ?? ''),
    'article_name' => claim_meta_null_if_empty($_POST['article_name'] ?? ''),
    'quantity_affected' => $quantity,
    'delivery_date' => claim_meta_null_if_empty($_POST['delivery_date'] ?? ''),
    'source_module' => claim_meta_null_if_empty($_POST['source_module'] ?? ''),
    'source_number' => claim_meta_null_if_empty($_POST['source_number'] ?? ''),
    'source_url' => claim_meta_null_if_empty($_POST['source_url'] ?? ''),
    'problem_description' => claim_meta_null_if_empty($_POST['problem_description'] ?? ''),
];

/*
 * QM-Felder dürfen nur Rollen ändern, die auch die Auswertung sehen.
 * Bei normalen Mitarbeitern bleiben vorhandene QM-Daten unverändert,
 * selbst wenn manipulierte POST-Werte gesendet werden.
 */
$claimMetaCanSeeAnalytics = analytics_can_view(current_user());

if ($claimMetaCanSeeAnalytics) {
    $data['error_category'] = qm_clean_value($_POST, 'error_category');
    $data['error_pattern'] = qm_clean_value($_POST, 'error_pattern');
    $data['process_area'] = qm_clean_value($_POST, 'process_area');
    $data['root_cause_category'] = qm_clean_value($_POST, 'root_cause_category');
}

$labels = [
    'short_description' => 'Titel',
    'claim_type' => 'Art',
    'priority' => 'Priorität',
    'partner_name' => 'Partner/Bereich',
    'claim_date' => 'Reklamationsdatum',
    'responsible_user_id' => 'Verantwortlich',
    'article_number' => 'Artikelnummer',
    'article_name' => 'Artikelbezeichnung',
    'quantity_affected' => 'Menge betroffen',
    'delivery_date' => 'Lieferdatum',
    'source_module' => 'Quelle',
    'source_number' => 'Quellnummer/Quellenangabe',
    'source_url' => 'Quell-Link',
    'problem_description' => 'Problembeschreibung',
    'error_category' => 'QM Fehlerkategorie',
    'error_pattern' => 'QM Fehlerbild',
    'process_area' => 'QM Prozessbereich',
    'root_cause_category' => 'QM Ursachenkategorie',
];

$changes = [];
$sets = [];
$params = [];

foreach ($data as $column => $newValue) {
    if (!claim_meta_column_exists($db, 'claims', $column)) {
        continue;
    }

    $oldValue = $old[$column] ?? null;

    $oldCompare = (string)($oldValue ?? '');
    $newCompare = (string)($newValue ?? '');

    if ($oldCompare !== $newCompare) {
        $sets[] = "{$column} = ?";
        $params[] = $newValue;

        if ($column === 'responsible_user_id') {
            $changes[] = $labels[$column] . ': ' . claim_meta_user_label($db, $oldValue ? (int)$oldValue : null) . ' → ' . claim_meta_user_label($db, $newValue ? (int)$newValue : null);
        } else {
            $changes[] = $labels[$column] . ' geändert';
        }
    }
}

if (claim_meta_column_exists($db, 'claims', 'updated_at') && $sets) {
    $sets[] = 'updated_at = NOW()';
}

$selectedGroupIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['group_ids'] ?? [])))));

try {
    $db->beginTransaction();

    if ($sets) {
        $params[] = $claimId;
        $sql = 'UPDATE claims SET ' . implode(', ', $sets) . ' WHERE id = ?';
        $update = $db->prepare($sql);
        $update->execute($params);
    }

    if (function_exists('claim_groups_enabled') && claim_groups_enabled()) {
        $oldGroupsStmt = $db->prepare('SELECT group_id FROM claim_group_assignments WHERE claim_id = ? ORDER BY group_id ASC');
        $oldGroupsStmt->execute([$claimId]);
        $oldGroupIds = array_map('intval', $oldGroupsStmt->fetchAll(PDO::FETCH_COLUMN));

        sort($oldGroupIds);
        sort($selectedGroupIds);

        if ($oldGroupIds !== $selectedGroupIds) {
            $delete = $db->prepare('DELETE FROM claim_group_assignments WHERE claim_id = ?');
            $delete->execute([$claimId]);

            if ($selectedGroupIds) {
                $insert = $db->prepare('INSERT INTO claim_group_assignments (claim_id, group_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())');
                foreach ($selectedGroupIds as $groupId) {
                    $insert->execute([$claimId, $groupId, (int)current_user()['id']]);
                }
            }

            $changes[] = 'Gruppen: ' . claim_meta_group_names($db, $oldGroupIds) . ' → ' . claim_meta_group_names($db, $selectedGroupIds);
        }
    }

    $responsibleActionResult = sync_claim_responsible_start_action(
        $db,
        $claimId,
        $responsibleUserId,
        $priority,
        (int)current_user()['id'],
        $responsibleChangedToNewPerson
    );

    if (($responsibleActionResult['status'] ?? '') === 'created') {
        $changes[] = 'Persönliche D1-Startmaßnahme für '
            . claim_meta_user_label($db, $newResponsibleUserId)
            . ' erstellt'
            . (!empty($responsibleActionResult['due_date'])
                ? ' · Frist: ' . $responsibleActionResult['due_date']
                : '');
    } elseif (($responsibleActionResult['status'] ?? '') === 'transferred') {
        $changes[] = 'Offene D1-Startmaßnahme auf '
            . claim_meta_user_label($db, $newResponsibleUserId)
            . ' übertragen'
            . (!empty($responsibleActionResult['due_date'])
                ? ' · neue Frist: ' . $responsibleActionResult['due_date']
                : '');
    }

    if ($changes) {
        $history = $db->prepare('INSERT INTO claim_history (claim_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, NOW())');
        $history->execute([
            $claimId,
            (int)current_user()['id'],
            'Stammdaten aktualisiert',
            implode("\n", $changes),
        ]);
    }

    $db->commit();

    $mailResult = null;

    if ($responsibleChangedToNewPerson && $changes) {
        $mailResult = claim_meta_notify_new_responsible($db, $claimId, array_merge($old, $data), $newResponsibleUserId, (int)current_user()['id']);

        $mailHistory = $db->prepare('INSERT INTO claim_history (claim_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, NOW())');
        $mailHistory->execute([
            $claimId,
            (int)current_user()['id'],
            $mailResult['sent'] ? 'Verantwortlichen-Mail gesendet' : 'Verantwortlichen-Mail nicht gesendet',
            trim(($mailResult['name'] ?: '-') . ' · ' . ($mailResult['email'] ?: '-') . "\n" . ($mailResult['message'] ?: '')),
        ]);
    }

    flash('success', $changes ? 'Stammdaten wurden aktualisiert.' : 'Keine Änderungen erkannt.');

    if ($mailResult !== null) {
        if ($mailResult['sent']) {
            flash('success', 'Der neue Verantwortliche wurde per E-Mail informiert.');
        } else {
            flash('warning', $mailResult['message'] ?: 'E-Mail an neuen Verantwortlichen konnte nicht gesendet werden.');
        }
    }
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    flash('error', defined('APP_DEBUG') && APP_DEBUG ? $e->getMessage() : 'Stammdaten konnten nicht gespeichert werden.');
}

redirect('claim_view.php?id=' . $claimId);
