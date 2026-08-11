<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

$returnStandortRaw = trim((string)($_POST['return_standort_id'] ?? ''));
$returnUrl = 'users.php';
if ($returnStandortRaw === 'all') {
    $returnUrl = 'users.php?standort_id=all';
} elseif (ctype_digit($returnStandortRaw) && (int)$returnStandortRaw > 0) {
    $returnUrl = 'users.php?standort_id=' . (int)$returnStandortRaw;
}

$id = (int)($_POST['id'] ?? 0);
$active = (int)($_POST['active'] ?? 0) === 1 ? 1 : 0;

$stmt = pdo()->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$id]);
$userRow = $stmt->fetch();

if (!$userRow) {
    flash('error', 'Benutzer wurde nicht gefunden.');
    redirect($returnUrl);
}

if ((int)$userRow['id'] === (int)current_user()['id']) {
    flash('error', 'Du kannst deinen eigenen Benutzer nicht deaktivieren.');
    redirect($returnUrl);
}

if ($userRow['role'] === 'admin' && $active === 0 && active_admin_count($id) === 0) {
    flash('error', 'Der letzte aktive Admin darf nicht deaktiviert werden.');
    redirect($returnUrl);
}

$update = pdo()->prepare('UPDATE users SET active = ? WHERE id = ?');
$update->execute([$active, $id]);

flash('success', $active === 1 ? 'Benutzer wurde aktiviert.' : 'Benutzer wurde deaktiviert.');
redirect($returnUrl);
