<?php
require_once __DIR__ . '/auth.php';
require_admin();

if (!db_table_exists('standorte')) {
    flash('warning', 'Die Standort-Tabellen sind noch nicht vorhanden. Bitte zuerst die Migration ausführen.');
    redirect('run_location_migration.php');
}

$editId = (int)($_GET['edit'] ?? 0);
$editLocation = null;
if ($editId > 0) {
    $stmt = pdo()->prepare('SELECT * FROM standorte WHERE id = ?');
    $stmt->execute([$editId]);
    $editLocation = $stmt->fetch();
}

$claimCountSql = db_column_exists('claims', 'standort_id')
    ? '(SELECT COUNT(*) FROM claims c WHERE c.standort_id = s.id)'
    : '0';
$userCountSql = db_table_exists('user_standorte')
    ? '(SELECT COUNT(*) FROM user_standorte us WHERE us.standort_id = s.id)'
    : '0';

$stmt = pdo()->query("SELECT s.*, $claimCountSql AS claim_count, $userCountSql AS user_count
    FROM standorte s
    ORDER BY s.aktiv DESC, s.name ASC");
$locations = $stmt->fetchAll();

require __DIR__ . '/header.php';
?>

<div class="card page-hero locations-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">Administration · Standorte</div>
                <h1 class="page-title display-6 fw-bold mb-2">Standorte</h1>
                <div class="page-subtitle">
                    Standorte wie Wunstorf, Hannover oder weitere Werke verwalten. Aktive Standorte können für neue Reklamationen ausgewählt werden.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a href="demo_seed_standorte.php" class="btn btn-outline-success">Standort-Demos</a>
                    <a href="run_location_migration.php" class="btn btn-outline-secondary">Migration prüfen</a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card location-notice-card mb-4">
    <div class="card-body d-flex gap-3 align-items-start">
        <div class="location-notice-icon">i</div>
        <div>
            <div class="fw-bold mb-1">Hinweis zur Standortverwaltung</div>
            <div class="text-muted">
                Standorte können gelöscht werden, solange keine Reklamationen damit verknüpft sind.
                Sobald Reklamationen vorhanden sind, sollte der Standort archiviert werden. Die Daten bleiben dann erhalten, aber der Standort ist nicht mehr auswählbar.
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-5">
        <div class="card location-form-card">
            <div class="card-header fw-bold"><?= $editLocation ? 'Standort bearbeiten' : 'Neuen Standort anlegen' ?></div>
            <div class="card-body">
                <form method="post" action="location_save.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)($editLocation['id'] ?? 0) ?>">

                    <div class="mb-3">
                        <label class="form-label">Name *</label>
                        <input name="name" class="form-control" required maxlength="100" value="<?= e($editLocation['name'] ?? '') ?>" placeholder="z. B. Wunstorf">
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Kürzel *</label>
                        <input name="kuerzel" class="form-control" required maxlength="20" value="<?= e($editLocation['kuerzel'] ?? '') ?>" placeholder="z. B. WUN">
                        <div class="form-text">Das Kürzel wird für neue Nummern genutzt, z. B. WUN-8D-2026-0001.</div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Adresse / Hinweis</label>
                        <input name="adresse" class="form-control" maxlength="255" value="<?= e($editLocation['adresse'] ?? '') ?>">
                    </div>

                    <div class="form-check form-switch mb-4">
                        <input class="form-check-input" type="checkbox" role="switch" name="aktiv" value="1" id="locActive" <?= (int)($editLocation['aktiv'] ?? 1) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label" for="locActive">Standort ist aktiv</label>
                        <div class="form-text">Wenn du den Schalter deaktivierst, ist der Standort archiviert und nicht mehr in der Standort-Auswahl sichtbar.</div>
                    </div>

                    <div class="d-flex flex-wrap justify-content-end gap-2">
                        <?php if ($editLocation): ?><a href="locations.php" class="btn btn-outline-secondary">Abbrechen</a><?php endif; ?>
                        <button class="btn btn-primary">Speichern</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-lg-7">
        <div class="card location-table-card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <div>
                    <div class="fw-bold">Vorhandene Standorte</div>
                    <div class="small text-muted"><?= count($locations) ?> Einträge</div>
                </div>
            </div>

            <!-- Desktop / Tablet groß -->
            <div class="table-responsive d-none d-lg-block">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Kürzel</th>
                            <th>Name</th>
                            <th>Adresse</th>
                            <th>Rekl.</th>
                            <th>Benutzer</th>
                            <th>Status</th>
                            <th class="text-end">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($locations as $location): ?>
                        <?php
                            $isActive = (int)$location['aktiv'] === 1;
                            $claimCount = (int)($location['claim_count'] ?? 0);
                            $userCount = (int)($location['user_count'] ?? 0);
                        ?>
                        <tr class="<?= $isActive ? '' : 'table-light text-muted' ?>">
                            <td><span class="badge bg-info-subtle text-info-emphasis border border-info-subtle"><?= e($location['kuerzel']) ?></span></td>
                            <td class="fw-semibold"><?= e($location['name']) ?></td>
                            <td><?= e($location['adresse'] ?: '-') ?></td>
                            <td><span class="badge bg-light text-dark border"><?= $claimCount ?></span></td>
                            <td><span class="badge bg-light text-dark border"><?= $userCount ?></span></td>
                            <td>
                                <?= $isActive
                                    ? '<span class="badge bg-success">Aktiv</span>'
                                    : '<span class="badge bg-secondary">Archiviert</span>' ?>
                            </td>
                            <td class="text-end">
                                <div class="location-action-buttons">
                                    <a class="btn btn-sm btn-outline-primary" href="locations.php?edit=<?= (int)$location['id'] ?>">Bearbeiten</a>

                                    <?php if ($isActive): ?>
                                        <form method="post" action="location_archive.php" onsubmit="return confirm('Standort wirklich archivieren? Er bleibt für bestehende Reklamationen erhalten, ist aber nicht mehr auswählbar.');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$location['id'] ?>">
                                            <input type="hidden" name="mode" value="archive">
                                            <button class="btn btn-sm btn-outline-secondary">Archivieren</button>
                                        </form>
                                    <?php else: ?>
                                        <form method="post" action="location_archive.php">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="id" value="<?= (int)$location['id'] ?>">
                                            <input type="hidden" name="mode" value="reactivate">
                                            <button class="btn btn-sm btn-outline-success">Aktivieren</button>
                                        </form>
                                    <?php endif; ?>

                                    <form method="post" action="location_delete.php" onsubmit="return confirm('Standort wirklich löschen? Das geht nur, wenn keine Reklamationen damit verknüpft sind.');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="id" value="<?= (int)$location['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" <?= $claimCount > 0 ? 'disabled title="Löschen nicht möglich: Reklamationen vorhanden"' : '' ?>>Löschen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$locations): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Noch keine Standorte vorhanden.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <!-- Smartphone / Tablet klein -->
            <div class="locations-mobile-list d-lg-none">
                <?php foreach ($locations as $location): ?>
                    <?php
                        $isActive = (int)$location['aktiv'] === 1;
                        $claimCount = (int)($location['claim_count'] ?? 0);
                        $userCount = (int)($location['user_count'] ?? 0);
                    ?>
                    <div class="locations-mobile-card <?= $isActive ? '' : 'is-archived' ?>">
                        <div class="locations-mobile-top">
                            <span class="locations-mobile-code"><?= e($location['kuerzel']) ?></span>
                            <div>
                                <?= $isActive
                                    ? '<span class="badge bg-success">Aktiv</span>'
                                    : '<span class="badge bg-secondary">Archiviert</span>' ?>
                            </div>
                        </div>

                        <div class="locations-mobile-title"><?= e($location['name']) ?></div>
                        <div class="locations-mobile-address"><?= e($location['adresse'] ?: 'Keine Adresse hinterlegt') ?></div>

                        <div class="locations-mobile-stats">
                            <div><strong><?= $claimCount ?></strong><span>Reklamationen</span></div>
                            <div><strong><?= $userCount ?></strong><span>Benutzer</span></div>
                        </div>

                        <div class="locations-mobile-buttons">
                            <a class="btn btn-sm btn-outline-primary" href="locations.php?edit=<?= (int)$location['id'] ?>">Bearbeiten</a>

                            <?php if ($isActive): ?>
                                <form method="post" action="location_archive.php" onsubmit="return confirm('Standort wirklich archivieren? Er bleibt für bestehende Reklamationen erhalten, ist aber nicht mehr auswählbar.');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$location['id'] ?>">
                                    <input type="hidden" name="mode" value="archive">
                                    <button class="btn btn-sm btn-outline-secondary">Archivieren</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="location_archive.php">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="id" value="<?= (int)$location['id'] ?>">
                                    <input type="hidden" name="mode" value="reactivate">
                                    <button class="btn btn-sm btn-outline-success">Aktivieren</button>
                                </form>
                            <?php endif; ?>

                            <form method="post" action="location_delete.php" onsubmit="return confirm('Standort wirklich löschen? Das geht nur, wenn keine Reklamationen damit verknüpft sind.');">
                                <?= csrf_field() ?>
                                <input type="hidden" name="id" value="<?= (int)$location['id'] ?>">
                                <button class="btn btn-sm btn-outline-danger" <?= $claimCount > 0 ? 'disabled title="Löschen nicht möglich: Reklamationen vorhanden"' : '' ?>>Löschen</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!$locations): ?>
                    <div class="empty-state">Noch keine Standorte vorhanden.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
