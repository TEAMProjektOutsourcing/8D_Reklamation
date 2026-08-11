<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/claim_group_helper.php';
require_admin();

if (!claim_groups_enabled()) {
    flash('warning', 'Bitte zuerst die Gruppen-Migration ausführen.');
    redirect('run_claim_groups_migration.php');
}

$db = pdo();
$id = (int)($_GET['id'] ?? 0);
$group = null;

if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM claim_groups WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);
    $group = $stmt->fetch();
    if (!$group) {
        flash('danger', 'Gruppe wurde nicht gefunden.');
        redirect('groups.php');
    }
}

$locations = locations_enabled() ? get_locations(true) : [];
$users = locations_enabled()
    ? pdo()->query("SELECT id, name, email, role FROM users WHERE active = 1 ORDER BY name ASC")->fetchAll()
    : pdo()->query("SELECT id, name, email, role FROM users WHERE active = 1 ORDER BY name ASC")->fetchAll();
$memberIds = $group ? claim_group_user_ids((int)$group['id']) : [];
$colors = claim_group_colors();

require __DIR__ . '/header.php';
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
    <div>
        <h1 class="h3 fw-bold mb-1"><?= $group ? 'Gruppe bearbeiten' : 'Gruppe anlegen' ?></h1>
        <div class="text-muted">Gruppen können später bei neuen Reklamationen zusätzlich zum Verantwortlichen ausgewählt werden.</div>
    </div>
    <a href="groups.php" class="btn btn-outline-secondary">Zurück</a>
</div>

