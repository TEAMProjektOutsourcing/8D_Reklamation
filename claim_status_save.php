<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/claim_close_notify.php';

require_login();
require_csrf();

if (!can_edit()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$claimId = (int)($_POST['claim_id'] ?? 0);

if ($claimId <= 0) {
    flash('danger', 'Ungültige Reklamation.');
    redirect('claims.php');
}

require_claim_access($claimId);

$status = (string)($_POST['status'] ?? 'new');
$closeSource = trim((string)($_POST['close_source'] ?? ''));
$allowed = ['new','in_progress','waiting','overdue','closed','rejected','archived'];

if (!in_array($status, $allowed, true)) {
    flash('danger', 'Ungültiger Status.');
    redirect('claim_view.php?id=' . $claimId);
}

$db = pdo();

$stmt = $db->prepare("SELECT
        c.*,
        responsible.name AS responsible_name,
        responsible.email AS responsible_email,
        creator.name AS creator_name,
        creator.email AS creator_email
    FROM claims c
    LEFT JOIN users responsible ON responsible.id = c.responsible_user_id
    LEFT JOIN users creator ON creator.id = c.created_by
    WHERE c.id = ?");
$stmt->execute([$claimId]);
$claim = $stmt->fetch();

if (!$claim) {
    flash('danger', 'Reklamation wurde nicht gefunden.');
    redirect('claims.php');
}

$user = current_user();
$userId = (int)($user['id'] ?? 0);
$oldStatus = (string)($claim['status'] ?? '');

if ($status === 'closed' && $oldStatus !== 'closed') {
    if ($closeSource !== 'd8') {
        flash(
            'danger',
            'Die Reklamation kann nur nach dem Speichern von D8 über den Abschlussdialog abgeschlossen werden.'
        );
        redirect('claim_view.php?id=' . $claimId . '#stepD8');
    }

    $unfinishedStepsStmt = $db->prepare("SELECT step_key, title, status
        FROM claim_steps
        WHERE claim_id = ?
          AND status <> 'done'
        ORDER BY step_key ASC");
    $unfinishedStepsStmt->execute([$claimId]);
    $unfinishedSteps = $unfinishedStepsStmt->fetchAll();

    if ($unfinishedSteps) {
        $stepLabels = array_map(
            static fn(array $row): string =>
                (string)($row['step_key'] ?? '-')
                . ' · '
                . (string)($row['title'] ?? ''),
            array_slice($unfinishedSteps, 0, 4)
        );

        flash(
            'danger',
            'Abschluss nicht möglich: Noch nicht alle D-Schritte sind erledigt. Offen: '
            . implode(', ', $stepLabels)
            . (count($unfinishedSteps) > 4 ? ' …' : '')
        );
        redirect('claim_view.php?id=' . $claimId . '#stepD8');
    }

    $openActionsStmt = $db->prepare("SELECT
            a.title,
            a.status,
            COALESCE(NULLIF(u.name, ''), 'Nicht zugewiesen') AS responsible_name
        FROM claim_actions a
        LEFT JOIN users u ON u.id = a.responsible_user_id
        WHERE a.claim_id = ?
          AND a.status IN ('open', 'in_progress')
        ORDER BY
            CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END,
            a.due_date ASC,
            a.created_at ASC");
    $openActionsStmt->execute([$claimId]);
    $openActions = $openActionsStmt->fetchAll();

    if ($openActions) {
        $actionLabels = array_map(
            static function (array $row): string {
                $title = trim((string)($row['title'] ?? 'Maßnahme')) ?: 'Maßnahme';
                $name = trim((string)($row['responsible_name'] ?? 'Nicht zugewiesen')) ?: 'Nicht zugewiesen';

                return $title . ' – ' . $name;
            },
            array_slice($openActions, 0, 4)
        );

        flash(
            'danger',
            'Abschluss nicht möglich: Folgende vergebene Maßnahme wurde noch nicht erledigt: '
            . implode(', ', $actionLabels)
            . (count($openActions) > 4 ? ' …' : '')
        );
        redirect('claim_view.php?id=' . $claimId . '#actions');
    }
}

$setParts = ['status = ?'];
$params = [$status];

if ($status === 'closed') {
    if ($oldStatus !== 'closed') {
        if (function_exists('db_column_exists') && db_column_exists('claims', 'closed_by')) {
            $setParts[] = 'closed_by = ?';
            $params[] = $userId;
        }

        if (function_exists('db_column_exists') && db_column_exists('claims', 'closed_at')) {
            $setParts[] = 'closed_at = NOW()';
        }
    }
} else {
    if (function_exists('db_column_exists') && db_column_exists('claims', 'closed_by')) {
        $setParts[] = 'closed_by = NULL';
    }

    if (function_exists('db_column_exists') && db_column_exists('claims', 'closed_at')) {
        $setParts[] = 'closed_at = NULL';
    }
}

$params[] = $claimId;

$updateStmt = $db->prepare(
    'UPDATE claims SET ' . implode(', ', $setParts) . ' WHERE id = ?'
);
$updateStmt->execute($params);

$mailInfo = ['attempted' => 0, 'sent' => 0, 'failed' => 0];

if ($oldStatus !== $status) {
    $oldLabel = function_exists('status_label')
        ? status_label($oldStatus)
        : $oldStatus;
    $newLabel = function_exists('status_label')
        ? status_label($status)
        : $status;

    if (function_exists('build_change_details')) {
        $details = build_change_details([
            'Fallstatus' => [$oldLabel, $newLabel],
        ]);
    } else {
        $details = 'Fallstatus: ' . $oldLabel . ' → ' . $newLabel;
    }

    if (function_exists('log_history')) {
        log_history(
            $claimId,
            $status === 'closed' ? 'Fall abgeschlossen' : 'Fallstatus geändert',
            $details
        );
    }

    if ($status === 'closed') {
        $mailInfo = claim_close_send_notifications($db, $claim, $user);

        if (function_exists('log_history')) {
            $mailDetails = 'Benachrichtigung beim Abschluss: '
                . (int)$mailInfo['sent'] . ' gesendet'
                . ((int)$mailInfo['failed'] > 0
                    ? ', ' . (int)$mailInfo['failed'] . ' fehlgeschlagen'
                    : '')
                . '.';

            log_history(
                $claimId,
                'Abschluss-Benachrichtigung versendet',
                $mailDetails
            );
        }
    }
}

if ($status === 'closed') {
    if ($oldStatus === 'closed') {
        flash('success', 'Die Reklamation ist bereits abgeschlossen.');
    } elseif ((int)$mailInfo['attempted'] > 0) {
        flash(
            'success',
            'Reklamation wurde abgeschlossen und als erledigt markiert. '
            . (int)$mailInfo['sent'] . ' E-Mail(s) wurden versendet.'
        );
    } else {
        flash(
            'warning',
            'Reklamation wurde abgeschlossen. Es wurden keine gültigen E-Mail-Empfänger gefunden.'
        );
    }
} else {
    flash('success', 'Fallstatus wurde gespeichert.');
}

redirect(
    'claim_view.php?id=' . $claimId
    . ($status === 'closed' ? '#stepD8' : '')
);
