<?php
require_once __DIR__ . '/auth.php';
if (file_exists(__DIR__ . '/claim_group_helper.php')) {
    require_once __DIR__ . '/claim_group_helper.php';
}
require_admin();
require_csrf();

function users_return_url(string $returnStandortRaw): string
{
    $returnStandortRaw = trim($returnStandortRaw);
    if ($returnStandortRaw === 'all') {
        return 'users.php?standort_id=all';
    }
    if (ctype_digit($returnStandortRaw) && (int)$returnStandortRaw > 0) {
        return 'users.php?standort_id=' . (int)$returnStandortRaw;
    }
    return 'users.php';
}

function user_form_return_url(int $id, string $returnStandortRaw): string
{
    $url = $id > 0 ? 'user_form.php?id=' . $id : 'user_form.php';
    $returnStandortRaw = trim($returnStandortRaw);
    if ($returnStandortRaw !== '') {
        $url .= ($id > 0 ? '&' : '?') . 'standort_id=' . rawurlencode($returnStandortRaw);
    }
    return $url;
}


function user_group_memberships_enabled(): bool
{
    return db_table_exists('claim_groups')
        && db_table_exists('claim_group_members');
}

function sanitize_user_group_ids(
    PDO $db,
    array $rawGroupIds,
    array $locationIds
): array {
    if (!user_group_memberships_enabled()) {
        return [];
    }

    $groupIds = [];
    foreach ($rawGroupIds as $rawGroupId) {
        if (is_numeric($rawGroupId) && (int)$rawGroupId > 0) {
            $groupIds[] = (int)$rawGroupId;
        }
    }
    $groupIds = array_values(array_unique($groupIds));

    if (!$groupIds) {
        return [];
    }

    $placeholders = implode(
        ',',
        array_fill(0, count($groupIds), '?')
    );

    $sql = "SELECT id
            FROM claim_groups
            WHERE id IN ($placeholders)";
    $params = $groupIds;

    if (db_column_exists('claim_groups', 'active')) {
        $sql .= ' AND active = 1';
    }

    if (
        locations_enabled()
        && db_column_exists('claim_groups', 'standort_id')
    ) {
        if (!$locationIds) {
            throw new RuntimeException(
                'Gruppen können nur zusammen mit einem Standort zugewiesen werden.'
            );
        }

        $locationPlaceholders = implode(
            ',',
            array_fill(0, count($locationIds), '?')
        );

        $sql .= " AND (
                    standort_id IS NULL
                    OR standort_id IN ($locationPlaceholders)
                  )";
        $params = array_merge($params, $locationIds);
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    $validIds = array_map(
        'intval',
        $stmt->fetchAll(PDO::FETCH_COLUMN)
    );

    $invalidIds = array_values(array_diff($groupIds, $validIds));
    if ($invalidIds) {
        throw new RuntimeException(
            'Mindestens eine ausgewählte Gruppe gehört nicht zu den zugewiesenen Standorten oder ist nicht mehr aktiv.'
        );
    }

    return $validIds;
}

function sync_user_group_memberships(
    PDO $db,
    int $userId,
    array $groupIds
): void {
    if (!user_group_memberships_enabled() || $userId <= 0) {
        return;
    }

    $delete = $db->prepare(
        'DELETE FROM claim_group_members WHERE user_id = ?'
    );
    $delete->execute([$userId]);

    if (!$groupIds) {
        return;
    }

    $insert = $db->prepare(
        'INSERT INTO claim_group_members
            (group_id, user_id, created_at)
         VALUES (?, ?, NOW())'
    );

    foreach (array_values(array_unique(array_map('intval', $groupIds))) as $groupId) {
        if ($groupId > 0) {
            $insert->execute([$groupId, $userId]);
        }
    }
}

$id = (int)($_POST['id'] ?? 0);
$returnStandortRaw = (string)($_POST['return_standort_id'] ?? '');
$returnUsersUrl = users_return_url($returnStandortRaw);
$returnFormUrl = user_form_return_url($id, $returnStandortRaw);

