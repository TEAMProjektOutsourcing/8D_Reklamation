<?php
require_once __DIR__ . '/auth.php';
require_login();

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
[$locationSql, $locationParams] = location_scope_condition('c');

$sql = "SELECT c.*, u.name AS responsible_name
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

$sql .= " ORDER BY c.created_at DESC";
$stmt = pdo()->prepare($sql);
$stmt->execute($params);
$claims = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>
<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1">Reklamationen</h1>
        <div class="text-muted">Alle 8D-Fälle im Überblick<?= locations_enabled() ? ' · Standort: ' . e(selected_location()['name'] ?? 'Alle Standorte') : '' ?>.</div>
    </div>
    <a href="claim_create.php" class="btn btn-primary">+ Neue Reklamation</a>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2">
            <div class="col-md-7">
                <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Suche nach Nr., Partner, Artikel oder Problem">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="">Alle Status</option>
                    <?php foreach (['new','in_progress','waiting','overdue','closed','rejected','archived'] as $s): ?>
                        <option value="<?= e($s) ?>" <?= $status === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-primary">Filtern</button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="table-responsive">
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
                <th>Datum</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($claims as $claim): ?>
                <tr>
                    <td><a class="fw-bold" href="claim_view.php?id=<?= (int)$claim['id'] ?>"><?= e($claim['claim_number']) ?></a></td>
                    <td><?= location_badge(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null) ?></td>
                    <td><?= e(status_label($claim['claim_type'])) ?></td>
                    <td><?= e($claim['partner_name']) ?></td>
                    <td><?= e($claim['article_number'] ?: '-') ?></td>
                    <td><?= e($claim['short_description']) ?></td>
                    <td><?= e(priority_label($claim['priority'])) ?></td>
                    <td><?= status_badge($claim['status']) ?></td>
                    <td><?= e($claim['responsible_name'] ?? '-') ?></td>
                    <td><?= e($claim['claim_date']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$claims): ?>
                <tr><td colspan="10" class="text-center text-muted py-4">Keine Reklamationen gefunden.</td></tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
