<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/claim_group_helper.php';
require_login();

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$groupFilter = trim((string)($_GET['group_id'] ?? ''));
$groupsReady = claim_groups_enabled();
$availableGroups = $groupsReady ? active_claim_groups_for_select(selected_location_id()) : [];
[$locationSql, $locationParams] = location_scope_condition('c');

$groupNamesSelect = $groupsReady
    ? "(SELECT GROUP_CONCAT(g.name ORDER BY g.name SEPARATOR ', ') FROM claim_group_assignments ga JOIN claim_groups g ON g.id = ga.group_id WHERE ga.claim_id = c.id)"
    : "''";

$sql = "SELECT c.*, u.name AS responsible_name, {$groupNamesSelect} AS group_names
        FROM claims c
        LEFT JOIN users u ON u.id = c.responsible_user_id
        WHERE 1=1" . $locationSql;
$params = $locationParams;

if ($q !== '') {
    $sql .= " AND (c.claim_number LIKE ? OR c.partner_name LIKE ? OR c.article_number LIKE ? OR c.short_description LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like);
}

if ($status !== '') {
    $sql .= " AND c.status = ?";
    $params[] = $status;
}

if ($groupFilter !== '' && ctype_digit($groupFilter) && claim_groups_enabled()) {
    $sql .= " AND EXISTS (SELECT 1 FROM claim_group_assignments ga_filter WHERE ga_filter.claim_id = c.id AND ga_filter.group_id = ?)";
    $params[] = (int)$groupFilter;
}

$sql .= " ORDER BY c.created_at DESC";
$stmt = pdo()->prepare($sql);
$stmt->execute($params);
$claims = $stmt->fetchAll();

$activeFilterCount = 0;
if ($q !== '') $activeFilterCount++;
if ($status !== '') $activeFilterCount++;
if ($groupFilter !== '') $activeFilterCount++;

require __DIR__ . '/header.php';
?>

