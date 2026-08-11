<?php
declare(strict_types=1);

/**
 * Ampel-Eskalation für offene Maßnahmen.
 *
 * Regel:
 * - Grün: Tag 0–5 -> normale Gruppen-/Verantwortlicheninfo bei Erstellung.
 * - Gelb: Tag 6–10 -> E-Mail an Gruppen mit escalate_yellow = 1.
 * - Rot: ab Tag 11 oder Frist überschritten -> E-Mail an Gruppen mit escalate_yellow = 1 und/oder escalate_red = 1.
 *
 * Die E-Mail wird pro Maßnahme, Stufe und Empfänger nur einmal gesendet.
 */

function action_escalation_table_ready(): bool
{
    return db_table_exists('claim_action_escalation_log');
}

function action_escalation_group_columns_ready(): bool
{
    return db_column_exists('claim_groups', 'escalate_yellow')
        && db_column_exists('claim_groups', 'escalate_red');
}

function action_escalation_ready(): bool
{
    return db_table_exists('claim_actions')
        && db_table_exists('claim_groups')
        && db_table_exists('claim_group_members')
        && action_escalation_group_columns_ready()
        && action_escalation_table_ready();
}

function action_escalation_age_days(array $action): int
{
    $createdAt = (string)($action['created_at'] ?? '');
    if ($createdAt === '') {
        return 0;
    }

    try {
        $created = new DateTimeImmutable(substr($createdAt, 0, 10));
        $today = new DateTimeImmutable(date('Y-m-d'));
        return max(0, (int)$created->diff($today)->format('%a'));
    } catch (Throwable $e) {
        return 0;
    }
}

function action_escalation_level(array $action): string
{
    $status = (string)($action['status'] ?? '');
    if (in_array($status, ['done', 'cancelled'], true)) {
        return 'none';
    }

    $today = date('Y-m-d');
    $dueDate = (string)($action['due_date'] ?? '');

    if ($dueDate !== '' && $dueDate < $today) {
        return 'red';
    }

    $days = action_escalation_age_days($action);

    if ($days >= 11) {
        return 'red';
    }

    if ($days >= 6) {
        return 'yellow';
    }

    return 'green';
}

function action_escalation_level_label(string $level): string
{
    return match ($level) {
        'yellow' => 'Gelb',
        'red' => 'Rot',
        'green' => 'Grün',
        default => 'Keine Eskalation',
    };
}

function action_escalation_base_url(): string
{
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . ($path !== '' ? $path : '');
    }

    if (defined('APP_URL')) {
        return rtrim((string)APP_URL, '/');
    }

    return '';
}

