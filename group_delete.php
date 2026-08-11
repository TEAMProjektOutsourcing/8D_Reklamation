<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/claim_group_helper.php';
require_admin();
require_csrf();

if (!claim_groups_enabled()) {
    flash('warning', 'Bitte zuerst die Gruppen-Migration ausführen.');
    redirect('run_claim_groups_migration.php');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    flash('danger', 'Ungültige Gruppe.');
    redirect('groups.php');
}

$active = isset($_POST['reactivate']) ? 1 : 0;
$stmt = pdo()->prepare('UPDATE claim_groups SET active = ?, updated_by = ?, updated_at = NOW() WHERE id = ?');
$stmt->execute([$active, (int)(current_user()['id'] ?? 0), $id]);

flash('success', $active ? 'Gruppe wurde aktiviert.' : 'Gruppe wurde deaktiviert.');
redirect('groups.php');
