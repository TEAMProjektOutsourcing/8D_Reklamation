<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/claim_group_helper.php';
require_admin();

$db = pdo();

if (!claim_groups_enabled()) {
    require __DIR__ . '/header.php';
    ?>
    <div class="card page-hero groups-hero mb-4">
        <div class="card-body p-4 p-lg-5">
            <div class="row g-4 align-items-center">
                <div class="col-lg-8">
                    <div class="page-kicker mb-3">Administration · Gruppen</div>
                    <h1 class="page-title display-6 fw-bold mb-2">Gruppenverwaltung</h1>
                    <div class="page-subtitle">Gruppen für Reklamationen verwalten und später bei neuen 8D-Fällen zuweisen.</div>
                </div>

                <div class="col-lg-4">
                    <div class="page-actions">
                        <a href="run_claim_groups_migration.php" class="btn btn-warning">Gruppen-Migration ausführen</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="card groups-migration-card">
        <div class="card-body d-flex gap-3 align-items-start">
            <div class="groups-migration-icon">!</div>
            <div>
                <div class="fw-bold mb-1">Gruppentabellen fehlen</div>
                <div class="text-muted">Die Gruppentabellen wurden noch nicht gefunden. Bitte führe einmal die Migration aus.</div>
            </div>
        </div>
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$status = (string)($_GET['status'] ?? 'active');
$standortFilter = trim((string)($_GET['standort_id'] ?? ''));

if (!in_array($status, ['active','inactive','all'], true)) {
    $status = 'active';
}

$where = [];
$params = [];

if ($status === 'active') {
    $where[] = 'g.active = 1';
} elseif ($status === 'inactive') {
    $where[] = 'g.active = 0';
}

if ($q !== '') {
    $where[] = '(g.name LIKE ? OR g.description LIKE ?)';
    $params[] = '%' . $q . '%';
    $params[] = '%' . $q . '%';
}

if (locations_enabled() && $standortFilter !== '') {
    if ($standortFilter === 'global') {
        $where[] = 'g.standort_id IS NULL';
    } elseif (ctype_digit($standortFilter)) {
        $where[] = 'g.standort_id = ?';
        $params[] = (int)$standortFilter;
    }
}

$locationJoin = locations_enabled() ? 'LEFT JOIN standorte s ON s.id = g.standort_id' : '';
$locationSelect = locations_enabled() ? ', s.kuerzel AS standort_kuerzel, s.name AS standort_name' : ', NULL AS standort_kuerzel, NULL AS standort_name';
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$sql = "SELECT g.* {$locationSelect},
           (SELECT COUNT(*) FROM claim_group_members gm WHERE gm.group_id = g.id) AS member_count,
           (SELECT COUNT(*) FROM claim_group_assignments ga WHERE ga.group_id = g.id) AS claim_count
        FROM claim_groups g
        {$locationJoin}
        {$whereSql}
        ORDER BY g.active DESC, g.standort_id IS NULL DESC, g.name ASC";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$groups = $stmt->fetchAll();
$locations = locations_enabled() ? get_locations(false) : [];

require __DIR__ . '/header.php';
?>

<div class="card page-hero groups-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">Administration · Gruppen</div>
                <h1 class="page-title display-6 fw-bold mb-2">Gruppenverwaltung</h1>
                <div class="page-subtitle">
                    Gruppen anlegen, bearbeiten und Mitgliedern zuweisen. Diese Gruppen können bei neuen Reklamationen zusätzlich ausgewählt werden.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a href="claim_create.php" class="btn btn-outline-secondary">Neue Reklamation</a>
                    <a href="group_form.php" class="btn btn-primary">+ Gruppe anlegen</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card groups-filter-card mb-4">
    <div class="card-body">
        <form class="row g-2 align-items-end" method="get">
            <div class="col-lg-5">
                <label class="form-label">Suche</label>
                <input type="search" name="q" class="form-control" placeholder="Gruppe oder Beschreibung" value="<?= e($q) ?>">
            </div>

            <div class="col-md-6 col-lg-3">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>Nur aktive</option>
                    <option value="inactive" <?= $status === 'inactive' ? 'selected' : '' ?>>Nur inaktive</option>
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Alle</option>
                </select>
            </div>

            <?php if (locations_enabled()): ?>
                <div class="col-md-6 col-lg-3">
                    <label class="form-label">Standort</label>
                    <select name="standort_id" class="form-select">
                        <option value="">Alle Gruppen</option>
                        <option value="global" <?= $standortFilter === 'global' ? 'selected' : '' ?>>Global / alle Standorte</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int)$loc['id'] ?>" <?= $standortFilter === (string)$loc['id'] ? 'selected' : '' ?>><?= e($loc['kuerzel']) ?> · <?= e($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <div class="col-lg-1 d-grid">
                <button class="btn btn-outline-primary">Filtern</button>
            </div>
        </form>
    </div>
