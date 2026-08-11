<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/claim_group_helper.php';
require_admin();
require_csrf();

if (!claim_groups_enabled()) {
    flash('warning', 'Bitte zuerst die Gruppen-Migration ausführen.');
    redirect('run_claim_groups_migration.php');
}

$db = pdo();
$user = current_user();
$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$description = trim((string)($_POST['description'] ?? '')) ?: null;
$color = claim_group_color_class((string)($_POST['color'] ?? 'secondary'));
$active = isset($_POST['active']) ? 1 : 0;
$notifyOnAssign = isset($_POST['notify_on_assign']) ? 1 : 0;
$createActionOnAssign = isset($_POST['create_action_on_assign']) ? 1 : 0;
$defaultDueDays = max(0, min(365, (int)($_POST['default_due_days'] ?? 2)));
$escalateYellow = isset($_POST['escalate_yellow']) ? 1 : 0;
$escalateRed = isset($_POST['escalate_red']) ? 1 : 0;
$standortId = locations_enabled() && ($_POST['standort_id'] ?? '') !== '' ? (int)$_POST['standort_id'] : null;
$memberIds = is_array($_POST['member_ids'] ?? null) ? $_POST['member_ids'] : [];

if ($name === '') {
    flash('danger', 'Bitte einen Gruppennamen eingeben.');
    redirect($id > 0 ? 'group_form.php?id=' . $id : 'group_form.php');
}

if ($standortId !== null && !location_by_id($standortId)) {
    flash('danger', 'Der gewählte Standort wurde nicht gefunden.');
    redirect($id > 0 ? 'group_form.php?id=' . $id : 'group_form.php');
}

try {
    $db->beginTransaction();

    if ($id > 0) {
        $stmt = $db->prepare('UPDATE claim_groups
            SET standort_id = ?, name = ?, description = ?, color = ?, active = ?, notify_on_assign = ?, create_action_on_assign = ?, default_due_days = ?, escalate_yellow = ?, escalate_red = ?, updated_by = ?, updated_at = NOW()
            WHERE id = ?');
        $stmt->execute([$standortId, $name, $description, $color, $active, $notifyOnAssign, $createActionOnAssign, $defaultDueDays, $escalateYellow, $escalateRed, (int)$user['id'], $id]);
    } else {
        $stmt = $db->prepare('INSERT INTO claim_groups
            (standort_id, name, description, color, active, notify_on_assign, create_action_on_assign, default_due_days, escalate_yellow, escalate_red, created_by, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())');
        $stmt->execute([$standortId, $name, $description, $color, $active, $notifyOnAssign, $createActionOnAssign, $defaultDueDays, $escalateYellow, $escalateRed, (int)$user['id'], (int)$user['id']]);
        $id = (int)$db->lastInsertId();
    }

    save_claim_group_members($id, $memberIds);

    $db->commit();
    flash('success', 'Gruppe wurde gespeichert.');
    redirect('groups.php');
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (defined('APP_DEBUG') && APP_DEBUG) {
        die('Fehler: ' . e($e->getMessage()));
    }
    flash('danger', 'Gruppe konnte nicht gespeichert werden.');
    redirect($id > 0 ? 'group_form.php?id=' . $id : 'group_form.php');
}