function action_escalation_send_mail(string $to, string $subject, string $message): bool
{
    $to = trim($to);
    if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
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

function action_escalation_recipients(string $level): array
{
    if (!action_escalation_group_columns_ready()) {
        return [];
    }

    if ($level === 'yellow') {
        $where = 'g.escalate_yellow = 1';
    } elseif ($level === 'red') {
        // Rot informiert Management UND Geschäftsleitung:
        // also alle Gruppen, die gelb oder rot als Eskalation markiert sind.
        $where = '(g.escalate_yellow = 1 OR g.escalate_red = 1)';
    } else {
        return [];
    }

    $stmt = pdo()->query("
        SELECT
            g.id AS group_id,
            g.name AS group_name,
            u.id AS user_id,
            u.name AS user_name,
            u.email AS user_email
        FROM claim_groups g
        JOIN claim_group_members gm ON gm.group_id = g.id
        JOIN users u ON u.id = gm.user_id
        WHERE g.active = 1
          AND {$where}
          AND u.active = 1
          AND u.email IS NOT NULL
          AND u.email <> ''
        ORDER BY g.name ASC, u.name ASC
    ");

    $recipients = [];

    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $userId = (int)$row['user_id'];
        $email = trim((string)$row['user_email']);

        if ($userId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        if (!isset($recipients[$userId])) {
            $recipients[$userId] = [
                'user_id' => $userId,
                'name' => (string)$row['user_name'],
                'email' => $email,
                'groups' => [],
            ];
        }

        $recipients[$userId]['groups'][] = [
            'id' => (int)$row['group_id'],
            'name' => (string)$row['group_name'],
        ];
    }

    return array_values($recipients);
}

function action_escalation_already_sent(int $actionId, string $level, int $recipientUserId): bool
{
    if (!action_escalation_table_ready()) {
        return false;
    }

    $stmt = pdo()->prepare("
        SELECT COUNT(*)
        FROM claim_action_escalation_log
        WHERE action_id = ?
          AND escalation_level = ?
          AND recipient_user_id = ?
    ");
    $stmt->execute([$actionId, $level, $recipientUserId]);

    return (int)$stmt->fetchColumn() > 0;
}

function action_escalation_log_sent(
    int $claimId,
    int $actionId,
    string $level,
    int $recipientUserId,
    string $recipientEmail,
    string $groupNames,
    bool $sent,
    ?string $error = null
): void {
    if (!action_escalation_table_ready()) {
        return;
    }

    $stmt = pdo()->prepare("
        INSERT INTO claim_action_escalation_log
            (claim_id, action_id, escalation_level, recipient_user_id, recipient_email, group_names, sent, error_message, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([
        $claimId,
        $actionId,
        $level,
        $recipientUserId,
        $recipientEmail,
        $groupNames,
        $sent ? 1 : 0,
        $error,
    ]);
}

function action_escalation_mail_subject(array $action, string $level): string
{
    $prefix = $level === 'red' ? 'ROT' : 'GELB';
    return $prefix . '-Eskalation 8D-Maßnahme: ' . (string)$action['claim_number'] . ' · ' . (string)$action['title'];
}

function action_escalation_mail_body(array $action, string $level, array $recipient): string
{
    $days = action_escalation_age_days($action);
    $levelLabel = action_escalation_level_label($level);
    $baseUrl = action_escalation_base_url();
    $claimId = (int)$action['claim_id'];
    $link = $baseUrl !== '' ? $baseUrl . '/claim_view.php?id=' . $claimId . '#actions' : 'claim_view.php?id=' . $claimId . '#actions';

    $groupNames = implode(', ', array_unique(array_map(static fn(array $g): string => (string)$g['name'], $recipient['groups'] ?? [])));

    $message = "Hallo " . ((string)($recipient['name'] ?? '') ?: 'zusammen') . ",\n\n";

    if ($level === 'yellow') {
        $message .= "eine offene 8D-Maßnahme ist in den GELBEN Bereich gerutscht.\n";
        $message .= "Das bedeutet: Es wurde sich seit mehreren Tagen nicht ausreichend darum gekümmert oder die Maßnahme ist noch offen.\n\n";
    } else {
        $message .= "eine offene 8D-Maßnahme ist in den ROTEN Bereich gerutscht.\n";
        $message .= "Das bedeutet: Die Maßnahme ist kritisch, älter als 10 Tage oder die Frist wurde überschritten.\n\n";
    }

    $message .= "Eskalationsgruppe(n): " . ($groupNames ?: '-') . "\n";
    $message .= "Ampel: " . $levelLabel . "\n";
    $message .= "Alter der Maßnahme: " . $days . " Tag(e)\n";
    $message .= "Frist: " . ((string)($action['due_date'] ?? '') ?: 'keine Frist') . "\n\n";

    $message .= "Reklamation: " . (string)$action['claim_number'] . "\n";
    $message .= "Partner/Bereich: " . (string)$action['partner_name'] . "\n";
    $message .= "Problem: " . (string)$action['short_description'] . "\n";
    $message .= "D-Schritt: " . (string)$action['step_key'] . "\n";
    $message .= "Maßnahme: " . (string)$action['title'] . "\n";
    $message .= "Verantwortlich: " . ((string)($action['responsible_name'] ?? '') ?: '-') . "\n";
    $message .= "Status: " . (function_exists('status_label') ? status_label((string)$action['status']) : (string)$action['status']) . "\n\n";

    if (!empty($action['description'])) {
        $message .= "Beschreibung:\n" . (string)$action['description'] . "\n\n";
    }

    $message .= "Direkt öffnen:\n" . $link . "\n\n";
    $message .= "Viele Grüße\n" . (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '8D Tool') . "\n";

    return $message;
}

function run_action_escalation_check(bool $dryRun = false): array
{
    $result = [
        'ok' => false,
        'dry_run' => $dryRun,
        'checked_actions' => 0,
        'yellow_actions' => 0,
        'red_actions' => 0,
        'emails_sent' => 0,
        'emails_failed' => 0,
        'skipped_already_sent' => 0,
        'skipped_no_recipients' => 0,
        'details' => [],
    ];

    if (!action_escalation_ready()) {
        $result['details'][] = 'Datenbankstruktur für Ampel-Eskalation ist noch nicht vollständig. Bitte Migration ausführen.';
        return $result;
    }

    $stmt = pdo()->query("
        SELECT
            a.*,
            c.claim_number,
            c.partner_name,
            c.short_description,
            c.priority AS claim_priority,
            c.status AS claim_status,
            u.name AS responsible_name,
            u.email AS responsible_email
        FROM claim_actions a
        JOIN claims c ON c.id = a.claim_id
        LEFT JOIN users u ON u.id = a.responsible_user_id
        WHERE a.status IN ('open', 'in_progress')
        ORDER BY a.created_at ASC, a.id ASC
    ");
    $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($actions as $action) {
        $result['checked_actions']++;
        $level = action_escalation_level($action);

        if (!in_array($level, ['yellow', 'red'], true)) {
            continue;
        }

        if ($level === 'yellow') {
            $result['yellow_actions']++;
        } else {
            $result['red_actions']++;
        }

        $recipients = action_escalation_recipients($level);

        if (!$recipients) {
            $result['skipped_no_recipients']++;
            $result['details'][] = 'Keine Empfänger für ' . strtoupper($level) . ' gefunden. Maßnahme #' . (int)$action['id'];
            continue;
        }

        foreach ($recipients as $recipient) {
            $actionId = (int)$action['id'];
            $claimId = (int)$action['claim_id'];
            $recipientUserId = (int)$recipient['user_id'];
            $recipientEmail = (string)$recipient['email'];
            $groupNames = implode(', ', array_unique(array_map(static fn(array $g): string => (string)$g['name'], $recipient['groups'] ?? [])));

            if (action_escalation_already_sent($actionId, $level, $recipientUserId)) {
                $result['skipped_already_sent']++;
                continue;
            }

            $subject = action_escalation_mail_subject($action, $level);
            $message = action_escalation_mail_body($action, $level, $recipient);

            if ($dryRun) {
                $result['details'][] = '[TEST] ' . strtoupper($level) . ' Maßnahme #' . $actionId . ' an ' . $recipientEmail . ' (' . $groupNames . ')';
                continue;
            }

            $sent = action_escalation_send_mail($recipientEmail, $subject, $message);

            action_escalation_log_sent(
                $claimId,
                $actionId,
                $level,
                $recipientUserId,
                $recipientEmail,
                $groupNames,
                $sent,
                $sent ? null : 'mail() gab false zurück'
            );

            if ($sent) {
                $result['emails_sent']++;
            } else {
                $result['emails_failed']++;
            }

            $result['details'][] = strtoupper($level) . ' Maßnahme #' . $actionId . ' an ' . $recipientEmail . ': ' . ($sent ? 'gesendet' : 'fehlgeschlagen');
        }

        if (!$dryRun) {
            log_history_for_user(
                (int)$action['claim_id'],
                null,
                'Ampel-Eskalation geprüft',
                'Maßnahme #' . (int)$action['id'] . ' wurde als ' . strtoupper($level) . ' eingestuft.'
            );
        }
    }

    $result['ok'] = true;
    return $result;
}
