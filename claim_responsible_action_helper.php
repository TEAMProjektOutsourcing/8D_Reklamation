<?php
declare(strict_types=1);

/**
 * Automatische persönliche Startmaßnahme für den Verantwortlichen einer Reklamation.
 *
 * Die Reklamationsverantwortung in claims und die Maßnahmenverantwortung in
 * claim_actions sind technisch getrennt. Dieser Helper hält beides synchron.
 */

function claim_responsible_action_table_exists(PDO $db, string $table): bool
{
    if (function_exists('db_table_exists')) {
        return db_table_exists($table);
    }

    $stmt = $db->prepare("
        SELECT COUNT(*)
        FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
    ");
    $stmt->execute([$table]);

    return (int)$stmt->fetchColumn() > 0;
}

function claim_responsible_action_column_exists(PDO $db, string $table, string $column): bool
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

function claim_responsible_action_due_date(string $priority, ?string $baseDate = null): string
{
    $days = match ($priority) {
        'low' => 10,
        'medium' => 7,
        'high' => 5,
        'critical' => 2,
        default => 7,
    };

    $baseDate = trim((string)$baseDate);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $baseDate);

    if (!$date) {
        $date = new DateTimeImmutable('today');
    }

    return $date->modify('+' . $days . ' days')->format('Y-m-d');
}

/**
 * Erstellt oder überträgt die automatische offene D1-Startmaßnahme.
 *
 * Rückgabe:
 * - created: neue Maßnahme wurde erstellt
 * - transferred: offene Maßnahme wurde auf neuen Verantwortlichen übertragen
 * - existing: passende Maßnahme war bereits korrekt vorhanden
 * - skipped: kein Verantwortlicher gesetzt
 */
function sync_claim_responsible_start_action(
    PDO $db,
    int $claimId,
    ?int $responsibleUserId,
    string $priority,
    int $actorUserId,
    bool $responsibleChanged = false
): array {
    $result = [
        'status' => 'skipped',
        'action_id' => 0,
        'due_date' => null,
        'message' => '',
    ];

    if ($claimId <= 0 || !$responsibleUserId) {
        $result['message'] = 'Kein Verantwortlicher ausgewählt.';
        return $result;
    }

    if (!claim_responsible_action_table_exists($db, 'claim_actions')) {
        throw new RuntimeException('Die Tabelle claim_actions wurde nicht gefunden.');
    }

    $requiredColumns = [
        'claim_id',
        'step_key',
        'title',
        'responsible_user_id',
        'status',
    ];

    foreach ($requiredColumns as $column) {
        if (!claim_responsible_action_column_exists($db, 'claim_actions', $column)) {
            throw new RuntimeException('Die erforderliche Spalte claim_actions.' . $column . ' fehlt.');
        }
    }

    $title = 'Neue Reklamation prüfen';
    $description = 'Du wurdest als verantwortliche Person dieser Reklamation eingetragen. Bitte prüfe den Vorgang und starte die weitere Bearbeitung.';
    $dueDate = claim_responsible_action_due_date($priority, date('Y-m-d'));
    $result['due_date'] = $dueDate;

    // Zuerst nur eine noch offene automatische Startmaßnahme suchen.
    $openStmt = $db->prepare("
        SELECT id, responsible_user_id, due_date, status
        FROM claim_actions
        WHERE claim_id = ?
          AND step_key = 'D1'
          AND title = ?
          AND status IN ('open', 'in_progress')
        ORDER BY id DESC
        LIMIT 1
    ");
    $openStmt->execute([$claimId, $title]);
    $openAction = $openStmt->fetch();

    if ($openAction) {
        $sets = [];
        $params = [];

        if ((int)($openAction['responsible_user_id'] ?? 0) !== $responsibleUserId) {
            $sets[] = 'responsible_user_id = ?';
            $params[] = $responsibleUserId;
        }

        // Bei einem Verantwortlichenwechsel beginnt die persönliche Frist neu.
        if ($responsibleChanged || empty($openAction['due_date'])) {
            if (claim_responsible_action_column_exists($db, 'claim_actions', 'due_date')) {
                $sets[] = 'due_date = ?';
                $params[] = $dueDate;
            }
        }

        if (claim_responsible_action_column_exists($db, 'claim_actions', 'description')) {
            $sets[] = 'description = ?';
            $params[] = $description;
        }

        if ($sets) {
            if (claim_responsible_action_column_exists($db, 'claim_actions', 'updated_at')) {
                $sets[] = 'updated_at = NOW()';
            }

            $params[] = (int)$openAction['id'];
            $update = $db->prepare(
                'UPDATE claim_actions SET ' . implode(', ', $sets) . ' WHERE id = ?'
            );
            $update->execute($params);

            $result['status'] = 'transferred';
            $result['action_id'] = (int)$openAction['id'];
            $result['message'] = 'Offene D1-Startmaßnahme wurde dem Verantwortlichen zugeordnet.';
            return $result;
        }

        $result['status'] = 'existing';
        $result['action_id'] = (int)$openAction['id'];
        $result['due_date'] = $openAction['due_date'] ?: $dueDate;
        $result['message'] = 'D1-Startmaßnahme war bereits korrekt vorhanden.';
        return $result;
    }

    // Eine bereits erledigte Startmaßnahme bleibt als Historie erhalten.
    // Nur bei einem echten Verantwortlichenwechsel wird danach eine neue erzeugt.
    $anyStmt = $db->prepare("
        SELECT id, status
        FROM claim_actions
        WHERE claim_id = ?
          AND step_key = 'D1'
          AND title = ?
        ORDER BY id DESC
        LIMIT 1
    ");
    $anyStmt->execute([$claimId, $title]);
    $previousAction = $anyStmt->fetch();

    if ($previousAction && !$responsibleChanged) {
        $result['status'] = 'existing';
        $result['action_id'] = (int)$previousAction['id'];
        $result['message'] = 'Eine persönliche D1-Startmaßnahme ist bereits dokumentiert.';
        return $result;
    }

    $columns = [
        'claim_id',
        'step_key',
        'title',
        'responsible_user_id',
        'status',
    ];
    $placeholders = ['?', '?', '?', '?', '?'];
    $values = [
        $claimId,
        'D1',
        $title,
        $responsibleUserId,
        'open',
    ];

    if (claim_responsible_action_column_exists($db, 'claim_actions', 'description')) {
        $columns[] = 'description';
        $placeholders[] = '?';
        $values[] = $description;
    }

    if (claim_responsible_action_column_exists($db, 'claim_actions', 'due_date')) {
        $columns[] = 'due_date';
        $placeholders[] = '?';
        $values[] = $dueDate;
    }

    if (claim_responsible_action_column_exists($db, 'claim_actions', 'created_by')) {
        $columns[] = 'created_by';
        $placeholders[] = '?';
        $values[] = $actorUserId;
    }

    if (claim_responsible_action_column_exists($db, 'claim_actions', 'created_at')) {
        $columns[] = 'created_at';
        $placeholders[] = 'NOW()';
    }

    if (claim_responsible_action_column_exists($db, 'claim_actions', 'updated_at')) {
        $columns[] = 'updated_at';
        $placeholders[] = 'NOW()';
    }

    $insert = $db->prepare(
        'INSERT INTO claim_actions (' . implode(', ', $columns) . ') '
        . 'VALUES (' . implode(', ', $placeholders) . ')'
    );
    $insert->execute($values);

    $result['status'] = 'created';
    $result['action_id'] = (int)$db->lastInsertId();
    $result['message'] = 'Persönliche offene D1-Startmaßnahme wurde erstellt.';

    return $result;
}
