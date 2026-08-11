<?php
declare(strict_types=1);

/**
 * Helper für Reklamationsgruppen.
 *
 * Tabellen:
 * - claim_groups
 * - claim_group_members
 * - claim_group_assignments
 */

function claim_groups_enabled(): bool
{
    return db_table_exists('claim_groups') && db_table_exists('claim_group_assignments');
}

function claim_group_members_enabled(): bool
{
    return db_table_exists('claim_group_members');
}

function claim_group_colors(): array
{
    return [
        'primary' => 'Blau',
        'success' => 'Grün',
        'warning' => 'Gelb',
        'danger' => 'Rot',
        'info' => 'Türkis',
        'secondary' => 'Grau',
        'dark' => 'Dunkel',
    ];
}

function claim_group_color_class(?string $color): string
{
    $color = (string)($color ?: 'secondary');
    return array_key_exists($color, claim_group_colors()) ? $color : 'secondary';
}

function claim_group_badge(array $group): string
{
    $color = claim_group_color_class($group['color'] ?? 'secondary');
    $textClass = $color === 'warning' || $color === 'info' ? ' text-dark' : '';
    return '<span class="badge bg-' . e($color) . $textClass . '">' . e((string)$group['name']) . '</span>';
}

function active_claim_groups_for_select(?int $locationId = null): array
{
    if (!claim_groups_enabled()) {
        return [];
    }

    $sql = 'SELECT g.* FROM claim_groups g WHERE g.active = 1';
    $params = [];

    if (locations_enabled()) {
        if ($locationId !== null && $locationId > 0) {
            $sql .= ' AND (g.standort_id IS NULL OR g.standort_id = ?)';
            $params[] = $locationId;
        } else {
            $selected = selected_location_id();
            if ($selected !== null) {
                $sql .= ' AND (g.standort_id IS NULL OR g.standort_id = ?)';
                $params[] = $selected;
            }
        }
    }

    $sql .= ' ORDER BY g.standort_id IS NOT NULL ASC, g.name ASC';

    $stmt = pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

function claim_group_ids_for_claim(int $claimId): array
{
    if (!claim_groups_enabled() || $claimId <= 0) {
        return [];
    }

    $stmt = pdo()->prepare('SELECT group_id FROM claim_group_assignments WHERE claim_id = ? ORDER BY group_id ASC');
    $stmt->execute([$claimId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function claim_groups_for_claim(int $claimId): array
{
    if (!claim_groups_enabled() || $claimId <= 0) {
        return [];
    }

    $stmt = pdo()->prepare('SELECT g.*
        FROM claim_group_assignments cga
        JOIN claim_groups g ON g.id = cga.group_id
        WHERE cga.claim_id = ?
        ORDER BY g.name ASC');
    $stmt->execute([$claimId]);
    return $stmt->fetchAll();
}

function claim_group_names_for_claim(int $claimId): string
{
    $groups = claim_groups_for_claim($claimId);
    if (!$groups) {
        return '';
    }

    return implode(', ', array_map(static fn(array $g): string => (string)$g['name'], $groups));
}

function sanitize_group_ids(array $rawIds, ?int $locationId = null): array
{
    if (!claim_groups_enabled()) {
        return [];
    }

    $ids = [];
    foreach ($rawIds as $id) {
        if (is_numeric($id) && (int)$id > 0) {
            $ids[] = (int)$id;
        }
    }
    $ids = array_values(array_unique($ids));

    if (!$ids) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $sql = "SELECT id FROM claim_groups WHERE active = 1 AND id IN ($placeholders)";
    $params = $ids;

    if (locations_enabled() && $locationId !== null && $locationId > 0) {
        $sql .= ' AND (standort_id IS NULL OR standort_id = ?)';
        $params[] = $locationId;
    }

    $stmt = pdo()->prepare($sql);
    $stmt->execute($params);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function save_claim_group_assignments(int $claimId, array $groupIds, ?int $assignedBy = null): void
{
    if (!claim_groups_enabled() || $claimId <= 0) {
        return;
    }

    $db = pdo();
    $del = $db->prepare('DELETE FROM claim_group_assignments WHERE claim_id = ?');
    $del->execute([$claimId]);

    if (!$groupIds) {
        return;
    }

    $ins = $db->prepare('INSERT INTO claim_group_assignments (claim_id, group_id, assigned_by, created_at) VALUES (?, ?, ?, NOW())');
    foreach (array_values(array_unique(array_map('intval', $groupIds))) as $groupId) {
        if ($groupId > 0) {
            $ins->execute([$claimId, $groupId, $assignedBy]);
        }
    }
}

function claim_group_user_ids(int $groupId): array
{
    if (!claim_group_members_enabled() || $groupId <= 0) {
        return [];
    }

    $stmt = pdo()->prepare('SELECT user_id FROM claim_group_members WHERE group_id = ? ORDER BY user_id ASC');
    $stmt->execute([$groupId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function save_claim_group_members(int $groupId, array $userIds): void
{
    if (!claim_group_members_enabled() || $groupId <= 0) {
        return;
    }

    $ids = [];
    foreach ($userIds as $id) {
        if (is_numeric($id) && (int)$id > 0) {
            $ids[] = (int)$id;
        }
    }
    $ids = array_values(array_unique($ids));

    $db = pdo();
    $del = $db->prepare('DELETE FROM claim_group_members WHERE group_id = ?');
    $del->execute([$groupId]);

    if (!$ids) {
        return;
    }

    $ins = $db->prepare('INSERT INTO claim_group_members (group_id, user_id, created_at) VALUES (?, ?, NOW())');
    foreach ($ids as $userId) {
        $ins->execute([$groupId, $userId]);
    }
}

function claim_group_member_names(int $groupId): string
{
    if (!claim_group_members_enabled() || $groupId <= 0) {
        return '';
    }

    $stmt = pdo()->prepare('SELECT u.name
        FROM claim_group_members gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id = ?
        ORDER BY u.name ASC');
    $stmt->execute([$groupId]);
    return implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN));
}


function claim_group_column_exists(string $column): bool
{
    $stmt = pdo()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'claim_groups' AND COLUMN_NAME = ?");
    $stmt->execute([$column]);
    return (int)$stmt->fetchColumn() > 0;
}

function claim_group_automation_enabled(): bool
{
    return claim_groups_enabled()
        && claim_group_column_exists('notify_on_assign')
        && claim_group_column_exists('create_action_on_assign')
        && claim_group_column_exists('default_due_days');
}

function claim_group_settings_for_ids(array $groupIds): array
{
    if (!claim_groups_enabled()) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));

    $hasAutomation = claim_group_automation_enabled();
    $selectAutomation = $hasAutomation
        ? 'g.notify_on_assign, g.create_action_on_assign, g.default_due_days'
        : '0 AS notify_on_assign, 0 AS create_action_on_assign, 2 AS default_due_days';

    $stmt = pdo()->prepare("SELECT g.id, g.name, g.color, {$selectAutomation}
        FROM claim_groups g
        WHERE g.active = 1 AND g.id IN ($ph)
        ORDER BY g.name ASC");
    $stmt->execute($ids);

    $out = [];
    foreach ($stmt->fetchAll() as $row) {
        $out[(int)$row['id']] = $row;
    }
    return $out;
}

function claim_group_member_users_for_groups(array $groupIds): array
{
    if (!claim_group_members_enabled()) {
        return [];
    }

    $ids = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
    if (!$ids) {
        return [];
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stmt = pdo()->prepare("SELECT gm.group_id, u.id AS user_id, u.name, u.email, u.role
        FROM claim_group_members gm
        JOIN users u ON u.id = gm.user_id
        WHERE gm.group_id IN ($ph)
          AND u.active = 1
        ORDER BY u.name ASC");
    $stmt->execute($ids);
    return $stmt->fetchAll();
}

function claim_group_base_url(): string
{
    if (!empty($_SERVER['HTTP_HOST'])) {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $path = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? ''), '/\\');
        return $scheme . '://' . $_SERVER['HTTP_HOST'] . ($path !== '' ? $path : '');
    }
    return '';
}

function claim_group_send_mail(string $to, string $subject, string $message): bool
{
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

function claim_group_handle_claim_created(int $claimId, array $groupIds, int $createdByUserId): array
{
    $result = [
        'members' => 0,
        'actions_created' => 0,
        'emails_sent' => 0,
        'emails_failed' => 0,
        'groups' => '',
    ];

    if (!claim_groups_enabled() || !claim_group_members_enabled() || $claimId <= 0 || !$groupIds) {
        return $result;
    }

    $groups = claim_group_settings_for_ids($groupIds);
    if (!$groups) {
        return $result;
    }

    $members = claim_group_member_users_for_groups(array_keys($groups));
    if (!$members) {
        return $result;
    }

    $groupNames = array_map(static fn(array $g): string => (string)$g['name'], $groups);
    $result['groups'] = implode(', ', $groupNames);

    $claimStmt = pdo()->prepare('SELECT id, claim_number, partner_name, short_description, priority, status FROM claims WHERE id = ? LIMIT 1');
    $claimStmt->execute([$claimId]);
    $claim = $claimStmt->fetch();
    if (!$claim) {
        return $result;
    }

    $users = [];
    foreach ($members as $member) {
        $groupId = (int)$member['group_id'];
        if (!isset($groups[$groupId])) {
            continue;
        }
        $group = $groups[$groupId];
        $userId = (int)$member['user_id'];
        if ($userId <= 0) {
            continue;
        }

        if (!isset($users[$userId])) {
            $users[$userId] = [
                'id' => $userId,
                'name' => (string)($member['name'] ?? ''),
                'email' => (string)($member['email'] ?? ''),
                'action_groups' => [],
                'email_groups' => [],
                'due_days' => [],
            ];
        }

        if ((int)($group['create_action_on_assign'] ?? 0) === 1) {
            $users[$userId]['action_groups'][] = (string)$group['name'];
            $users[$userId]['due_days'][] = max(0, (int)($group['default_due_days'] ?? 2));
        }

        if ((int)($group['notify_on_assign'] ?? 0) === 1) {
            $users[$userId]['email_groups'][] = (string)$group['name'];
        }
    }

    if (!$users) {
        return $result;
    }

    $result['members'] = count($users);
    $baseUrl = claim_group_base_url();
    $claimLink = $baseUrl !== '' ? $baseUrl . '/claim_view.php?id=' . $claimId : 'claim_view.php?id=' . $claimId;

    $actionInsert = pdo()->prepare('INSERT INTO claim_actions
        (claim_id, step_key, title, description, responsible_user_id, due_date, status, created_by, created_at)
        VALUES (?, ?, ?, ?, ?, ?, "open", ?, NOW())');

    foreach ($users as $member) {
        $actionGroups = array_values(array_unique($member['action_groups']));
        $emailGroups = array_values(array_unique($member['email_groups']));

        if ($actionGroups) {
            $days = $member['due_days'] ? min($member['due_days']) : 2;
            $dueDate = $days > 0 ? date('Y-m-d', strtotime('+' . $days . ' days')) : null;
            $title = 'Gruppeninfo: Reklamation prüfen';
            $description = "Du bist über folgende Gruppe(n) dieser Reklamation zugeordnet:\n" . implode(', ', $actionGroups) . "\n\nBitte prüfe, ob du beteiligt bist oder weitere Maßnahmen erforderlich sind.";

            $actionInsert->execute([
                $claimId,
                'D1',
                $title,
                $description,
                (int)$member['id'],
                $dueDate,
                $createdByUserId,
            ]);
            $result['actions_created']++;
        }

        if ($emailGroups) {
            $email = trim((string)$member['email']);
            $subject = 'Neue 8D-Reklamation: ' . $claim['claim_number'];
            $message = "Hallo " . ($member['name'] ?: 'zusammen') . ",\n\n";
            $message .= "du wurdest über folgende Gruppe(n) einer 8D-Reklamation zugeordnet:\n" . implode(', ', $emailGroups) . "\n\n";
            $message .= "Reklamation: " . $claim['claim_number'] . "\n";
            $message .= "Partner/Bereich: " . $claim['partner_name'] . "\n";
            $message .= "Problem: " . $claim['short_description'] . "\n";
            $message .= "Priorität: " . priority_label((string)$claim['priority']) . "\n";
            $message .= "Status: " . status_label((string)$claim['status']) . "\n\n";
            $message .= "Direkt öffnen:\n" . $claimLink . "\n\n";
            $message .= "Viele Grüße\n" . (defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : '8D Tool') . "\n";

            if (claim_group_send_mail($email, $subject, $message)) {
                $result['emails_sent']++;
            } else {
                $result['emails_failed']++;
            }
        }
    }

    $details = [];
    if ($result['groups'] !== '') {
        $details[] = 'Gruppen: ' . $result['groups'];
    }
    $details[] = 'Mitglieder: ' . $result['members'];
    $details[] = 'Maßnahmen erstellt: ' . $result['actions_created'];
    $details[] = 'E-Mails gesendet: ' . $result['emails_sent'];
    if ($result['emails_failed'] > 0) {
        $details[] = 'E-Mails fehlgeschlagen: ' . $result['emails_failed'];
    }

    log_history($claimId, 'Gruppen automatisch informiert', implode("\n", $details));

    return $result;
}
