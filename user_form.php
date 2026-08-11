<?php
require_once __DIR__ . '/auth.php';
require_admin();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$returnStandortRaw = trim((string)($_GET['standort_id'] ?? ''));
$userRow = null;

$returnUsersUrl = 'users.php';
if ($returnStandortRaw === 'all') {
    $returnUsersUrl = 'users.php?standort_id=all';
} elseif (ctype_digit($returnStandortRaw) && (int)$returnStandortRaw > 0) {
    $returnUsersUrl = 'users.php?standort_id=' . (int)$returnStandortRaw;
}

if ($id > 0) {
    $stmt = pdo()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $userRow = $stmt->fetch();
    if (!$userRow) {
        flash('error', 'Benutzer wurde nicht gefunden.');
        redirect('users.php');
    }
}

$roles = role_options();
$locations = locations_enabled() ? get_locations(true) : [];

if ($userRow) {
    $userLocationIds = user_location_ids((int)$userRow['id']);
} elseif (ctype_digit($returnStandortRaw) && (int)$returnStandortRaw > 0) {
    $requestedLocationId = (int)$returnStandortRaw;
    $validLocationIds = array_map(static fn(array $loc): int => (int)$loc['id'], $locations);
    $userLocationIds = in_array($requestedLocationId, $validLocationIds, true) ? [$requestedLocationId] : [];
} else {
    $userLocationIds = array_map(static fn(array $loc): int => (int)$loc['id'], $locations);
}

$defaultLocationId = 0;
if ($userRow && db_table_exists('user_standorte')) {
    $stmt = pdo()->prepare('SELECT standort_id FROM user_standorte WHERE user_id = ? AND is_default = 1 LIMIT 1');
    $stmt->execute([(int)$userRow['id']]);
    $defaultLocationId = (int)($stmt->fetchColumn() ?: 0);
}
if (!$defaultLocationId && ctype_digit($returnStandortRaw) && (int)$returnStandortRaw > 0 && in_array((int)$returnStandortRaw, $userLocationIds, true)) {
    $defaultLocationId = (int)$returnStandortRaw;
}
if (!$defaultLocationId && $locations) {
    $defaultLocationId = (int)($userLocationIds[0] ?? $locations[0]['id']);
}

/*
 * Gruppenzugehörigkeit des Mitarbeiters.
 * Die Auswahl wird direkt aus claim_groups / claim_group_members geladen.
 */
$userGroupsEnabled = db_table_exists('claim_groups')
    && db_table_exists('claim_group_members');

$availableUserGroups = [];
$currentUserGroupIds = [];

if ($userGroupsEnabled) {
    $groupWhere = [];
    if (db_column_exists('claim_groups', 'active')) {
        $groupWhere[] = 'g.active = 1';
    }

    $groupSql = 'SELECT g.* FROM claim_groups g';
    if ($groupWhere) {
        $groupSql .= ' WHERE ' . implode(' AND ', $groupWhere);
    }

    if (db_column_exists('claim_groups', 'standort_id')) {
        $groupSql .= ' ORDER BY g.standort_id IS NOT NULL ASC, g.name ASC';
    } else {
        $groupSql .= ' ORDER BY g.name ASC';
    }

    $availableUserGroups = pdo()->query($groupSql)->fetchAll();

    if ($userRow) {
        $stmt = pdo()->prepare(
            'SELECT group_id
             FROM claim_group_members
             WHERE user_id = ?
             ORDER BY group_id ASC'
        );
        $stmt->execute([(int)$userRow['id']]);
        $currentUserGroupIds = array_map(
            'intval',
            $stmt->fetchAll(PDO::FETCH_COLUMN)
        );
    }

    if ($availableUserGroups) {
        $groupIdsForNames = array_values(array_unique(array_map(
            static fn(array $group): int => (int)($group['id'] ?? 0),
            $availableUserGroups
        )));
        $groupIdsForNames = array_values(array_filter(
            $groupIdsForNames,
            static fn(int $groupId): bool => $groupId > 0
        ));

        if ($groupIdsForNames) {
            $placeholders = implode(
                ',',
                array_fill(0, count($groupIdsForNames), '?')
            );

            $stmt = pdo()->prepare(
                "SELECT
                    gm.group_id,
                    GROUP_CONCAT(
                        DISTINCT u.name
                        ORDER BY u.name ASC
                        SEPARATOR ' · '
                    ) AS member_names
                 FROM claim_group_members gm
                 JOIN users u ON u.id = gm.user_id
                 WHERE gm.group_id IN ($placeholders)
                 GROUP BY gm.group_id"
            );
            $stmt->execute($groupIdsForNames);

            $memberNamesByGroup = [];
            foreach ($stmt->fetchAll() as $memberRow) {
                $memberNamesByGroup[(int)$memberRow['group_id']] =
                    trim((string)($memberRow['member_names'] ?? ''));
            }

            foreach ($availableUserGroups as &$availableUserGroup) {
                $availableUserGroup['member_names'] =
                    $memberNamesByGroup[(int)$availableUserGroup['id']] ?? '';
            }
            unset($availableUserGroup);
        }
    }
}

