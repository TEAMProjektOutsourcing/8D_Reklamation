<?php
require_once __DIR__ . '/auth.php';
require_login();

$db = pdo();
$current = current_user();

$stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([(int)$current['id']]);
$userRow = $stmt->fetch();

if (!$userRow) {
    session_destroy();
    redirect('login.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    $mode = (string)($_POST['mode'] ?? 'profile');

    if ($mode === 'profile') {
        $name = trim((string)($_POST['name'] ?? ''));
        $email = strtolower(trim((string)($_POST['email'] ?? '')));

        if ($name === '' || $email === '') {
            flash('error', 'Name und E-Mail sind Pflichtfelder.');
            redirect('profile.php');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Bitte eine gültige E-Mail-Adresse eintragen.');
            redirect('profile.php');
        }

        $check = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $check->execute([$email, (int)$userRow['id']]);
        if ($check->fetch()) {
            flash('error', 'Diese E-Mail-Adresse wird bereits verwendet.');
            redirect('profile.php');
        }

        $update = $db->prepare('UPDATE users SET name = ?, email = ? WHERE id = ?');
        $update->execute([$name, $email, (int)$userRow['id']]);

        flash('success', 'Profil wurde aktualisiert.');
        redirect('profile.php');
    }

    if ($mode === 'password') {
        $oldPassword = (string)($_POST['old_password'] ?? '');
        $newPassword = (string)($_POST['new_password'] ?? '');
        $newPasswordRepeat = (string)($_POST['new_password_repeat'] ?? '');

        if (!password_verify($oldPassword, $userRow['password_hash'])) {
            flash('error', 'Das aktuelle Passwort ist falsch.');
            redirect('profile.php');
        }

        if (strlen($newPassword) < 8) {
            flash('error', 'Das neue Passwort muss mindestens 8 Zeichen lang sein.');
            redirect('profile.php');
        }

        if ($newPassword !== $newPasswordRepeat) {
            flash('error', 'Die neuen Passwörter stimmen nicht überein.');
            redirect('profile.php');
        }

        $update = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?');
        $update->execute([password_hash($newPassword, PASSWORD_DEFAULT), (int)$userRow['id']]);

        flash('success', 'Passwort wurde geändert.');
        redirect('profile.php');
    }
}

require __DIR__ . '/header.php';
?>

<div class="card page-hero profile-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">Benutzerkonto</div>
                <h1 class="page-title display-6 fw-bold mb-2">Mein Profil</h1>
                <div class="page-subtitle">
                    Eigene Stammdaten aktualisieren und das Passwort ändern. Deine Rolle kann nur ein Admin ändern.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a href="dashboard.php" class="btn btn-outline-primary">Zum Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card profile-card h-100">
            <div class="card-header fw-bold">
                <span class="profile-card-icon">P</span>
                <span>Profil</span>
            </div>

            <div class="card-body p-4">
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mode" value="profile">

                    <div class="mb-3">
                        <label class="form-label">Name</label>
                        <input type="text" name="name" class="form-control" required maxlength="120" value="<?= e($userRow['name']) ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">E-Mail</label>
                        <input type="email" name="email" class="form-control" required maxlength="190" value="<?= e($userRow['email']) ?>">
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Rolle</label>
                        <div class="profile-role-box"><?= role_badge($userRow['role']) ?></div>
                        <div class="form-text">Die eigene Rolle kann nur ein Admin ändern.</div>
                    </div>

                    <button class="btn btn-primary">Profil speichern</button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-6">
        <div class="card profile-card password-card h-100">
            <div class="card-header fw-bold">
                <span class="profile-card-icon">S</span>
                <span>Passwort</span>
            </div>

            <div class="card-body p-4">
                <form method="post" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="mode" value="password">

                    <div class="mb-3">
                        <label class="form-label">Aktuelles Passwort</label>
                        <input type="password" name="old_password" class="form-control" required>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Neues Passwort</label>
                        <input type="password" name="new_password" class="form-control" required minlength="8">
                    </div>

                    <div class="profile-password-hint mb-3">
                        <?= e(password_rules_hint()) ?>
                    </div>

                    <div class="mb-4">
                        <label class="form-label">Neues Passwort wiederholen</label>
                        <input type="password" name="new_password_repeat" class="form-control" required minlength="8">
                    </div>

                    <button class="btn btn-primary">Passwort ändern</button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