<div class="card page-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">
                    Übersicht<?= locations_enabled() ? ' · ' . e(selected_location()['name'] ?? 'Alle Standorte') : '' ?>
                </div>
                <h1 class="page-title display-6 fw-bold mb-2">Reklamationen</h1>
                <div class="page-subtitle">
                    Alle 8D-Fälle im Überblick. Suche gezielt nach Nummer, Partner, Artikel oder Problem und filtere nach Status oder Gruppe.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a href="claims.php" class="btn btn-outline-primary">Alle anzeigen</a>
                    <?php if (can_edit()): ?>
                        <a href="claim_create.php" class="btn btn-primary">+ Neue Reklamation</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card filter-card mb-4">
    <div class="card-body">
        <form class="row g-2 align-items-end">
            <div class="col-lg-5">
                <label class="form-label small text-muted fw-semibold">Suche</label>
                <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Nr., Partner, Artikel oder Problem">
            </div>
            <div class="col-md-6 col-lg-3">
                <label class="form-label small text-muted fw-semibold">Status</label>
                <select class="form-select" name="status">
                    <option value="">Alle Status</option>
                    <?php foreach (['new','in_progress','waiting','overdue','closed','rejected','archived'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 col-lg-2">
                <label class="form-label small text-muted fw-semibold">Gruppe</label>
                <select class="form-select" name="group_id">
                    <option value="">Alle Gruppen</option>
                    <?php foreach ($availableGroups as $group): ?>
                        <option value="<?= (int)$group['id'] ?>" <?= $groupFilter === (string)$group['id'] ? 'selected' : '' ?>><?= e($group['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-2 d-grid">
                <button class="btn btn-outline-primary">Filtern</button>
            </div>
        </form>

        <?php if ($activeFilterCount > 0): ?>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <span class="badge text-bg-light"><?= (int)$activeFilterCount ?> Filter aktiv</span>
                <?php if ($q !== ''): ?><span class="badge text-bg-primary">Suche: <?= e($q) ?></span><?php endif; ?>
                <?php if ($status !== ''): ?><span class="badge text-bg-secondary">Status: <?= e(status_label($status)) ?></span><?php endif; ?>
                <?php if ($groupFilter !== ''): ?><span class="badge text-bg-info">Gruppe gefiltert</span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card claims-table-card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <div class="fw-bold">Gefundene Reklamationen</div>
            <div class="small text-muted"><?= count($claims) ?> Einträge</div>
        </div>
        <a href="claim_create.php" class="btn btn-sm btn-primary no-print">+ Neue Reklamation</a>
    </div>

    <!-- Desktop / Tablet groß -->
    <div class="table-responsive d-none d-lg-block">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
            <tr>
                <th>Nr.</th>
                <th>Standort</th>
                <th>Art</th>
                <th>Partner</th>
                <th>Artikel</th>
                <th>Problem</th>
                <th>Priorität</th>
                <th>Status</th>
                <th>Verantwortlich</th>
                <th>Gruppen</th>
                <th>Datum</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($claims as $claim): ?>
                <tr>
                    <td><a class="fw-bold text-decoration-none" href="claim_view.php?id=<?= (int)$claim['id'] ?>"><?= e($claim['claim_number']) ?></a></td>
                    <td><?= location_badge(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null) ?></td>
                    <td><?= e(status_label($claim['claim_type'])) ?></td>
                    <td><?= e($claim['partner_name']) ?></td>
                    <td><?= e($claim['article_number'] ?: '-') ?></td>
                    <td><?= e($claim['short_description']) ?></td>
                    <td><?= e(priority_label($claim['priority'])) ?></td>
                    <td><?= status_badge($claim['status']) ?></td>
                    <td><?= e($claim['responsible_name'] ?? '-') ?></td>
                    <td><?= e($claim['group_names'] ?? '-') ?></td>
                    <td><?= e($claim['claim_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$claims): ?>
                <tr><td colspan="11" class="text-center text-muted py-4">Keine Reklamationen gefunden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Smartphone / Tablet klein -->
    <div class="claims-mobile-list d-lg-none">
        <?php foreach ($claims as $claim): ?>
            <a href="claim_view.php?id=<?= (int)$claim['id'] ?>" class="claims-mobile-card">
                <div class="claims-mobile-top">
                    <div class="claims-mobile-number"><?= e($claim['claim_number']) ?></div>
                    <div><?= status_badge($claim['status']) ?></div>
                </div>

                <div class="claims-mobile-title"><?= e($claim['short_description']) ?></div>

                <div class="claims-mobile-meta">
                    <div class="claims-mobile-meta-row">
                        <span class="claims-mobile-meta-label">Partner:</span>
                        <span><?= e($claim['partner_name']) ?></span>
                    </div>
                    <div class="claims-mobile-meta-row">
                        <span class="claims-mobile-meta-label">Artikel:</span>
                        <span><?= e($claim['article_number'] ?: '-') ?></span>
                    </div>
                    <div class="claims-mobile-meta-row">
                        <span class="claims-mobile-meta-label">Art:</span>
                        <span><?= e(status_label($claim['claim_type'])) ?></span>
                    </div>
                    <div class="claims-mobile-meta-row">
                        <span class="claims-mobile-meta-label">Verantw.:</span>
                        <span><?= e($claim['responsible_name'] ?? '-') ?></span>
                    </div>
                    <div class="claims-mobile-meta-row">
                        <span class="claims-mobile-meta-label">Datum:</span>
                        <span><?= e($claim['claim_date']) ?></span>
                    </div>
                    <?php if (!empty($claim['group_names'])): ?>
                        <div class="claims-mobile-meta-row">
                            <span class="claims-mobile-meta-label">Gruppen:</span>
                            <span><?= e($claim['group_names']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <div class="claims-mobile-footer">
                    <?= location_badge(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null) ?>
                    <span class="badge bg-dark"><?= e(priority_label($claim['priority'])) ?></span>
                    <span class="badge text-bg-light">Öffnen</span>
                </div>
            </a>
        <?php endforeach; ?>

        <?php if (!$claims): ?>
            <div class="empty-state">Keine Reklamationen gefunden.</div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
