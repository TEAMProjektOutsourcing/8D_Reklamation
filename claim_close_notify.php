<?php
declare(strict_types=1);

/**
 * E-Mail-Benachrichtigung beim Abschluss einer Reklamation.
 *
 * Empfänger:
 * - verantwortlicher User der Reklamation
 * - Ersteller der Reklamation
 * - User, der den Fall abschließt
 * - Mitglieder der zugeordneten Reklamationsgruppen
 * - Verantwortliche und Ersteller zusätzlicher Maßnahmen
 *
 * Es wird sauber dedupliziert, damit niemand doppelt eine Mail bekommt.
 */

function claim_close_public_url(int $claimId): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'portfolio.your-workbench.de';
    $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

    if ($scriptDir === '' || $scriptDir === '.') {
        $scriptDir = '';
    }

    return $scheme . '://' . $host . $scriptDir . '/claim_view.php?id=' . $claimId;
}

function claim_close_mail_headers(): string
{
    $host = $_SERVER['HTTP_HOST'] ?? 'your-workbench.de';
    $host = preg_replace('/[^a-zA-Z0-9.\-]/', '', (string)$host) ?: 'your-workbench.de';

    return implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'From: 8D Reklamationstool <no-reply@' . $host . '>',
        'Reply-To: no-reply@' . $host,
        'X-Mailer: PHP/' . PHP_VERSION,
    ]);
}

function claim_close_add_recipient(array &$recipients, ?int $userId, ?string $name, ?string $email, string $source): void
{
    $email = trim((string)$email);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $key = strtolower($email);

    if (!isset($recipients[$key])) {
        $recipients[$key] = [
            'user_id' => $userId,
            'name' => trim((string)$name) ?: $email,
            'email' => $email,
            'sources' => [],
        ];
    }

    $recipients[$key]['sources'][$source] = true;
}