</div>

<div class="card groups-table-card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="fw-bold">Gruppenliste</div>
            <div class="small text-muted"><?= count($groups) ?> Einträge</div>
        </div>
        <a href="group_form.php" class="btn btn-sm btn-primary no-print">+ Gruppe anlegen</a>
    </div>

    <!-- Desktop / Tablet groß -->
    <div class="table-responsive d-none d-lg-block">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Gruppe</th>
                <th>Standort</th>
                <th>Beschreibung</th>
                <th>Mitglieder</th>
                <th>Automatik</th>
                <th>Reklamationen</th>
                <th>Status</th>
                <th class="text-end">Aktion</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($groups as $group): ?>
                <tr class="<?= (int)$group['active'] === 1 ? '' : 'table-light text-muted' ?>">
                    <td><?= claim_group_badge($group) ?><div class="small text-muted mt-1">ID <?= (int)$group['id'] ?></div></td>
                    <td>
                        <?php if (!empty($group['standort_id'])): ?>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><?= e($group['standort_kuerzel'] ?? '') ?> · <?= e($group['standort_name'] ?? '') ?></span>
                        <?php else: ?>
                            <span class="badge bg-dark">Global</span>
                        <?php endif; ?>
                    </td>
                    <td><?= e($group['description'] ?: '-') ?></td>
                    <td><strong><?= (int)$group['member_count'] ?></strong><div class="small text-muted"><?= e(claim_group_member_names((int)$group['id']) ?: '-') ?></div></td>
                    <td>
                        <div class="group-automation-badges">
                            <?= (int)($group['create_action_on_assign'] ?? 0) === 1 ? '<span class="badge bg-primary">Meine Maßnahmen</span>' : '' ?>
                            <?= (int)($group['notify_on_assign'] ?? 0) === 1 ? '<span class="badge bg-warning text-dark">E-Mail</span>' : '' ?>
                            <?= (int)($group['escalate_yellow'] ?? 0) === 1 ? '<span class="badge bg-warning text-dark">Gelb-Eskalation</span>' : '' ?>
                            <?= (int)($group['escalate_red'] ?? 0) === 1 ? '<span class="badge bg-danger">Rot-Eskalation</span>' : '' ?>
                            <?php if ((int)($group['create_action_on_assign'] ?? 0) !== 1 && (int)($group['notify_on_assign'] ?? 0) !== 1 && (int)($group['escalate_yellow'] ?? 0) !== 1 && (int)($group['escalate_red'] ?? 0) !== 1): ?>
                                <span class="text-muted small">Keine</span>
                            <?php endif; ?>
                        </div>
                        <div class="small text-muted">Frist: <?= (int)($group['default_due_days'] ?? 0) ?> Tag(e)</div>
                    </td>
                    <td><?= (int)$group['claim_count'] ?></td>
                    <td><?= (int)$group['active'] === 1 ? '<span class="badge bg-success">Aktiv</span>' : '<span class="badge bg-secondary">Inaktiv</span>' ?></td>
                    <td class="text-end">
                        <div class="group-action-buttons">
                            <a href="group_form.php?id=<?= (int)$group['id'] ?>" class="btn btn-sm btn-outline-primary">Bearbeiten</a>
                            <form method="post" action="group_delete.php">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$group['id'] ?>">
                                <?php if ((int)$group['active'] === 1): ?>
                                    <button class="btn btn-sm btn-outline-secondary" data-confirm="Gruppe wirklich deaktivieren?">Deaktivieren</button>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-success" name="reactivate" value="1">Aktivieren</button>
                                <?php endif; ?>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$groups): ?>
                <tr><td colspan="8" class="text-center text-muted py-4">Keine Gruppen gefunden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Smartphone / Tablet klein -->
    <div class="groups-mobile-list d-lg-none">
        <?php foreach ($groups as $group): ?>
            <div class="groups-mobile-card <?= (int)$group['active'] === 1 ? '' : 'is-inactive' ?>">
                <div class="groups-mobile-top">
                    <div>
                        <div class="groups-mobile-title"><?= claim_group_badge($group) ?></div>
                        <div class="groups-mobile-id">ID <?= (int)$group['id'] ?></div>
                    </div>
                    <div><?= (int)$group['active'] === 1 ? '<span class="badge bg-success">Aktiv</span>' : '<span class="badge bg-secondary">Inaktiv</span>' ?></div>
                </div>

                <div class="groups-mobile-description"><?= e($group['description'] ?: 'Keine Beschreibung') ?></div>

                <div class="groups-mobile-meta">
                    <div class="groups-mobile-meta-row">
                        <span class="groups-mobile-meta-label">Standort:</span>
                        <span>
                            <?php if (!empty($group['standort_id'])): ?>
                                <?= e($group['standort_kuerzel'] ?? '') ?> · <?= e($group['standort_name'] ?? '') ?>
                            <?php else: ?>
                                Global
                            <?php endif; ?>
                        </span>
                    </div>
                    <div class="groups-mobile-meta-row">
                        <span class="groups-mobile-meta-label">Mitglieder:</span>
                        <span><strong><?= (int)$group['member_count'] ?></strong> · <?= e(claim_group_member_names((int)$group['id']) ?: '-') ?></span>
                    </div>
                    <div class="groups-mobile-meta-row">
                        <span class="groups-mobile-meta-label">Reklam.:</span>
                        <span><?= (int)$group['claim_count'] ?></span>
                    </div>
                    <div class="groups-mobile-meta-row">
                        <span class="groups-mobile-meta-label">Frist:</span>
                        <span><?= (int)($group['default_due_days'] ?? 0) ?> Tag(e)</span>
                    </div>
                </div>

                <div class="groups-mobile-section">
                    <div class="small fw-bold text-muted mb-2">Automatik</div>
                    <div class="group-automation-badges">
                        <?= (int)($group['create_action_on_assign'] ?? 0) === 1 ? '<span class="badge bg-primary">Meine Maßnahmen</span>' : '' ?>
                        <?= (int)($group['notify_on_assign'] ?? 0) === 1 ? '<span class="badge bg-warning text-dark">E-Mail</span>' : '' ?>
                        <?= (int)($group['escalate_yellow'] ?? 0) === 1 ? '<span class="badge bg-warning text-dark">Gelb-Eskalation</span>' : '' ?>
                        <?= (int)($group['escalate_red'] ?? 0) === 1 ? '<span class="badge bg-danger">Rot-Eskalation</span>' : '' ?>
                        <?php if ((int)($group['create_action_on_assign'] ?? 0) !== 1 && (int)($group['notify_on_assign'] ?? 0) !== 1 && (int)($group['escalate_yellow'] ?? 0) !== 1 && (int)($group['escalate_red'] ?? 0) !== 1): ?>
                            <span class="text-muted small">Keine</span>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="groups-mobile-buttons">
                    <a href="group_form.php?id=<?= (int)$group['id'] ?>" class="btn btn-sm btn-outline-primary">Bearbeiten</a>
                    <form method="post" action="group_delete.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= (int)$group['id'] ?>">
                        <?php if ((int)$group['active'] === 1): ?>
                            <button class="btn btn-sm btn-outline-secondary" data-confirm="Gruppe wirklich deaktivieren?">Deaktivieren</button>
                        <?php else: ?>
                            <button class="btn btn-sm btn-outline-success" name="reactivate" value="1">Aktivieren</button>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$groups): ?>
            <div class="empty-state">Keine Gruppen gefunden.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