require __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1"><?= $userRow ? 'Benutzer bearbeiten' : 'Benutzer anlegen' ?></h1>
        <div class="text-muted"><?= $userRow ? 'Stammdaten und Rolle des Benutzers ändern.' : 'Neuen Zugang für das 8D-Tool erstellen.' ?></div>
    </div>
    <a href="<?= e($returnUsersUrl) ?>" class="btn btn-outline-secondary">Zurück</a>
</div>

<div class="row justify-content-center">
    <div class="col-lg-9 col-xl-8">
        <div class="card">
            <div class="card-body p-4">
                <form method="post" action="user_save.php" autocomplete="off">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)($userRow['id'] ?? 0) ?>">
                    <?php if ($returnStandortRaw !== ''): ?>
                        <input type="hidden" name="return_standort_id" value="<?= e($returnStandortRaw) ?>">
                    <?php endif; ?>

                    <div class="mb-3">
                        <label class="form-label">Name *</label>
                        <input type="text" name="name" class="form-control" required maxlength="120" value="<?= e($userRow['name'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">E-Mail *</label>
                        <input type="email" name="email" class="form-control" required maxlength="190" value="<?= e($userRow['email'] ?? '') ?>">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Rolle *</label>
                        <select name="role" class="form-select" required>
                            <?php foreach ($roles as $key => $label): ?>
                                <option value="<?= e($key) ?>" <?= (($userRow['role'] ?? 'employee') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text">
                            Admin = alles · Qualität = Reklamationen abschließen · Mitarbeiter = bearbeiten · Leser = nur ansehen.
                        </div>
                    </div>

                    <?php if (!$userRow): ?>
                        <div class="mb-3">
                            <label class="form-label">Start-Passwort *</label>
                            <input type="password" name="password" class="form-control" required minlength="8">
                            <div class="form-text"><?= e(password_rules_hint()) ?></div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Start-Passwort wiederholen *</label>
                            <input type="password" name="password_repeat" class="form-control" required minlength="8">
                        </div>
                    <?php else: ?>
                        <div class="alert alert-info mb-3">
                            Das Passwort wird separat über den Button <strong>Passwort</strong> in der Benutzerliste geändert.
                        </div>
                    <?php endif; ?>

                    <?php if (locations_enabled()): ?>
                        <div class="mb-3">
                            <label class="form-label">Standorte *</label>
                            <div class="border rounded p-3 bg-light">
                                <?php foreach ($locations as $loc): ?>
                                    <div class="form-check d-flex align-items-center gap-2 mb-2">
                                        <input class="form-check-input" type="checkbox" name="standort_ids[]" value="<?= (int)$loc['id'] ?>" id="loc<?= (int)$loc['id'] ?>" <?= in_array((int)$loc['id'], $userLocationIds, true) ? 'checked' : '' ?>>
                                        <label class="form-check-label flex-grow-1" for="loc<?= (int)$loc['id'] ?>"><?= e($loc['kuerzel']) ?> · <?= e($loc['name']) ?></label>
                                        <label class="small text-muted mb-0">
                                            <input type="radio" name="default_standort_id" value="<?= (int)$loc['id'] ?>" <?= $defaultLocationId === (int)$loc['id'] ? 'checked' : '' ?>> Standard
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <div class="form-text">Benutzer sehen später nur die Standorte, denen sie zugeordnet sind. Admins können zusätzlich „Alle Standorte“ wählen.</div>
                        </div>
                    <?php endif; ?>

                    <?php if ($userGroupsEnabled): ?>
                        <div class="mb-3">
                            <label class="form-label">Gruppenzugehörigkeit</label>
                            <div class="form-text mb-2">
                                Wähle aus, in welchen Reklamationsgruppen der
                                Mitarbeiter mitarbeitet. Die Auswahl ist freiwillig
                                und richtet sich nach den ausgewählten Standorten.
                            </div>

                            <?php if ($availableUserGroups): ?>
                                <div
                                    class="claim-group-select"
                                    id="userGroupSelect"
                                >
                                    <button
                                        type="button"
                                        class="claim-group-select-button"
                                        id="userGroupSelectButton"
                                        aria-expanded="false"
                                    >
                                        <span id="userGroupSelectButtonText">
                                            Gruppen auswählen
                                        </span>
                                        <span class="claim-group-select-arrow">▾</span>
                                    </button>

                                    <div
                                        class="claim-group-select-menu"
                                        id="userGroupSelectMenu"
                                        hidden
                                    >
                                        <div class="claim-group-select-search-wrap">
                                            <input
                                                type="search"
                                                class="form-control claim-group-select-search"
                                                id="userGroupSearch"
                                                placeholder="Gruppe suchen..."
                                                autocomplete="off"
                                            >
                                        </div>

                                        <div class="claim-group-select-options">
                                            <?php foreach ($availableUserGroups as $group): ?>
                                                <?php
                                                    $groupId = (int)($group['id'] ?? 0);
                                                    $groupName = trim((string)($group['name'] ?? ''));
                                                    $groupDescription = trim((string)($group['description'] ?? ''));
                                                    $groupMembers = trim((string)($group['member_names'] ?? ''));
                                                    $groupLocationId = isset($group['standort_id'])
                                                        ? (int)($group['standort_id'] ?? 0)
                                                        : 0;

                                                    $groupMeta = $groupMembers !== ''
                                                        ? $groupMembers
                                                        : ($groupDescription !== ''
                                                            ? $groupDescription
                                                            : ($groupLocationId > 0
                                                                ? 'Standortgruppe'
                                                                : 'Globale Gruppe'));

                                                    $groupLabel = $groupName . ' · ' . $groupMeta;
                                                ?>

                                                <label
                                                    class="claim-group-select-option"
                                                    data-user-group-option
                                                    data-group-location-id="<?= $groupLocationId ?>"
                                                    data-group-search="<?= e(strtolower($groupLabel)) ?>"
                                                >
                                                    <input
                                                        class="form-check-input"
                                                        type="checkbox"
                                                        name="group_ids[]"
                                                        value="<?= $groupId ?>"
                                                        data-user-group-checkbox
                                                        data-group-label="<?= e($groupLabel) ?>"
                                                        <?= in_array($groupId, $currentUserGroupIds, true)
                                                            ? 'checked'
                                                            : '' ?>
                                                    >

                                                    <span class="claim-group-option-content">
                                                        <span class="claim-group-option-title">
                                                            <?= e($groupName) ?>
                                                        </span>
                                                        <span class="claim-group-option-owner">
                                                            <?= e($groupMeta) ?>
                                                        </span>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>

                                            <div
                                                class="text-muted small p-3"
                                                id="userGroupEmptyMessage"
                                                hidden
                                            >
                                                Für die ausgewählten Standorte
                                                wurde keine passende Gruppe gefunden.
                                            </div>
                                        </div>

                                        <div class="claim-group-select-footer">
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-outline-secondary"
                                                id="userGroupClear"
                                            >
                                                Auswahl löschen
                                            </button>
                                            <button
                                                type="button"
                                                class="btn btn-sm btn-primary"
                                                id="userGroupDone"
                                            >
                                                Fertig
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div class="alert alert-light border mb-0">
                                    Noch keine aktive Reklamationsgruppe vorhanden.
                                    <a href="group_form.php">Gruppe anlegen</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" role="switch" name="active" value="1" id="activeSwitch" <?= (int)($userRow['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="activeSwitch">Benutzer ist aktiv</label>
                    </div>

                    <div class="d-flex justify-content-end gap-2">
                        <a href="<?= e($returnUsersUrl) ?>" class="btn btn-outline-secondary">Abbrechen</a>
                        <button class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const groupSelect = document.getElementById('userGroupSelect');
    const button = document.getElementById('userGroupSelectButton');
    const buttonText = document.getElementById('userGroupSelectButtonText');
    const menu = document.getElementById('userGroupSelectMenu');
    const search = document.getElementById('userGroupSearch');
    const clearButton = document.getElementById('userGroupClear');
    const doneButton = document.getElementById('userGroupDone');
    const emptyMessage = document.getElementById('userGroupEmptyMessage');
    const options = Array.from(
        document.querySelectorAll('[data-user-group-option]')
    );
    const checkboxes = Array.from(
        document.querySelectorAll('[data-user-group-checkbox]')
    );
    const locationCheckboxes = Array.from(
        document.querySelectorAll('input[name="standort_ids[]"]')
    );

    if (!groupSelect || !button || !menu) {
        return;
    }

    function selectedLocationIds() {
        return new Set(
            locationCheckboxes
                .filter(function (checkbox) {
                    return checkbox.checked;
                })
                .map(function (checkbox) {
                    return String(checkbox.value);
                })
        );
    }

    function optionMatchesLocation(option, selectedLocations) {
        const locationId = String(
            option.getAttribute('data-group-location-id') || '0'
        );

        return locationId === '0'
            || locationCheckboxes.length === 0
            || selectedLocations.has(locationId);
    }

    function applyFilters() {
        const selectedLocations = selectedLocationIds();
        const query = search
            ? String(search.value || '').toLowerCase().trim()
            : '';
        let visibleCount = 0;

        options.forEach(function (option) {
            const checkbox = option.querySelector(
                '[data-user-group-checkbox]'
            );
            const locationMatches = optionMatchesLocation(
                option,
                selectedLocations
            );
            const haystack = String(
                option.getAttribute('data-group-search') || ''
            ).toLowerCase();
            const searchMatches = query === ''
                || haystack.indexOf(query) !== -1;

            /*
             * Wechselt der Admin die Standorte, dürfen Gruppen eines
             * nicht mehr zugeordneten Standortes nicht gespeichert bleiben.
             */
            if (!locationMatches && checkbox) {
                checkbox.checked = false;
            }

            option.hidden = !(locationMatches && searchMatches);

            if (!option.hidden) {
                visibleCount++;
            }
        });

        if (emptyMessage) {
            emptyMessage.hidden = visibleCount > 0;
        }

        updateButtonText();
    }

    function updateButtonText() {
        if (!buttonText) {
            return;
        }

        const selected = checkboxes.filter(function (checkbox) {
            return checkbox.checked;
        });

        if (selected.length === 0) {
            buttonText.textContent = 'Gruppen auswählen';
            return;
        }

        if (selected.length === 1) {
            buttonText.textContent =
                selected[0].getAttribute('data-group-label')
                || '1 Gruppe ausgewählt';
            return;
        }

        buttonText.textContent =
            selected.length + ' Gruppen ausgewählt';
    }

    function setOpen(open) {
        menu.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        groupSelect.classList.toggle('is-open', open);

        if (open && search) {
            window.setTimeout(function () {
                search.focus();
            }, 50);
        }
    }

    button.addEventListener('click', function () {
        setOpen(menu.hidden);
    });

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateButtonText);
    });

    locationCheckboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', applyFilters);
    });

    if (search) {
        search.addEventListener('input', applyFilters);
    }

    if (clearButton) {
        clearButton.addEventListener('click', function () {
            checkboxes.forEach(function (checkbox) {
                const option = checkbox.closest(
                    '[data-user-group-option]'
                );

                if (!option || !option.hidden) {
                    checkbox.checked = false;
                }
            });

            updateButtonText();
        });
    }

    if (doneButton) {
        doneButton.addEventListener('click', function () {
            setOpen(false);
        });
    }

    document.addEventListener('click', function (event) {
        if (!groupSelect.contains(event.target)) {
            setOpen(false);
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape') {
            setOpen(false);
        }
    });

    applyFilters();
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