$name = trim((string)($_POST['name'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$role = (string)($_POST['role'] ?? 'employee');
$active = isset($_POST['active']) ? 1 : 0;
$password = (string)($_POST['password'] ?? '');
$passwordRepeat = (string)($_POST['password_repeat'] ?? '');
$postedLocationIds = array_values(array_unique(array_map('intval', (array)($_POST['standort_ids'] ?? []))));
$defaultStandortId = (int)($_POST['default_standort_id'] ?? 0);
$postedGroupIdsRaw = is_array($_POST['group_ids'] ?? null)
    ? $_POST['group_ids']
    : [];

if (locations_enabled()) {
    $validLocationIds = array_map(static fn(array $row): int => (int)$row['id'], get_locations(true));
    $postedLocationIds = array_values(array_intersect($postedLocationIds, $validLocationIds));
    if (!$postedLocationIds) {
        flash('error', 'Bitte mindestens einen Standort zuweisen.');
        redirect($returnFormUrl);
    }
    if (!in_array($defaultStandortId, $postedLocationIds, true)) {
        $defaultStandortId = $postedLocationIds[0];
    }
}

if ($name === '' || $email === '') {
    flash('error', 'Name und E-Mail sind Pflichtfelder.');
    redirect($returnFormUrl);
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    flash('error', 'Bitte eine gültige E-Mail-Adresse eintragen.');
    redirect($returnFormUrl);
}

if (!array_key_exists($role, role_options())) {
    flash('error', 'Ungültige Rolle.');
    redirect($returnFormUrl);
}

$db = pdo();

try {
    $postedGroupIds = sanitize_user_group_ids(
        $db,
        $postedGroupIdsRaw,
        $postedLocationIds
    );
} catch (Throwable $e) {
    flash('error', $e->getMessage());
    redirect($returnFormUrl);
}

$stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
$stmt->execute([$email, $id]);
if ($stmt->fetch()) {
    flash('error', 'Diese E-Mail-Adresse wird bereits verwendet.');
    redirect($returnFormUrl);
}

if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch();
    if (!$existing) {
        flash('error', 'Benutzer wurde nicht gefunden.');
        redirect($returnUsersUrl);
    }

    if ((int)$existing['id'] === (int)current_user()['id'] && $active === 0) {
        flash('error', 'Du kannst deinen eigenen Benutzer nicht deaktivieren.');
        redirect($returnFormUrl);
    }

    if ($existing['role'] === 'admin' && $role !== 'admin' && active_admin_count($id) === 0) {
        flash('error', 'Der letzte aktive Admin darf nicht heruntergestuft werden.');
        redirect($returnFormUrl);
    }

    if ($existing['role'] === 'admin' && $active === 0 && active_admin_count($id) === 0) {
        flash('error', 'Der letzte aktive Admin darf nicht deaktiviert werden.');
        redirect($returnFormUrl);
    }

    try {
        $db->beginTransaction();

        $stmt = $db->prepare(
            'UPDATE users
             SET name = ?, email = ?, role = ?, active = ?
             WHERE id = ?'
        );
        $stmt->execute([$name, $email, $role, $active, $id]);

        if (locations_enabled()) {
            $db->prepare(
                'DELETE FROM user_standorte WHERE user_id = ?'
            )->execute([$id]);

            $ins = $db->prepare(
                'INSERT INTO user_standorte
                    (user_id, standort_id, standort_role, is_default)
                 VALUES (?, ?, ?, ?)'
            );

            foreach ($postedLocationIds as $locId) {
                $ins->execute([
                    $id,
                    $locId,
                    $role,
                    $locId === $defaultStandortId ? 1 : 0,
                ]);
            }
        }

        sync_user_group_memberships(
            $db,
            $id,
            $postedGroupIds
        );

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'Benutzer und Gruppen konnten nicht aktualisiert werden: '
            . $e->getMessage()
        );

        flash(
            'error',
            'Benutzer und Gruppenzugehörigkeit konnten nicht gespeichert werden.'
        );
        redirect($returnFormUrl);
    }

    flash('success', 'Benutzer und Gruppenzugehörigkeit wurden aktualisiert.');
    redirect($returnUsersUrl);
}

if (strlen($password) < 8) {
    flash('error', 'Das Passwort muss mindestens 8 Zeichen lang sein.');
    redirect($returnFormUrl);
}

if ($password !== $passwordRepeat) {
    flash('error', 'Die Passwörter stimmen nicht überein.');
    redirect($returnFormUrl);
}

try {
    $db->beginTransaction();

    $stmt = $db->prepare(
        'INSERT INTO users
            (name, email, password_hash, role, active)
         VALUES (?, ?, ?, ?, ?)'
    );
    $stmt->execute([
        $name,
        $email,
        password_hash($password, PASSWORD_DEFAULT),
        $role,
        $active,
    ]);

    $newUserId = (int)$db->lastInsertId();

    if (locations_enabled()) {
        $ins = $db->prepare(
            'INSERT INTO user_standorte
                (user_id, standort_id, standort_role, is_default)
             VALUES (?, ?, ?, ?)'
        );

        foreach ($postedLocationIds as $locId) {
            $ins->execute([
                $newUserId,
                $locId,
                $role,
                $locId === $defaultStandortId ? 1 : 0,
            ]);
        }
    }

    sync_user_group_memberships(
        $db,
        $newUserId,
        $postedGroupIds
    );

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    error_log(
        'Benutzer und Gruppen konnten nicht angelegt werden: '
        . $e->getMessage()
    );

    flash(
        'error',
        'Benutzer und Gruppenzugehörigkeit konnten nicht angelegt werden.'
    );
    redirect($returnFormUrl);
}

flash('success', 'Benutzer und Gruppenzugehörigkeit wurden angelegt.');
redirect($returnUsersUrl);
