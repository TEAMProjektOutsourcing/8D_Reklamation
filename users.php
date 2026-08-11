<?php
require_once __DIR__ . '/auth.php';
require_admin();

$locationsReady = locations_enabled();
$selectedStandortRaw = trim((string)($_GET['standort_id'] ?? ''));
$selectedStandortId = 0;
$selectedStandort = null;
$showAllLocations = false;

if ($locationsReady && $selectedStandortRaw !== '') {
    if ($selectedStandortRaw === 'all') {
        $showAllLocations = true;
    } elseif (ctype_digit($selectedStandortRaw)) {
        $selectedStandortId = (int)$selectedStandortRaw;
        $selectedStandort = location_by_id($selectedStandortId);
        if (!$selectedStandort) {
            flash('error', 'Standort wurde nicht gefunden.');
            redirect('users.php');
        }
    } else {
        flash('error', 'Ungültiger Standortfilter.');
        redirect('users.php');
    }
}

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'active');
if (!in_array($status, ['active', 'inactive', 'all'], true)) {
    $status = 'active';
}

$locationCards = [];
if ($locationsReady) {
    $claimCountSql = db_column_exists('claims', 'standort_id')
        ? '(SELECT COUNT(*) FROM claims c WHERE c.standort_id = s.id)'
        : '0';
    $openClaimCountSql = db_column_exists('claims', 'standort_id')
        ? "(SELECT COUNT(*) FROM claims c WHERE c.standort_id = s.id AND c.status NOT IN ('closed','rejected','archived'))"
        : '0';
    $userCountSql = db_table_exists('user_standorte')
        ? '(SELECT COUNT(DISTINCT us.user_id) FROM user_standorte us JOIN users u2 ON u2.id = us.user_id WHERE us.standort_id = s.id)'
        : '0';
    $activeUserCountSql = db_table_exists('user_standorte')
        ? '(SELECT COUNT(DISTINCT us.user_id) FROM user_standorte us JOIN users u2 ON u2.id = us.user_id WHERE us.standort_id = s.id AND u2.active = 1)'
        : '0';
    $openActionCountSql = (db_column_exists('claims', 'standort_id') && db_table_exists('claim_actions'))
        ? "(SELECT COUNT(*) FROM claim_actions ca JOIN claims c ON c.id = ca.claim_id WHERE c.standort_id = s.id AND ca.status IN ('open','in_progress') AND c.status NOT IN ('closed','rejected','archived'))"
        : '0';

    $stmt = pdo()->query("SELECT s.*, 
            $claimCountSql AS claim_count,
            $openClaimCountSql AS open_claim_count,
            $userCountSql AS user_count,
            $activeUserCountSql AS active_user_count,
            $openActionCountSql AS open_action_count
        FROM standorte s
        ORDER BY s.aktiv DESC, s.name ASC");
    $locationCards = $stmt->fetchAll();
}

$users = [];
if (!$locationsReady || $selectedStandortRaw !== '') {
    $where = [];
    $params = [];

    if ($locationsReady) {
        if ($selectedStandortId > 0) {
            $where[] = 'us_filter.standort_id = ?';
            $params[] = $selectedStandortId;
        }
    }

    if ($status === 'active') {
        $where[] = $locationsReady ? 'u.active = 1' : 'active = 1';
    } elseif ($status === 'inactive') {
        $where[] = $locationsReady ? 'u.active = 0' : 'active = 0';
    }

    if ($q !== '') {
        $where[] = $locationsReady ? '(u.name LIKE ? OR u.email LIKE ?)' : '(name LIKE ? OR email LIKE ?)';
        $params[] = '%' . $q . '%';
        $params[] = '%' . $q . '%';
    }

    $sql = $locationsReady
        ? "SELECT u.*, GROUP_CONCAT(DISTINCT CONCAT(s.kuerzel, ' · ', s.name) ORDER BY s.name SEPARATOR ', ') AS standorte_liste
           FROM users u
           LEFT JOIN user_standorte us_filter ON us_filter.user_id = u.id
           LEFT JOIN user_standorte us ON us.user_id = u.id
           LEFT JOIN standorte s ON s.id = us.standort_id"
        : 'SELECT * FROM users';

    if ($where) {
        $sql .= ' WHERE ' . implode(' AND ', $where);
    }
    if ($locationsReady) {
        $sql .= ' GROUP BY u.id';
        $sql .= " ORDER BY u.active DESC, FIELD(u.role, 'admin','quality','employee','viewer'), u.name ASC";
    } else {
        $sql .= " ORDER BY active DESC, FIELD(role, 'admin','quality','employee','viewer'), name ASC";
    }

    $stmt = pdo()->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll();
}

$selectedParamForLinks = $selectedStandortId > 0 ? (string)$selectedStandortId : ($showAllLocations ? 'all' : '');
$userFormLink = 'user_form.php';
if ($selectedParamForLinks !== '') {
    $userFormLink .= '?standort_id=' . rawurlencode($selectedParamForLinks);
}

require __DIR__ . '/header.php';
?>

<div class="card page-hero users-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">
                    Administration<?= $locationsReady && $selectedStandortRaw !== '' ? ' · Benutzergruppe' : ' · Standorte' ?>
                </div>

                <?php if ($locationsReady && $selectedStandortRaw === ''): ?>
                    <h1 class="page-title display-6 fw-bold mb-2">Benutzerverwaltung nach Standort</h1>
                    <div class="page-subtitle">
                        Wähle zuerst einen Standort aus. Danach bearbeitest du nur die Benutzer dieses Standortes.
                    </div>
                <?php else: ?>
                    <h1 class="page-title display-6 fw-bold mb-2">
                        Benutzerverwaltung<?= $selectedStandort ? ' · ' . e($selectedStandort['kuerzel']) . ' ' . e($selectedStandort['name']) : ($showAllLocations ? ' · Alle Standorte' : '') ?>
                    </h1>
                    <div class="page-subtitle">
                        Benutzer anlegen, Rollen vergeben, Passwörter setzen und Zugänge deaktivieren.
                    </div>
                <?php endif; ?>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <?php if ($locationsReady && $selectedStandortRaw !== ''): ?>
                        <a href="users.php" class="btn btn-outline-secondary">← Standortauswahl</a>
                        <a href="<?= e($userFormLink) ?>" class="btn btn-primary">+ Benutzer anlegen</a>
                    <?php elseif (!$locationsReady): ?>
                        <a href="user_form.php" class="btn btn-primary">+ Benutzer anlegen</a>
                    <?php else: ?>
                        <a href="demo_seed_roles.php" class="btn btn-outline-success">Rollen-Demo anlegen</a>
                        <a href="locations.php" class="btn btn-outline-primary">Standorte verwalten</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($locationsReady && $selectedStandortRaw === ''): ?>
    <?php
        $totalUsers = (int)pdo()->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $totalClaims = db_table_exists('claims') ? (int)pdo()->query('SELECT COUNT(*) FROM claims')->fetchColumn() : 0;
    ?>

    <div class="card user-location-admin-panel mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold">Standort-Gruppen</div>
                <div class="small text-muted"><?= count($locationCards) ?> Standorte verfügbar</div>
            </div>
            <a href="locations.php" class="btn btn-sm btn-outline-secondary">Standort hinzufügen / bearbeiten</a>
        </div>

        <div class="card-body">
            <div class="user-location-toolbar mb-3">
                <input type="search" id="locationSearchInput" class="form-control user-location-search" placeholder="Standort suchen..." autocomplete="off">

                <div class="user-location-filter-group" role="group" aria-label="Standortfilter">
                    <button type="button" class="user-location-filter-btn active" data-location-filter="all">Alle</button>
                    <button type="button" class="user-location-filter-btn" data-location-filter="active">Aktiv</button>
                    <button type="button" class="user-location-filter-btn" data-location-filter="archived">Archiviert</button>
                </div>
            </div>

            <div class="user-location-list" id="userLocationList">
                <?php
                    $totalOpenActions = 0;
                    if (db_column_exists('claims', 'standort_id') && db_table_exists('claim_actions')) {
                        $totalOpenActions = (int)pdo()->query("SELECT COUNT(*)
                            FROM claim_actions ca
                            JOIN claims c ON c.id = ca.claim_id
                            WHERE ca.status IN ('open','in_progress')
                              AND c.status NOT IN ('closed','rejected','archived')")->fetchColumn();
                    }
                ?>

                <div class="user-location-row is-all" data-location-card data-location-fixed="1" data-location-status="all" data-location-search="alle standorte admin übersicht">
                    <div class="user-location-code">ALL</div>

                    <div class="user-location-main">
                        <div class="user-location-titleline">
                            <div class="user-location-name">Alle Standorte</div>
                            <span class="user-location-status-badge is-admin">Admin-Übersicht</span>
                        </div>

                        <div class="user-location-meta">Standortübergreifende Benutzer- und Rollenverwaltung</div>

                        <div class="user-location-stats">
                            <div class="user-location-stat">
                                <strong><?= (int)$totalUsers ?></strong>
                                <span>Benutzer</span>
                            </div>
                            <div class="user-location-stat">
                                <strong><?= (int)$totalClaims ?></strong>
                                <span>Reklamationen</span>
                            </div>
                            <div class="user-location-stat <?= $totalOpenActions > 0 ? 'is-danger' : '' ?>">
                                <strong><?= (int)$totalOpenActions ?></strong>
                                <span>offene Maßnahmen</span>
                            </div>
                        </div>
                    </div>

                    <div class="user-location-actions">
                        <a href="users.php?standort_id=all" class="btn btn-primary">Benutzer öffnen</a>
                    </div>
                </div>

                <?php foreach ($locationCards as $location): ?>
                    <?php
                        $isActive = (int)($location['aktiv'] ?? 1) === 1;
                        $claimCount = (int)($location['claim_count'] ?? 0);
                        $openClaimCount = (int)($location['open_claim_count'] ?? 0);
                        $userCount = (int)($location['user_count'] ?? 0);
                        $activeUserCount = (int)($location['active_user_count'] ?? 0);
                        $openActionCount = (int)($location['open_action_count'] ?? 0);
                        $locationSearch = strtolower(trim((string)($location['kuerzel'] ?? '') . ' ' . (string)($location['name'] ?? '') . ' ' . (string)($location['adresse'] ?? '')));
                    ?>
                    <div class="user-location-row <?= $isActive ? '' : 'is-archived' ?>"
                         data-location-card
                         data-location-status="<?= $isActive ? 'active' : 'archived' ?>"
                         data-location-search="<?= e($locationSearch) ?>">
                        <div class="user-location-code"><?= e(strtoupper(substr((string)$location['kuerzel'], 0, 3))) ?></div>

                        <div class="user-location-main">
                            <div class="user-location-titleline">
                                <div class="user-location-name"><?= e($location['name']) ?></div>
                                <span class="user-location-status-badge <?= $isActive ? 'is-active' : 'is-archived' ?>">
                                    <?= $isActive ? 'Aktiv' : 'Archiviert' ?>
                                </span>
                            </div>

                            <div class="user-location-meta">
                                Werk <?= e($location['name']) ?> · Benutzer und Reklamationen für diesen Standort
                            </div>

                            <div class="user-location-stats">
                                <div class="user-location-stat">
                                    <strong><?= $userCount ?></strong>
                                    <span>Benutzer</span>
                                </div>
                                <div class="user-location-stat">
                                    <strong><?= $claimCount ?></strong>
                                    <span>Reklamationen</span>
                                </div>
                                <div class="user-location-stat">
                                    <strong><?= $activeUserCount ?></strong>
                                    <span>aktiv</span>
                                </div>
                                <div class="user-location-stat <?= $openClaimCount > 0 ? 'is-warning' : '' ?>">
                                    <strong><?= $openClaimCount ?></strong>
                                    <span>offen</span>
                                </div>
                                <div class="user-location-stat <?= $openActionCount > 0 ? 'is-danger' : '' ?>">
                                    <strong><?= $openActionCount ?></strong>
                                    <span>Maßnahmen</span>
                                </div>
                            </div>
                        </div>

                        <div class="user-location-actions">
                            <a href="users.php?standort_id=<?= (int)$location['id'] ?>" class="btn btn-primary">Benutzer öffnen</a>
                            <a href="locations.php?edit=<?= (int)$location['id'] ?>" class="btn btn-outline-secondary">Standort bearbeiten</a>
                        </div>
                    </div>
                <?php endforeach; ?>

                <div class="user-location-empty" id="userLocationEmpty" hidden>
                    Kein Standort passt zu deiner Suche oder deinem Filter.
                </div>
            </div>
        </div>
    </div>

    <?php if (!$locationCards): ?>
        <div class="alert alert-warning">Noch keine Standorte vorhanden. Lege zuerst einen Standort an.</div>
    <?php endif; ?>
<?php else: ?>
    <?php if ($locationsReady): ?>
        <div class="card user-context-card location-context-card mb-4">
            <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
                <div>
                    <div class="text-muted small">Aktuelle Benutzergruppe</div>
                    <?php if ($selectedStandort): ?>
                        <div class="fw-bold fs-5"><?= e($selectedStandort['kuerzel']) ?> · <?= e($selectedStandort['name']) ?></div>
                        <div class="text-muted small"><?= e($selectedStandort['adresse'] ?: 'Keine Adresse hinterlegt') ?></div>
                    <?php else: ?>
                        <div class="fw-bold fs-5">Alle Standorte</div>
                        <div class="text-muted small">Standortübergreifende Admin-Ansicht</div>
                    <?php endif; ?>
                </div>
                <div class="d-flex flex-wrap gap-2">
                    <a href="users.php" class="btn btn-outline-secondary btn-sm">Andere Gruppe wählen</a>
                    <?php if ($selectedStandort): ?>
                        <a href="locations.php?edit=<?= (int)$selectedStandort['id'] ?>" class="btn btn-outline-primary btn-sm">Standort bearbeiten</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="card user-filter-card mb-4">
        <div class="card-body">
            <form class="row g-2 align-items-end" method="get">
                <?php if ($selectedParamForLinks !== ''): ?>
                    <input type="hidden" name="standort_id" value="<?= e($selectedParamForLinks) ?>">
                <?php endif; ?>
                <div class="col-md-6 col-lg-5">
                    <label class="form-label">Suche</label>
                    <input type="search" name="q" class="form-control" placeholder="Name oder E-Mail" value="<?= e($q) ?>">
                </div>
                <div class="col-md-4 col-lg-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Nur aktive</option>
                        <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Nur inaktive</option>
                        <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Alle</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button class="btn btn-outline-primary w-100">Filtern</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card user-table-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold">Benutzerliste</div>
                <div class="small text-muted"><?= count($users) ?> Einträge</div>
            </div>
            <a href="<?= e($userFormLink) ?>" class="btn btn-sm btn-primary no-print">+ Benutzer anlegen</a>
        </div>

        <!-- Desktop / Tablet groß -->
        <div class="table-responsive d-none d-lg-block">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>Name</th>
                    <th>E-Mail</th>
                    <th>Rolle</th>
                    <th>Status</th>
                    <th>Standorte</th>
                    <th>Erstellt</th>
                    <th class="text-end">Aktionen</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($users as $row): ?>
                    <?php
                        $editLink = 'user_form.php?id=' . (int)$row['id'];
                        $passwordLink = 'user_password.php?id=' . (int)$row['id'];
                        if ($selectedParamForLinks !== '') {
                            $editLink .= '&standort_id=' . rawurlencode($selectedParamForLinks);
                            $passwordLink .= '&standort_id=' . rawurlencode($selectedParamForLinks);
                        }
                    ?>
                    <tr>
                        <td class="fw-semibold"><?= e($row['name']) ?></td>
                        <td><?= e($row['email']) ?></td>
                        <td><?= role_badge($row['role']) ?></td>
                        <td>
                            <?php if ((int)$row['active'] === 1): ?>
                                <span class="badge bg-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td class="small"><?= e($row['standorte_liste'] ?? '-') ?></td>
                        <td class="text-muted small"><?= e($row['created_at'] ?? '-') ?></td>
                        <td class="text-end text-nowrap">
                            <div class="user-table-actions">
                                <a href="<?= e($editLink) ?>" class="btn btn-sm btn-outline-primary">Bearbeiten</a>
                                <a href="<?= e($passwordLink) ?>" class="btn btn-sm btn-outline-secondary">Passwort</a>
                                <?php if ((int)$row['id'] !== (int)current_user()['id']): ?>
                                    <form method="post" action="user_toggle_active.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                        <input type="hidden" name="active" value="<?= (int)$row['active'] === 1 ? '0' : '1' ?>">
                                        <?php if ($selectedParamForLinks !== ''): ?>
                                            <input type="hidden" name="return_standort_id" value="<?= e($selectedParamForLinks) ?>">
                                        <?php endif; ?>
                                        <?php if ((int)$row['active'] === 1): ?>
                                            <button class="btn btn-sm btn-outline-danger" data-confirm="Benutzer wirklich deaktivieren?">Deaktivieren</button>
                                        <?php else: ?>
                                            <button class="btn btn-sm btn-outline-success">Aktivieren</button>
                                        <?php endif; ?>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$users): ?>
                    <tr><td colspan="7" class="text-center text-muted py-4">Keine Benutzer gefunden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Smartphone / Tablet klein -->
        <div class="users-mobile-list d-lg-none">
            <?php foreach ($users as $row): ?>
                <?php
                    $editLink = 'user_form.php?id=' . (int)$row['id'];
                    $passwordLink = 'user_password.php?id=' . (int)$row['id'];
                    if ($selectedParamForLinks !== '') {
                        $editLink .= '&standort_id=' . rawurlencode($selectedParamForLinks);
                        $passwordLink .= '&standort_id=' . rawurlencode($selectedParamForLinks);
                    }
                ?>
                <div class="users-mobile-card <?= (int)$row['active'] === 1 ? '' : 'is-inactive' ?>">
                    <div class="users-mobile-top">
                        <div>
                            <div class="users-mobile-name"><?= e($row['name']) ?></div>
                            <div class="users-mobile-email"><?= e($row['email']) ?></div>
                        </div>
                        <div>
                            <?php if ((int)$row['active'] === 1): ?>
                                <span class="badge bg-success">Aktiv</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Inaktiv</span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="users-mobile-meta">
                        <div class="users-mobile-meta-row">
                            <span class="users-mobile-meta-label">Rolle:</span>
                            <span><?= role_badge($row['role']) ?></span>
                        </div>
                        <div class="users-mobile-meta-row">
                            <span class="users-mobile-meta-label">Standorte:</span>
                            <span><?= e($row['standorte_liste'] ?? '-') ?></span>
                        </div>
                        <div class="users-mobile-meta-row">
                            <span class="users-mobile-meta-label">Erstellt:</span>
                            <span><?= e($row['created_at'] ?? '-') ?></span>
                        </div>
                    </div>

                    <div class="users-mobile-buttons">
                        <a href="<?= e($editLink) ?>" class="btn btn-sm btn-outline-primary">Bearbeiten</a>
                        <a href="<?= e($passwordLink) ?>" class="btn btn-sm btn-outline-secondary">Passwort</a>
                        <?php if ((int)$row['id'] !== (int)current_user()['id']): ?>
                            <form method="post" action="user_toggle_active.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
                                <input type="hidden" name="active" value="<?= (int)$row['active'] === 1 ? '0' : '1' ?>">
                                <?php if ($selectedParamForLinks !== ''): ?>
                                    <input type="hidden" name="return_standort_id" value="<?= e($selectedParamForLinks) ?>">
                                <?php endif; ?>
                                <?php if ((int)$row['active'] === 1): ?>
                                    <button class="btn btn-sm btn-outline-danger" data-confirm="Benutzer wirklich deaktivieren?">Deaktivieren</button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-success">Aktivieren</button>
                                <?php endif; ?>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>

            <?php if (!$users): ?>
                <div class="empty-state">Keine Benutzer gefunden.</div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('locationSearchInput');
    const filterButtons = document.querySelectorAll('[data-location-filter]');
    const cards = document.querySelectorAll('[data-location-card]');
    const emptyState = document.getElementById('userLocationEmpty');

    if (!cards.length) {
        return;
    }

    let activeFilter = 'all';

    function normalize(value) {
        return (value || '').toString().toLowerCase().trim();
    }

    function applyLocationFilter() {
        const search = normalize(searchInput ? searchInput.value : '');
        let visibleCount = 0;

        cards.forEach(function (card) {
            const cardText = normalize(card.getAttribute('data-location-search'));
            const status = card.getAttribute('data-location-status') || 'active';
            const fixed = card.getAttribute('data-location-fixed') === '1';

            const matchesSearch = search === '' || cardText.indexOf(search) !== -1;
            const matchesFilter = activeFilter === 'all' || fixed || status === activeFilter;

            const visible = matchesSearch && matchesFilter;
            card.hidden = !visible;

            if (visible && !fixed) {
                visibleCount++;
            }
        });

        if (emptyState) {
            const anyVisible = Array.from(cards).some(function (card) {
                return !card.hidden;
            });
            emptyState.hidden = anyVisible;
        }
    }

    filterButtons.forEach(function (button) {
        button.addEventListener('click', function () {
            activeFilter = button.getAttribute('data-location-filter') || 'all';

            filterButtons.forEach(function (btn) {
                btn.classList.toggle('active', btn === button);
            });

            applyLocationFilter();
        });
    });

    if (searchInput) {
        searchInput.addEventListener('input', applyLocationFilter);
    }
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
