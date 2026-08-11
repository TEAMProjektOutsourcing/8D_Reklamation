<?php
require_once __DIR__ . '/auth.php';
require_login();

if (!can_edit()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$selectedLocationId = selected_location_id();
$locations = user_allowed_locations((int)current_user()['id']);
$users = get_users_for_select($selectedLocationId);
require __DIR__ . '/header.php';
?>
<div class="mb-4">
    <h1 class="h3 fw-bold mb-1">Neue Reklamation erstellen</h1>
    <div class="text-muted">Stammdaten erfassen, danach werden D1 bis D8 automatisch angelegt.</div>
</div>

<form method="post" action="claim_store.php" class="card">
    <div class="card-body">
        <?= csrf_field() ?>
        <div class="row g-3">
            <?php if (locations_enabled()): ?>
                <div class="col-md-3">
                    <label class="form-label">Standort *</label>
                    <?php if (count($locations) > 1 || is_admin()): ?>
                        <select name="standort_id" class="form-select" required>
                            <?php foreach ($locations as $loc): ?>
                                <option value="<?= (int)$loc['id'] ?>" <?= $selectedLocationId === (int)$loc['id'] ? 'selected' : '' ?>><?= e($loc['kuerzel']) ?> · <?= e($loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php else: ?>
                        <input type="hidden" name="standort_id" value="<?= (int)($locations[0]['id'] ?? 0) ?>">
                        <div class="form-control bg-light"><?= e(($locations[0]['kuerzel'] ?? '') . ' · ' . ($locations[0]['name'] ?? '')) ?></div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            <div class="col-md-3">
                <label class="form-label">Art *</label>
                <select name="claim_type" class="form-select" required>
                    <option value="customer">Kundenreklamation</option>
                    <option value="supplier">Lieferantenreklamation</option>
                    <option value="internal">Interne Reklamation</option>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label">Kunde / Lieferant / Bereich *</label>
                <input name="partner_name" class="form-control" required placeholder="z. B. VW, Lieferant Müller, Lager intern">
            </div>
            <div class="col-md-2">
                <label class="form-label">Reklamationsdatum *</label>
                <input type="date" name="claim_date" class="form-control" required value="<?= e(date('Y-m-d')) ?>">
            </div>
            <div class="col-md-2">
                <label class="form-label">Priorität *</label>
                <select name="priority" class="form-select" required>
                    <option value="low">Niedrig</option>
                    <option value="medium" selected>Mittel</option>
                    <option value="high">Hoch</option>
                    <option value="critical">Kritisch</option>
                </select>
            </div>

            <div class="col-md-3">
                <label class="form-label">Artikelnummer</label>
                <input name="article_number" class="form-control">
            </div>
            <div class="col-md-4">
                <label class="form-label">Artikelbezeichnung</label>
                <input name="article_name" class="form-control">
            </div>
            <div class="col-md-2">
                <label class="form-label">Menge betroffen</label>
                <input type="number" step="0.01" name="quantity_affected" class="form-control">
            </div>
            <div class="col-md-3">
                <label class="form-label">Lieferdatum</label>
                <input type="date" name="delivery_date" class="form-control">
            </div>

            <div class="col-md-3">
                <label class="form-label">Quelle / Modul</label>
                <select name="source_module" class="form-select">
                    <option value="">Keine Quelle</option>
                    <option value="warenausgang">Warenausgang</option>
                    <option value="wareneingang">Wareneingang</option>
                    <option value="kommi">Kommi</option>
                    <option value="cmr">CMR</option>
                    <option value="urlaub">Urlaub</option>
                    <option value="mitarbeiter">Mitarbeiter</option>
                    <option value="sonstiges">Sonstiges</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">Quellnummer</label>
                <input name="source_number" class="form-control" placeholder="z. B. WA-2828">
            </div>
            <div class="col-md-6">
                <label class="form-label">Quell-Link optional</label>
                <input name="source_url" class="form-control" placeholder="z. B. Link zur Workbench-Seite">
            </div>

            <div class="col-md-8">
                <label class="form-label">Kurzbeschreibung *</label>
                <input name="short_description" class="form-control" required maxlength="255" placeholder="z. B. Ware beschädigt angekommen">
            </div>
            <div class="col-md-4">
                <label class="form-label">Verantwortlich</label>
                <select name="responsible_user_id" class="form-select">
                    <option value="">-- bitte wählen --</option>
                    <?php foreach ($users as $user): ?>
                        <option value="<?= (int)$user['id'] ?>"><?= e($user['name']) ?> (<?= e($user['role']) ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-12">
                <label class="form-label">Problembeschreibung</label>
                <textarea name="problem_description" rows="5" class="form-control" placeholder="Was ist passiert? Wo? Wann? Wie viele Teile sind betroffen? Welche Nachweise gibt es?"></textarea>
            </div>
        </div>
    </div>
    <div class="card-footer bg-white d-flex justify-content-between">
        <a href="claims.php" class="btn btn-outline-secondary">Abbrechen</a>
        <button class="btn btn-primary">Reklamation erstellen</button>
    </div>
</form>
<?php require __DIR__ . '/footer.php'; ?>