function claim_close_group_names(PDO $db, int $claimId): array
{
    if (!function_exists('db_table_exists') || !db_table_exists('claim_group_assignments') || !db_table_exists('claim_groups')) {
        return [];
    }

    try {
        $stmt = $db->prepare("SELECT g.name
            FROM claim_group_assignments cga
            JOIN claim_groups g ON g.id = cga.group_id
            WHERE cga.claim_id = ?
            ORDER BY g.name ASC");
        $stmt->execute([$claimId]);

        return array_values(array_filter(array_map(static fn($row) => (string)($row['name'] ?? ''), $stmt->fetchAll())));
    } catch (Throwable $e) {
        error_log('8D close notify group names error: ' . $e->getMessage());
        return [];
    }
}

function claim_close_group_member_recipients(PDO $db, int $claimId, array &$recipients): void
{
    if (!function_exists('db_table_exists') || !db_table_exists('claim_group_assignments') || !db_table_exists('claim_group_members')) {
        return;
    }

    try {
        $activeSql = function_exists('db_column_exists') && db_column_exists('users', 'active') ? ' AND u.active = 1' : '';

        $stmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email
            FROM claim_group_assignments cga
            JOIN claim_group_members cgm ON cgm.group_id = cga.group_id
            JOIN users u ON u.id = cgm.user_id
            WHERE cga.claim_id = ?
              AND u.email IS NOT NULL
              AND u.email <> ''
              $activeSql
            ORDER BY u.name ASC");
        $stmt->execute([$claimId]);

        foreach ($stmt->fetchAll() as $row) {
            claim_close_add_recipient(
                $recipients,
                isset($row['id']) ? (int)$row['id'] : null,
                $row['name'] ?? null,
                $row['email'] ?? null,
                'Gruppe'
            );
        }
    } catch (Throwable $e) {
        error_log('8D close notify group members error: ' . $e->getMessage());
    }
}

function claim_close_action_recipients(PDO $db, int $claimId, array &$recipients): void
{
    if (!function_exists('db_table_exists') || !db_table_exists('claim_actions')) {
        return;
    }

    try {
        $activeSql = function_exists('db_column_exists') && db_column_exists('users', 'active')
            ? ' AND u.active = 1'
            : '';

        $stmt = $db->prepare("SELECT DISTINCT u.id, u.name, u.email
            FROM users u
            JOIN (
                SELECT responsible_user_id AS user_id
                FROM claim_actions
                WHERE claim_id = ?
                  AND responsible_user_id IS NOT NULL

                UNION

                SELECT created_by AS user_id
                FROM claim_actions
                WHERE claim_id = ?
                  AND created_by IS NOT NULL
            ) involved ON involved.user_id = u.id
            WHERE u.email IS NOT NULL
              AND u.email <> ''
              $activeSql
            ORDER BY u.name ASC");
        $stmt->execute([$claimId, $claimId]);

        foreach ($stmt->fetchAll() as $row) {
            claim_close_add_recipient(
                $recipients,
                isset($row['id']) ? (int)$row['id'] : null,
                $row['name'] ?? null,
                $row['email'] ?? null,
                'Maßnahmenbeteiligter'
            );
        }
    } catch (Throwable $e) {
        error_log('8D close notify action recipients error: ' . $e->getMessage());
    }
}

function claim_close_collect_recipients(PDO $db, array $claim, array $actor): array
{
    $recipients = [];

    claim_close_add_recipient(
        $recipients,
        isset($claim['responsible_user_id']) ? (int)$claim['responsible_user_id'] : null,
        $claim['responsible_name'] ?? null,
        $claim['responsible_email'] ?? null,
        'Fallverantwortlicher'
    );

    claim_close_add_recipient(
        $recipients,
        isset($claim['created_by']) ? (int)$claim['created_by'] : null,
        $claim['creator_name'] ?? null,
        $claim['creator_email'] ?? null,
        'Ersteller'
    );

    claim_close_add_recipient(
        $recipients,
        isset($actor['id']) ? (int)$actor['id'] : null,
        $actor['name'] ?? null,
        $actor['email'] ?? null,
        'Abschließender User'
    );

    claim_close_group_member_recipients($db, (int)$claim['id'], $recipients);
    claim_close_action_recipients($db, (int)$claim['id'], $recipients);

    return array_values($recipients);
}

function claim_close_send_notifications(PDO $db, array $claim, array $actor): array
{
    $claimId = (int)($claim['id'] ?? 0);

    if ($claimId <= 0) {
        return ['attempted' => 0, 'sent' => 0, 'failed' => 0];
    }

    $recipients = claim_close_collect_recipients($db, $claim, $actor);
    $url = claim_close_public_url($claimId);
    $groups = claim_close_group_names($db, $claimId);
    $groupLine = $groups ? implode(', ', $groups) : '-';

    $subject = '[8D] Reklamation erledigt und abgeschlossen: ' . (string)($claim['claim_number'] ?? ('#' . $claimId));

    $body = "Hallo,\n\n"
        . "die Bearbeitung der folgenden 8D-Reklamation wurde vollständig erledigt und abgeschlossen.\n"
        . "Es bestehen keine offenen zusätzlichen Maßnahmen mehr.\n\n"
        . "Reklamation: " . (string)($claim['claim_number'] ?? '-') . "\n"
        . "Problem: " . (string)($claim['short_description'] ?? '-') . "\n"
        . "Partner: " . (string)($claim['partner_name'] ?? '-') . "\n"
        . "Artikelnummer: " . (string)($claim['article_number'] ?? '-') . "\n"
        . "Priorität: " . (function_exists('priority_label') ? priority_label((string)($claim['priority'] ?? '')) : (string)($claim['priority'] ?? '-')) . "\n"
        . "Gruppen: " . $groupLine . "\n"
        . "Status: Erledigt / Abgeschlossen\n"
        . "Abgeschlossen von: " . (string)($actor['name'] ?? '-') . "\n"
        . "Abgeschlossen am: " . date('d.m.Y H:i') . "\n\n"
        . "Direkt öffnen:\n" . $url . "\n\n"
        . "Hinweis: Dies ist eine automatische Benachrichtigung aus dem 8D Reklamationstool.\n";

    $headers = claim_close_mail_headers();

    $sent = 0;
    $failed = 0;

    foreach ($recipients as $recipient) {
        $ok = @mail((string)$recipient['email'], $subject, $body, $headers);
        if ($ok) {
            $sent++;
        } else {
            $failed++;
            error_log('8D close notify mail failed: ' . (string)$recipient['email']);
        }
    }

    return [
        'attempted' => count($recipients),
        'sent' => $sent,
        'failed' => $failed,
    ];
}
