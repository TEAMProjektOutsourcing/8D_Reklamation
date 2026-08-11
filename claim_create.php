<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/claim_group_helper.php';
require_once __DIR__ . '/qm_helper.php';
require_login();

if (!can_edit()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$selectedLocationId = selected_location_id();
$locations = user_allowed_locations((int)current_user()['id']);
$users = get_users_for_select($selectedLocationId);

$groups = active_claim_groups_for_select($selectedLocationId);


function claim_group_normalized_name(array $group): string
{
    $name = trim((string)($group['name'] ?? ''));

    return function_exists('mb_strtolower')
        ? mb_strtolower($name, 'UTF-8')
        : strtolower($name);
}

function claim_group_canonical_key(array $group): string
{
    $name = claim_group_normalized_name($group);

    /*
     * „Verwaltung“ ist die aktuelle Bezeichnung.
     * „Logistik“ bleibt als Altbezeichnung kompatibel, damit ältere oder
     * doppelte Datensätze weiterhin korrekt zusammengeführt werden.
     */
    if (
        strpos($name, 'verwaltung') !== false
        || strpos($name, 'logistik') !== false
    ) {
        return 'verwaltung';
    }

    if (
        strpos($name, 'qualität') !== false
        || strpos($name, 'qualitaet') !== false
        || strpos($name, 'quality') !== false
    ) {
        return 'qualitaet';
    }

    if (
        strpos($name, 'verkauf') !== false
        || strpos($name, 'vertrieb') !== false
        || strpos($name, 'sales') !== false
    ) {
        return 'verkauf';
    }

    if (
        strpos($name, 'management') !== false
        || strpos($name, 'managment') !== false
    ) {
        return 'management';
    }

    return $name;
}

function claim_group_dedupe_for_select(array $groups): array
{
    $out = [];

    foreach ($groups as $group) {
        $key = claim_group_canonical_key($group);

        if ($key === '') {
            $key = 'group_' . (int)($group['id'] ?? 0);
        }

        if (!isset($out[$key])) {
            $out[$key] = $group;
            continue;
        }

        $current = $out[$key];

        $groupHasLocation = !empty($group['standort_id']);
        $currentHasLocation = !empty($current['standort_id']);

        // Wenn globale und Standortgruppe doppelt existieren, bevorzugen wir die Standortgruppe.
        if ($groupHasLocation && !$currentHasLocation) {
            $out[$key] = $group;
            continue;
        }

        // Falls beide gleich sind, behalten wir die ältere ID.
        if ((int)($group['id'] ?? 0) < (int)($current['id'] ?? PHP_INT_MAX)) {
            $out[$key] = $group;
        }
    }

    /*
     * Gewünschte fachliche Reihenfolge.
     * Verwaltung ersetzt die frühere Bezeichnung Logistik.
     */
    $order = [
        'verwaltung' => 10,
        'qualitaet' => 20,
        'verkauf' => 30,
        'management' => 40,
    ];

    uasort($out, static function (array $a, array $b) use ($order): int {
        $ak = claim_group_canonical_key($a);
        $bk = claim_group_canonical_key($b);

        $ao = $order[$ak] ?? 999;
        $bo = $order[$bk] ?? 999;

        if ($ao === $bo) {
            return strcasecmp(
                (string)($a['name'] ?? ''),
                (string)($b['name'] ?? '')
            );
        }

        return $ao <=> $bo;
    });

    return array_values($out);
}

function claim_group_owner_label(array $group): string
{
    $description = trim((string)($group['description'] ?? ''));

    /*
     * Die DB-Beschreibung ist die führende Quelle.
     * Damit können Leitung oder Ansprechpartner später direkt in der
     * Gruppenverwaltung geändert werden, ohne diese PHP-Datei anzupassen.
     */
    if ($description !== '') {
        return $description;
    }

    /*
     * Nur als Rückfall, falls bei einer Gruppe keine Beschreibung gepflegt ist.
     */
    return match (claim_group_canonical_key($group)) {
        'verwaltung' => 'Operations Leitung · Manuel Edel',
        'qualitaet' => 'Moritz Maucher',
        'verkauf' => 'Marvin Maier · Rachid Kadi',
        'management' => 'Christian Besier · Andreas Klug',
        default => !empty($group['standort_id'])
            ? 'Standortgruppe'
            : 'Globale Gruppe',
    };
}

function claim_group_is_mandatory_quality(array $group): bool
{
    return claim_group_canonical_key($group) === 'qualitaet';
}

$groups = claim_group_dedupe_for_select($groups);

require __DIR__ . '/header.php';
?>

<div class="card page-hero claim-create-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">
                    Neuer 8D-Fall<?= locations_enabled() ? ' · ' . e(selected_location()['name'] ?? 'Alle Standorte') : '' ?>
                </div>
                <h1 class="page-title display-6 fw-bold mb-2">Neue Reklamation erstellen</h1>
                <div class="page-subtitle">
                    Erfasse zuerst die Stammdaten. Danach werden D1 bis D8 automatisch angelegt und der Fall kann strukturiert bearbeitet werden.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a href="claims.php" class="btn btn-outline-primary">Zurück zur Übersicht</a>
                </div>
            </div>
        </div>
    </div>
</div>

<form method="post" action="claim_store.php" class="card claim-create-form" id="claimCreateForm">
    <div class="card-body">
        <?= csrf_field() ?>

        <div class="claim-form-section">
            <div class="claim-form-section-title">
                <span class="section-icon">1</span>
                <span>Stammdaten</span>
            </div>

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
                            <div class="form-control bg-light readonly-location"><?= e(($locations[0]['kuerzel'] ?? '') . ' · ' . ($locations[0]['name'] ?? '')) ?></div>
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

                <div class="col-md-3">
                    <label class="form-label">Bezug *</label>
                    <select name="case_reference_type" id="caseReferenceType" class="form-select" required>
                        <option value="article" selected>Artikel / Material</option>
                        <option value="incident">Vorfall / Prozess</option>
                    </select>
                    <div class="form-text">Bei Vorfall werden keine Artikeldaten abgefragt.</div>
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
                    <select name="priority" id="claimPrioritySelect" class="form-select" required>
                        <option value="low" data-processing="10 Tage (2 Arbeitswochen)">Niedrig · 10 Tage</option>
                        <option value="medium" data-processing="7 Tage" selected>Mittel · 7 Tage</option>
                        <option value="high" data-processing="5 Tage">Hoch · 5 Tage</option>
                        <option value="critical" data-processing="2 Tage">Kritisch · 2 Tage</option>
                    </select>
                    <div class="form-text priority-processing-hint" id="priorityProcessingHint">
                        Bearbeitungszeitraum: 7 Tage
                    </div>
                </div>
            </div>
        </div>

        <div class="claim-form-section" id="articleSection" data-reference-section="article">
            <div class="claim-form-section-title">
                <span class="section-icon">2</span>
                <span>Artikel & Menge</span>
            </div>
            <div class="claim-form-section-subtitle">Nur ausfüllen, wenn die Reklamation artikel- oder materialbezogen ist.</div>

            <div class="row g-3">
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
            </div>
        </div>


        <div class="claim-form-section d-none" id="incidentSection" data-reference-section="incident">
            <div class="claim-form-section-title">
                <span class="section-icon">2</span>
                <span>Vorfall / Prozess</span>
            </div>
            <div class="claim-form-section-subtitle">
                Für Vorfälle ohne direkten Artikelbezug. Die genaue Beschreibung trägst du unten bei „Vorfallbeschreibung“ ein.
            </div>

            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label">Vorfallbereich</label>
                    <input name="incident_area" class="form-control" maxlength="120" placeholder="z. B. Lager, Warenausgang, Transport, Dokumentation">
                </div>

                <div class="col-md-6">
                    <label class="form-label">Zeitpunkt / Bezug</label>
                    <input name="incident_reference" class="form-control" maxlength="120" placeholder="z. B. Schicht, Tour, Prozess, Vorgang">
                </div>

                <div class="col-12">
                    <label class="form-label">Problem / Vorfall beschreiben *</label>
                    <textarea name="problem_description" id="incidentProblemDescription" rows="5" class="form-control"
                        placeholder="Was ist genau passiert? Wo und wann? Wer oder welcher Prozess war betroffen? Welche Auswirkung hatte der Vorfall?"></textarea>
                    <div class="form-text">Bitte den Vorfall so beschreiben, dass er auch ohne Artikelnummer nachvollziehbar ist.</div>
                </div>
            </div>
        </div>

        <div class="claim-form-section">
            <div class="claim-form-section-title">
                <span class="section-icon">3</span>
                <span>Quelle / Bezug</span>
            </div>
            <div class="claim-form-section-subtitle" id="sourceOtherNotice">
                Wenn die Reklamation nicht aus der Workbench kommt, wähle „Sonstiges“ und trage die Quelle manuell ein.
            </div>

            <div class="row g-3">
                <div class="col-md-3">
                    <label class="form-label">Quelle / Modul</label>
                    <select name="source_module" id="sourceModuleSelect" class="form-select">
                        <option value="">Keine Quelle</option>
                        <option value="warenausgang">Warenausgang</option>
                        <option value="wareneingang">Wareneingang</option>
                        <option value="kommi">Kommi</option>
                        <option value="cmr">CMR</option>
                        <option value="urlaub">Urlaub</option>
                        <option value="mitarbeiter">Mitarbeiter</option>
                        <option value="sonstiges">Sonstiges</option>
                        <option value="other">Sonstiges</option>
                    </select>
                </div>

                <div class="col-md-6" id="sourceNumberWrap">
                    <label class="form-label" id="sourceNumberLabel">Quellnummer / Bezug</label>
                    <input name="source_number" id="sourceNumberInput" class="form-control" maxlength="120" placeholder="z. B. Workbench-Nr., Schadensmeldung, Lieferschein, Mail-Betreff">
                    <div class="form-text" id="sourceNumberHint">Bei Workbench-Quelle z. B. Schadensmeldungsnummer oder Vorgangsnummer eintragen.</div>
                </div>

                <div class="col-md-6">
                    <label class="form-label">Quell-Link optional</label>
                    <input name="source_url" class="form-control" placeholder="z. B. Link zur Workbench-Seite">
                </div>
            </div>
        </div>

        <div class="claim-form-section">
            <div class="claim-form-section-title">
                <span class="section-icon">4</span>
                <span>Beschreibung & Verantwortung</span>
            </div>

            <div class="row g-3">
                <div class="col-md-8">
                    <label class="form-label">Titel *</label>
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

                <div class="col-12" id="articleProblemDescriptionWrap" data-reference-section="article-problem">
                    <label class="form-label" id="problemDescriptionLabel">Problembeschreibung</label>
                    <textarea name="problem_description" id="problemDescription" rows="5" class="form-control" placeholder="Was ist passiert? Wo? Wann? Wie viele Teile sind betroffen? Welche Nachweise gibt es?"></textarea>
                    <div class="form-text" id="problemDescriptionHint">Bei artikelbezogenen Reklamationen bitte Artikel, Menge, Fehlerbild und Nachweise beschreiben.</div>
                </div>


                <div class="col-12">
                    <div class="qm-classification-box">
                        <div class="qm-classification-title">
                            <span>QM-Klassifizierung</span>
                            <span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">Wiederholfehler-Erkennung</span>
                        </div>
                        <div class="qm-classification-subtitle">
                            Diese Felder helfen dem Qualitätsmanagement später zu erkennen, ob Fehlerbilder wiederkehren und ob Maßnahmen überarbeitet werden müssen.
                        </div>

                        <div class="row g-3 mt-1">
                            <div class="col-md-3">
                                <label class="form-label">Fehlerkategorie</label>
                                <select name="error_category" class="form-select">
                                    <?= qm_select_options(qm_error_category_options(), $_POST['error_category'] ?? '') ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Fehlerbild</label>
                                <select name="error_pattern" class="form-select">
                                    <?= qm_select_options(qm_error_pattern_options(), $_POST['error_pattern'] ?? '') ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Prozessbereich</label>
                                <select name="process_area" class="form-select">
                                    <?= qm_select_options(qm_process_area_options(), $_POST['process_area'] ?? '') ?>
                                </select>
                            </div>

                            <div class="col-md-3">
                                <label class="form-label">Ursachenkategorie</label>
                                <select name="root_cause_category" class="form-select">
                                    <?= qm_select_options(qm_root_cause_category_options(), $_POST['root_cause_category'] ?? '') ?>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>

<div class="col-md-8">
                    <label class="form-label">Gruppen</label>
                    <?php if ($groups): ?>
                        <div class="claim-group-select" id="claimGroupSelect">
                            <button type="button" class="claim-group-select-button" id="claimGroupSelectButton" aria-expanded="false">
                                <span id="claimGroupSelectText">Gruppen auswählen</span>
                                <span class="claim-group-select-arrow">▾</span>
                            </button>

                            <div class="claim-group-select-menu" id="claimGroupSelectMenu" hidden>
                                <div class="claim-group-select-search-wrap">
                                    <input type="search" class="form-control claim-group-select-search" id="claimGroupSearch" placeholder="Gruppe suchen..." autocomplete="off">
                                </div>

                                <div class="claim-group-select-options">
                                    <?php foreach ($groups as $group): ?>
                                        <?php
                                            $groupName = (string)($group['name'] ?? '');
                                            $ownerLabel = claim_group_owner_label($group);
                                            $groupLabel = $groupName . ' · ' . $ownerLabel;
                                            $mandatoryQuality = claim_group_is_mandatory_quality($group);
                                        ?>

                                        <?php if ($mandatoryQuality): ?>
                                            <input type="hidden" name="group_ids[]" value="<?= (int)$group['id'] ?>">
                                        <?php endif; ?>

                                        <label class="claim-group-select-option <?= $mandatoryQuality ? 'is-mandatory' : '' ?>"
                                               data-group-option
                                               data-group-search="<?= e(strtolower($groupLabel)) ?>">
                                            <input class="form-check-input"
                                                   type="checkbox"
                                                   name="<?= $mandatoryQuality ? 'group_ids_quality_locked[]' : 'group_ids[]' ?>"
                                                   value="<?= (int)$group['id'] ?>"
                                                   data-group-checkbox
                                                   data-group-label="<?= e($groupLabel) ?>"
                                                   <?= $mandatoryQuality ? 'checked disabled' : '' ?>>
                                            <span class="claim-group-option-content">
                                                <span class="claim-group-option-title"><?= e($groupName) ?></span>
                                                <span class="claim-group-option-owner"><?= e($ownerLabel) ?></span>
                                                <?php if ($mandatoryQuality): ?>
                                                    <span class="claim-group-option-note">Pflichtgruppe · immer angewählt</span>
                                                <?php endif; ?>
                                            </span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>

                                <div class="claim-group-select-footer">
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="claimGroupClear">Auswahl löschen</button>
                                    <button type="button" class="btn btn-sm btn-primary" id="claimGroupDone">Fertig</button>
                                </div>
                            </div>
                        </div>
                        <div class="form-text">Doppelte Gruppen werden in dieser Auswahl automatisch zusammengeführt. Qualität ist immer fest angewählt.</div>
                    <?php else: ?>
                        <div class="form-control bg-light text-muted">Noch keine Gruppen vorhanden.</div>
                        <div class="form-text"><a href="groups.php">Gruppen jetzt anlegen</a></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card-footer bg-white d-flex justify-content-between">
        <a href="claims.php" class="btn btn-outline-secondary">Abbrechen</a>
        <button class="btn btn-primary">Reklamation erstellen</button>
    </div>
</form>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const typeSelect = document.getElementById('caseReferenceType');
    const articleSection = document.getElementById('articleSection');
    const incidentSection = document.getElementById('incidentSection');
    const articleProblemWrap = document.getElementById('articleProblemDescriptionWrap');
    const articleProblemTextarea = document.getElementById('problemDescription');
    const incidentProblemTextarea = document.getElementById('incidentProblemDescription');

    function setFieldsEnabled(container, enabled, clearWhenDisabled) {
        if (!container) {
            return;
        }

        container.querySelectorAll('input, select, textarea').forEach(function (field) {
            field.disabled = !enabled;

            if (!enabled && clearWhenDisabled) {
                field.value = '';
            }
        });
    }

    function updateReferenceType() {
        const isIncident = typeSelect && typeSelect.value === 'incident';

        if (articleSection) {
            articleSection.classList.toggle('d-none', isIncident);
        }

        if (incidentSection) {
            incidentSection.classList.toggle('d-none', !isIncident);
        }

        if (articleProblemWrap) {
            articleProblemWrap.classList.toggle('d-none', isIncident);
        }

        setFieldsEnabled(articleSection, !isIncident, true);
        setFieldsEnabled(articleProblemWrap, !isIncident, true);
        setFieldsEnabled(incidentSection, isIncident, true);

        if (articleProblemTextarea) {
            articleProblemTextarea.required = !isIncident;
        }

        if (incidentProblemTextarea) {
            incidentProblemTextarea.required = isIncident;
        }
    }

    if (typeSelect) {
        typeSelect.addEventListener('change', updateReferenceType);
        updateReferenceType();
    }
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const sourceSelect = document.getElementById('sourceModuleSelect') || document.querySelector('[name="source_module"]');
    const sourceNumberWrap = document.getElementById('sourceNumberWrap');
    const sourceNumberLabel = document.getElementById('sourceNumberLabel');
    const sourceNumberInput = document.getElementById('sourceNumberInput') || document.querySelector('[name="source_number"]');
    const sourceNumberHint = document.getElementById('sourceNumberHint');

    function isOtherSource(value) {
        value = (value || '').toString().toLowerCase().trim();
        return value === 'other' || value === 'sonstiges' || value === 'sonstige' || value === 'manual' || value === 'manuell';
    }

    function updateSourceFields() {
        if (!sourceSelect || !sourceNumberInput) {
            return;
        }

        const other = isOtherSource(sourceSelect.value);

        if (sourceNumberWrap) {
            sourceNumberWrap.classList.toggle('is-other-source', other);
        }

        if (sourceNumberLabel) {
            sourceNumberLabel.textContent = other ? 'Quellenangabe *' : 'Quellnummer / Bezug';
        }

        sourceNumberInput.placeholder = other
            ? 'z. B. E-Mail vom Kunden, Telefonat, Audit, interner Vorfall, manuelle Meldung'
            : 'z. B. Workbench-Nr., Schadensmeldung, Lieferschein, Mail-Betreff';

        sourceNumberInput.required = other;

        if (sourceNumberHint) {
            sourceNumberHint.textContent = other
                ? 'Bitte die Quelle so eintragen, dass später nachvollziehbar ist, woher die Reklamation/Vorfallmeldung kam.'
                : 'Bei Workbench-Quelle z. B. Schadensmeldungsnummer oder Vorgangsnummer eintragen.';
        }
    }

    if (sourceSelect) {
        sourceSelect.addEventListener('change', updateSourceFields);
        updateSourceFields();
    }
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('claimCreateForm');

    if (!form) {
        return;
    }

    form.addEventListener('keydown', function (event) {
        if (event.key !== 'Enter') {
            return;
        }

        const target = event.target;
        const tagName = target && target.tagName ? target.tagName.toLowerCase() : '';
        const type = target && target.type ? target.type.toLowerCase() : '';

        const allowedEnterTypes = ['textarea', 'submit', 'button'];
        const isAllowed = allowedEnterTypes.indexOf(tagName) !== -1 || allowedEnterTypes.indexOf(type) !== -1;

        if (!isAllowed) {
            event.preventDefault();
        }
    });
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const groupSelect = document.getElementById('claimGroupSelect');
    const button = document.getElementById('claimGroupSelectButton');
    const buttonText = document.getElementById('claimGroupSelectText');
    const menu = document.getElementById('claimGroupSelectMenu');
    const search = document.getElementById('claimGroupSearch');
    const clearButton = document.getElementById('claimGroupClear');
    const doneButton = document.getElementById('claimGroupDone');
    const checkboxes = document.querySelectorAll('[data-group-checkbox]');
    const options = document.querySelectorAll('[data-group-option]');

    if (!groupSelect || !button || !menu) {
        return;
    }

    function updateDropdownDirection() {
        if (!button || !menu || menu.hidden) {
            return;
        }

        groupSelect.classList.remove('is-dropup');

        const buttonRect = button.getBoundingClientRect();
        const viewportHeight = window.innerHeight || document.documentElement.clientHeight;
        const menuHeight = Math.min(menu.scrollHeight || 320, 360);
        const spaceBelow = viewportHeight - buttonRect.bottom;
        const spaceAbove = buttonRect.top;

        if (spaceBelow < menuHeight + 24 && spaceAbove > spaceBelow) {
            groupSelect.classList.add('is-dropup');
        }
    }

    function setOpen(open) {
        menu.hidden = !open;
        button.setAttribute('aria-expanded', open ? 'true' : 'false');
        groupSelect.classList.toggle('is-open', open);

        if (open) {
            updateDropdownDirection();

            setTimeout(function () {
                updateDropdownDirection();

                if (search) {
                    search.focus();
                }
            }, 50);
        } else {
            groupSelect.classList.remove('is-dropup');
        }
    }

    function updateText() {
        const selected = Array.from(checkboxes).filter(function (checkbox) {
            return checkbox.checked;
        });

        const selectableSelected = selected.filter(function (checkbox) {
            return !checkbox.disabled;
        });

        if (!buttonText) {
            return;
        }

        if (selected.length === 0) {
            buttonText.textContent = 'Gruppen auswählen';
            return;
        }

        if (selectableSelected.length === 0) {
            buttonText.textContent = 'Qualität fest ausgewählt';
            return;
        }

        if (selectableSelected.length === 1) {
            buttonText.textContent = selectableSelected[0].getAttribute('data-group-label') || '1 Gruppe ausgewählt';
            return;
        }

        buttonText.textContent = selectableSelected.length + ' Gruppen ausgewählt + Qualität';
    }

    function applySearch() {
        const value = (search && search.value ? search.value : '').toString().toLowerCase().trim();

        options.forEach(function (option) {
            const haystack = (option.getAttribute('data-group-search') || '').toLowerCase();
            option.hidden = value !== '' && haystack.indexOf(value) === -1;
        });
    }

    button.addEventListener('click', function () {
        setOpen(menu.hidden);
    });

    checkboxes.forEach(function (checkbox) {
        checkbox.addEventListener('change', updateText);
    });

    if (search) {
        search.addEventListener('input', applySearch);
    }

    if (clearButton) {
        clearButton.addEventListener('click', function () {
            checkboxes.forEach(function (checkbox) {
                if (!checkbox.disabled) {
                    checkbox.checked = false;
                }
            });
            updateText();
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

    window.addEventListener('resize', updateDropdownDirection);
    window.addEventListener('scroll', updateDropdownDirection, true);

    updateText();
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const prioritySelect = document.getElementById('claimPrioritySelect');
    const priorityHint = document.getElementById('priorityProcessingHint');

    if (!prioritySelect || !priorityHint) {
        return;
    }

    function updatePriorityHint() {
        const selectedOption = prioritySelect.options[prioritySelect.selectedIndex];
        const processing = selectedOption ? selectedOption.getAttribute('data-processing') : '';

        priorityHint.textContent = processing
            ? 'Bearbeitungszeitraum: ' + processing
            : '';
    }

    prioritySelect.addEventListener('change', updatePriorityHint);
    updatePriorityHint();
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
