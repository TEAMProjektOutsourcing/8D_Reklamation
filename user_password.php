<?php
require_once __DIR__ . '/auth.php';
require_admin();

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$returnStandortRaw = trim((string)($_GET['standort_id'] ?? $_POST['return_standort_id'] ?? ''));
$returnUsersUrl = 'users.php';
if ($returnStandortRaw === 'all') {
    $returnUsersUrl = 'users.php?standort_id=all';
} elseif (ctype_digit($returnStandortRaw) && (int)$returnStandortRaw > 0) {
    $returnUsersUrl = 'users.php?standort_id=' . (int)$returnStandortRaw;
}

$stmt = pdo()->prepare('SELECT id, name, email, role, active FROM users WHERE id = ?');
$stmt->execute([$id]);
$userRow = $stmt->fetch();

if (!$userRow) {
    flash('error', 'Benutzer wurde nicht gefunden.');
    redirect($returnUsersUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $password = (string)($_POST['password'] ?? '');
    $passwordRepeat = (string)($_POST['password_repeat'] ?? '');

    $passwordUrl = 'user_password.php?id=' . $id;
    if ($returnStandortRaw !== '') {
        $passwordUrl .= '&standort_id=' . rawurlencode($returnStandortRaw);
    }

    if (strlen($password) < 8) {
        flash('error', 'Das Passwort muss mindestens 8 Zeichen lang sein.');
        redirect($passwordUrl);
    }

    if ($password !== $passwordRepeat) {
        flash('error', 'Die Passwörter stimmen nicht überein.');
        redirect($passwordUrl);
    }

    $update = pdo()->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
    $update->execute([password_hash($password, PASSWORD_DEFAULT), $id]);

    flash('success', 'Passwort wurde geändert.');
    redirect($returnUsersUrl);
}

require __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">Passwort ändern</h1>
        <div class="text-muted"><?= e($userRow['name']) ?> · <?= e($userRow['email']) ?></div>
    </div>
    <a href="<?= e($returnUsersUrl) ?>" class="btn btn-outline-secondary">Zurück</a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-6 col-xl-5">
        <div class="card">
            <div class="card-body p-4">
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$userRow['id'] ?>">
                    <?php if ($returnStandortRaw !== ''): ?>
                        <input type="hidden" name="return_standort_id" value="<?= e($returnStandortRaw) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Neues Passwort *</label>
                        <input type="password" name="password" class="form-control" required minlength="8">
                        <div class="form-text"><?= e(password_rules_hint()) ?></div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Neues Passwort wiederholen *</label>
                        <input type="password" name="password_repeat" class="form-control" required minlength="8">
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= e($returnUsersUrl) ?>" class="btn btn-outline-secondary">Abbrechen</a>
                        <button class="btn btn-primary">Passwort speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
