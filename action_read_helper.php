<?php
declare(strict_types=1);

/**
 * Maßnahmen gelesen / ungelesen
 *
 * Die NAV-Badge "Meine Maßnahmen" zählt nicht mehr alle offenen Maßnahmen,
 * sondern nur noch ungelesene offene Maßnahmen.
 */

function claim_action_reads_enabled(): bool
{
    return db_table_exists('claim_action_reads');
}

function mark_action_read(PDO $db, int $actionId, int $userId): void
{
    if ($actionId <= 0 || $userId <= 0 || !claim_action_reads_enabled()) {
        return;
    }

    $stmt = $db->prepare("INSERT INTO claim_action_reads (action_id, user_id, read_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE read_at = VALUES(read_at)");
    $stmt->execute([$actionId, $userId]);
}

function my_unread_open_action_count(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    if (!claim_action_reads_enabled()) {
        return my_open_action_count($userId);
    }

    $db = pdo();
    [$locationSql, $locationParams] = location_scope_condition('c');

    $sql = "SELECT COUNT(*)
        FROM claim_actions a
        JOIN claims c ON c.id = a.claim_id
        LEFT JOIN claim_action_reads r ON r.action_id = a.id AND r.user_id = ?
        WHERE a.responsible_user_id = ?
          AND a.status IN ('open','in_progress')
          AND r.id IS NULL" . $locationSql;

    $params = array_merge([$userId, $userId], $locationParams);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    return (int)$stmt->fetchColumn();
}

function my_unread_critical_action_count(int $userId): int
{
    if ($userId <= 0) {
        return 0;
    }

    if (!claim_action_reads_enabled()) {
        return my_critical_action_count($userId);
    }

    $db = pdo();
    [$locationSql, $locationParams] = location_scope_condition('c');

    $sql = "SELECT a.status, a.due_date, a.created_at
        FROM claim_actions a
        JOIN claims c ON c.id = a.claim_id
        LEFT JOIN claim_action_reads r ON r.action_id = a.id AND r.user_id = ?
        WHERE a.responsible_user_id = ?
          AND a.status IN ('open','in_progress')
          AND r.id IS NULL" . $locationSql;

    $params = array_merge([$userId, $userId], $locationParams);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $critical = 0;
    foreach ($stmt->fetchAll() as $action) {
        if (action_traffic_level($action) === 'red') {
            $critical++;
        }
    }

    return $critical;
}