<form method="post" action="group_save.php" class="card border-0 shadow-sm">
    <div class="card-body">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)($group['id'] ?? 0) ?>">

        <div class="row g-3">
            <div class="col-md-5">
                <label class="form-label">Gruppenname *</label>
                <input name="name" class="form-control" required maxlength="120" value="<?= e((string)($group['name'] ?? '')) ?>" placeholder="z. B. Qualität, Logistik, Management">
            </div>
            <div class="col-md-3">
                <label class="form-label">Farbe</label>
                <select name="color" class="form-select">
                    <?php foreach ($colors as $value => $label): ?>
                        <option value="<?= e($value) ?>" <?= (string)($group['color'] ?? 'secondary') === $value ? 'selected' : '' ?>><?= e($label) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php if (locations_enabled()): ?>
                <div class="col-md-4">
                    <label class="form-label">Standort</label>
                    <select name="standort_id" class="form-select">
                        <option value="">Global / alle Standorte</option>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= (int)$loc['id'] ?>" <?= (int)($group['standort_id'] ?? 0) === (int)$loc['id'] ? 'selected' : '' ?>><?= e($loc['kuerzel']) ?> · <?= e($loc['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Globale Gruppen sind bei allen Standorten auswählbar.</div>
                </div>
            <?php endif; ?>
            <div class="col-12">
                <label class="form-label">Beschreibung</label>
                <textarea name="description" class="form-control" rows="3" placeholder="Wofür ist diese Gruppe zuständig?"><?= e((string)($group['description'] ?? '')) ?></textarea>
            </div>
            <div class="col-md-3">
                <div class="form-check form-switch mt-2">
                    <input class="form-check-input" type="checkbox" name="active" value="1" id="activeSwitch" <?= (int)($group['active'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label" for="activeSwitch">Gruppe aktiv</label>
                </div>
            </div>
        </div>

        <hr>

        <div class="mb-3">
            <h2 class="h5 mb-1">Automatik bei neuer Reklamation</h2>
            <div class="text-muted small">Wenn diese Gruppe bei einer neuen Reklamation gewählt wird, können Mitglieder automatisch informiert werden oder eine Aufgabe in „Meine Maßnahmen“ bekommen.</div>
        </div>

        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <div class="form-check form-switch border rounded p-3 h-100 bg-white">
                    <input class="form-check-input ms-0 me-2" type="checkbox" name="create_action_on_assign" value="1" id="actionAutoSwitch" <?= (int)($group['create_action_on_assign'] ?? 1) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="actionAutoSwitch">Maßnahme für Mitglieder anlegen</label>
                    <div class="small text-muted mt-1">Jedes Gruppenmitglied bekommt automatisch eine offene Aufgabe in „Meine Maßnahmen“.</div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="form-check form-switch border rounded p-3 h-100 bg-white">
                    <input class="form-check-input ms-0 me-2" type="checkbox" name="notify_on_assign" value="1" id="mailAutoSwitch" <?= (int)($group['notify_on_assign'] ?? 0) === 1 ? 'checked' : '' ?>>
                    <label class="form-check-label fw-semibold" for="mailAutoSwitch">Mitglieder per E-Mail informieren</label>
                    <div class="small text-muted mt-1">Es wird eine E-Mail mit Link zur Reklamation gesendet, wenn eine gültige E-Mail hinterlegt ist.</div>
                </div>
            </div>
            <div class="col-md-4">
                <label class="form-label fw-semibold">Frist für automatische Maßnahme</label>
                <div class="input-group">
                    <input type="number" min="0" max="365" name="default_due_days" class="form-control" value="<?= (int)($group['default_due_days'] ?? 2) ?>">
                    <span class="input-group-text">Tage</span>
                </div>
                <div class="form-text">0 = keine Frist. Standard: 2 Tage.</div>
            </div>
        </div>

        <div class="border rounded p-3 bg-light mb-3">
            <div class="mb-3">
                <h2 class="h5 mb-1">Ampel-Eskalation bei offenen Maßnahmen</h2>
                <div class="text-muted small">
                    Hier legst du fest, welche Gruppe automatisch informiert wird, wenn offene Maßnahmen nicht bearbeitet werden.
                    Gelb = Tag 6 bis 10. Rot = ab Tag 11 oder Frist überschritten.
                </div>
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <div class="form-check form-switch border rounded p-3 h-100 bg-white">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="escalate_yellow" value="1" id="escalateYellowSwitch" <?= (int)($group['escalate_yellow'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="escalateYellowSwitch">Diese Gruppe bei GELB informieren</label>
                        <div class="small text-muted mt-1">
                            Beispiel: Gruppe „Management“. Diese Gruppe bekommt eine E-Mail, wenn eine offene Maßnahme Tag 6–10 erreicht.
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="form-check form-switch border rounded p-3 h-100 bg-white">
                        <input class="form-check-input ms-0 me-2" type="checkbox" name="escalate_red" value="1" id="escalateRedSwitch" <?= (int)($group['escalate_red'] ?? 0) === 1 ? 'checked' : '' ?>>
                        <label class="form-check-label fw-semibold" for="escalateRedSwitch">Diese Gruppe bei ROT informieren</label>
                        <div class="small text-muted mt-1">
                            Beispiel: Gruppe „Geschäftsleitung“. Bei Rot werden zusätzlich auch Gruppen mit GELB-Eskalation informiert.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <hr>

        <div class="d-flex justify-content-between align-items-center mb-2">
            <div>
                <h2 class="h5 mb-1">Mitglieder</h2>
                <div class="text-muted small">Optional. Die Gruppe kann auch ohne Mitglieder als organisatorische Markierung genutzt werden.</div>
            </div>
        </div>

        <div class="row g-2">
            <?php foreach ($users as $u): ?>
                <div class="col-md-4 col-xl-3">
                    <label class="border rounded p-2 d-flex gap-2 align-items-start h-100 bg-white">
                        <input type="checkbox" class="form-check-input mt-1" name="member_ids[]" value="<?= (int)$u['id'] ?>" <?= in_array((int)$u['id'], $memberIds, true) ? 'checked' : '' ?>>
                        <span>
                            <strong><?= e($u['name']) ?></strong>
                            <span class="d-block small text-muted"><?= e(role_label((string)$u['role'])) ?><?= !empty($u['email']) ? ' · ' . e($u['email']) : '' ?></span>
                        </span>
                    </label>
                </div>
            <?php endforeach; ?>
            <?php if (!$users): ?>
                <div class="col-12 text-muted">Keine aktiven Benutzer vorhanden.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between">
        <a href="groups.php" class="btn btn-outline-secondary">Abbrechen</a>
        <button class="btn btn-primary">Speichern</button>
    </div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
