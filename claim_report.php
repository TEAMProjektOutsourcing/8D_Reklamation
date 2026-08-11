<?php
require_once __DIR__ . '/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$db = pdo();
require_claim_access($id);

$stmt = $db->prepare("SELECT c.*, u.name AS responsible_name, creator.name AS creator_name, closer.name AS closer_name
    FROM claims c
    LEFT JOIN users u ON u.id = c.responsible_user_id
    LEFT JOIN users creator ON creator.id = c.created_by
    LEFT JOIN users closer ON closer.id = c.closed_by
    WHERE c.id = ?");
$stmt->execute([$id]);
$claim = $stmt->fetch();

if (!$claim) {
    http_response_code(404);
    die('Reklamation nicht gefunden.');
}

$stepsStmt = $db->prepare('SELECT * FROM claim_steps WHERE claim_id = ? ORDER BY step_key');
$stepsStmt->execute([$id]);
$steps = $stepsStmt->fetchAll();

$actionsStmt = $db->prepare("SELECT a.*, u.name AS responsible_name
    FROM claim_actions a
    LEFT JOIN users u ON u.id = a.responsible_user_id
    WHERE a.claim_id = ?
    ORDER BY a.step_key, a.due_date ASC");
$actionsStmt->execute([$id]);
$actions = $actionsStmt->fetchAll();

$filesStmt = $db->prepare("SELECT * FROM claim_files WHERE claim_id = ? ORDER BY created_at DESC");
$filesStmt->execute([$id]);
$files = $filesStmt->fetchAll();

$fileMetaEnabled = db_column_exists('claim_files', 'step_key')
    && db_column_exists('claim_files', 'category')
    && db_column_exists('claim_files', 'caption');

$reportStepKeys = [];
foreach ($steps as $stepRow) {
    $reportStepKeys[] = strtoupper((string)$stepRow['step_key']);
}

$filesByStep = [];
$generalReportFiles = [];

foreach ($files as $fileRow) {
    $rawStepKey = $fileMetaEnabled ? strtoupper(trim((string)($fileRow['step_key'] ?? ''))) : '';
    $hasStepAssignment = $rawStepKey !== '' && in_array($rawStepKey, $reportStepKeys, true);

    if ($hasStepAssignment) {
        $filesByStep[$rawStepKey][] = $fileRow;
    } else {
        $generalReportFiles[] = $fileRow;
    }
}


function render_report_file_card(array $file): void
{
    ?>
    <div class="col-md-4 col-xl-3">
        <div class="p-3 h-100 report-file-card">
            <div class="d-flex flex-wrap gap-1 mb-2">
                <?= file_step_badge($file['step_key'] ?? null) ?>
                <?= file_category_badge($file['category'] ?? 'other') ?>
            </div>

            <div class="fw-bold small mb-2"><?= e($file['original_name']) ?></div>

            <?php if (is_image_mime($file['mime_type'])): ?>
                <img src="file_download.php?id=<?= (int)$file['id'] ?>" class="img-fluid rounded report-photo" alt="<?= e($file['original_name']) ?>">
            <?php else: ?>
                <div class="report-attachment-placeholder">
                    Dateianhang: <?= e($file['mime_type'] ?: 'unbekannt') ?>
                </div>
            <?php endif; ?>

            <?php if (!empty($file['caption'])): ?>
                <div class="small text-muted mt-2"><?= nl2br(e((string)$file['caption'])) ?></div>
            <?php endif; ?>
        </div>
    </div>
    <?php
}

require __DIR__ . '/header.php';
?>

<div class="report-page">
    <div class="card page-hero report-hero mb-4">
        <div class="card-body p-4 p-lg-5">
            <div class="row g-4 align-items-center">
                <div class="col-lg-8">
                    <div class="page-kicker mb-3">8D-Bericht · <?= e($claim['claim_number']) ?></div>
                    <h1 class="page-title display-6 fw-bold mb-2">8D-Reklamationsbericht</h1>
                    <div class="page-subtitle">
                        Automatisch erzeugt am <?= e(date('d.m.Y H:i')) ?>. Der Bericht fasst Kopfdaten, D1 bis D8, Maßnahmen und Anhänge zusammen.
                    </div>
                </div>

                <div class="col-lg-4">
                    <div class="page-actions">
                        <button type="button" class="btn btn-primary report-print-button no-print" onclick="openReportPrintOptions()">Als PDF drucken</button>
                    </div>
                </div>
            </div>
        </div>
    </div>


    <div id="reportPrintPanel" class="card report-print-panel no-print mb-4" hidden>
        <div class="card-header fw-bold d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>Druckauswahl</span>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeReportPrintOptions()">Schließen</button>
        </div>

        <div class="card-body">
            <div class="report-print-note mb-3">
                Wähle aus, welche Inhalte in den PDF-Druck sollen. So kannst du Kundenversionen erstellen, ohne interne Bereiche wie einzelne D-Schritte, Maßnahmen oder Fotos mitzudrucken.
            </div>

            <div class="report-print-option-grid mb-3">
                <label class="report-print-option">
                    <input class="form-check-input" type="checkbox" data-report-print-option value="kopf" checked>
                    <span>
                        <span class="report-print-option-title">Kopfdaten</span>
                        <span class="report-print-option-sub">Nummer, Partner, Artikel, Status, Beschreibung</span>
                    </span>
                </label>

                <?php foreach ($steps as $printStep): ?>
                    <label class="report-print-option">
                        <input class="form-check-input" type="checkbox" data-report-print-option value="<?= e($printStep['step_key']) ?>" checked>
                        <span>
                            <span class="report-print-option-title"><?= e($printStep['step_key']) ?> · <?= e($printStep['title']) ?></span>
                            <span class="report-print-option-sub">8D-Schritt im Bericht anzeigen</span>
                        </span>
                    </label>
                <?php endforeach; ?>

                <label class="report-print-option">
                    <input class="form-check-input" type="checkbox" data-report-print-option value="massnahmen" checked>
                    <span>
                        <span class="report-print-option-title">Maßnahmen</span>
                        <span class="report-print-option-sub">Tabelle mit Aufgaben, Fristen und Status</span>
                    </span>
                </label>

                <label class="report-print-option">
                    <input class="form-check-input" type="checkbox" data-report-print-option value="fotos" checked>
                    <span>
                        <span class="report-print-option-title">Bilder / Anhänge</span>
                        <span class="report-print-option-sub">Bilder und Nachweise innerhalb der D-Punkte anzeigen</span>
                    </span>
                </label>

                <label class="report-print-option">
                    <input class="form-check-input" type="checkbox" data-report-print-option value="signaturen" checked>
                    <span>
                        <span class="report-print-option-title">Unterschriften</span>
                        <span class="report-print-option-sub">Freigabe- und Verantwortlichenfelder</span>
                    </span>
                </label>
            </div>

            <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center report-print-actions">
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setReportPrintSelection(true)">Alle auswählen</button>
                    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="setReportPrintSelection(false)">Alle abwählen</button>
                </div>

                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" onclick="closeReportPrintOptions()">Abbrechen</button>
                    <button type="button" class="btn btn-primary" onclick="printSelectedReport()">Auswahl drucken</button>
                </div>
            </div>
        </div>
    </div>


    <div class="card report-card mb-4" data-print-section="kopf">
        <div class="card-header fw-bold">Kopfdaten</div>
        <div class="card-body">
            <div class="report-meta-grid mb-4">
                <div class="report-meta-item">
                    <div class="report-meta-label">Reklamationsnummer</div>
                    <div class="report-meta-value"><?= e($claim['claim_number']) ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Standort</div>
                    <div class="report-meta-value"><?= location_badge(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null) ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Art</div>
                    <div class="report-meta-value"><?= e(status_label($claim['claim_type'])) ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Status</div>
                    <div class="report-meta-value"><?= e(status_label($claim['status'])) ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Priorität</div>
                    <div class="report-meta-value"><?= e(priority_label($claim['priority'])) ?></div>
                </div>

                <?php if (!empty($claim['source_module']) || !empty($claim['source_number'])): ?>
                    <div class="report-meta-item">
                        <div class="report-meta-label">Quelle</div>
                        <div class="report-meta-value"><?= e($claim['source_module'] ?: '-') ?></div>
                    </div>

                    <div class="report-meta-item">
                        <div class="report-meta-label">Quellnummer</div>
                        <div class="report-meta-value"><?= e($claim['source_number'] ?: '-') ?></div>
                    </div>
                <?php endif; ?>

                <div class="report-meta-item">
                    <div class="report-meta-label">Kunde / Lieferant / Bereich</div>
                    <div class="report-meta-value"><?= e($claim['partner_name']) ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Verantwortlich</div>
                    <div class="report-meta-value"><?= e($claim['responsible_name'] ?? '-') ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Erstellt von</div>
                    <div class="report-meta-value"><?= e($claim['creator_name'] ?? '-') ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Artikelnummer</div>
                    <div class="report-meta-value"><?= e($claim['article_number'] ?: '-') ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Artikelbezeichnung</div>
                    <div class="report-meta-value"><?= e($claim['article_name'] ?: '-') ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Menge betroffen</div>
                    <div class="report-meta-value"><?= e((string)($claim['quantity_affected'] ?? '-')) ?></div>
                </div>

                <div class="report-meta-item">
                    <div class="report-meta-label">Reklamationsdatum</div>
                    <div class="report-meta-value"><?= e($claim['claim_date']) ?></div>
                </div>
            </div>

            <div class="row g-3">
                <div class="col-lg-6">
                    <div class="report-text-block h-100">
                        <div class="report-text-title">Kurzbeschreibung</div>
                        <div><?= nl2br(e($claim['short_description'])) ?></div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="report-text-block h-100">
                        <div class="report-text-title">Problembeschreibung</div>
                        <div><?= nl2br(e($claim['problem_description'] ?: '-')) ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php foreach ($steps as $step): ?>
        <div class="card report-step-card mb-3" data-print-section="<?= e($step['step_key']) ?>">
            <div class="card-header">
                <div class="report-step-title">
                    <span class="report-step-key"><?= e($step['step_key']) ?></span>
                    <span><?= e($step['title']) ?></span>
                </div>
                <span class="badge bg-light text-dark border"><?= e(status_label($step['status'])) ?></span>
            </div>

            <div class="card-body">
                <div class="report-step-description"><?= e($step['description']) ?></div>
                <div class="report-step-content"><?= nl2br(e($step['content'] ?: 'Keine Angabe.')) ?></div>

                <?php $stepFiles = $filesByStep[strtoupper((string)$step['step_key'])] ?? []; ?>
                <?php if ($stepFiles): ?>
                    <div class="report-step-files mt-3" data-print-section="fotos">
                        <div class="report-step-files-title">
                            Bilder / Nachweise zu <?= e($step['step_key']) ?>
                            <span class="badge bg-light text-dark border"><?= count($stepFiles) ?></span>
                        </div>
                        <div class="row g-3 mt-1">
                            <?php foreach ($stepFiles as $file): ?>
                                <?php render_report_file_card($file); ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <div class="card report-actions-card mb-4" data-print-section="massnahmen">
        <div class="card-header fw-bold">Maßnahmen</div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover align-middle mb-0">
                <thead class="table-light">
                <tr>
                    <th>D</th>
                    <th>Maßnahme</th>
                    <th>Beschreibung</th>
                    <th>Verantwortlich</th>
                    <th>Frist</th>
                    <th>Status</th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($actions as $action): ?>
                    <tr>
                        <td><span class="badge bg-secondary"><?= e($action['step_key']) ?></span></td>
                        <td class="fw-semibold"><?= e($action['title']) ?></td>
                        <td><?= e($action['description'] ?: '-') ?></td>
                        <td><?= e($action['responsible_name'] ?? '-') ?></td>
                        <td><?= e($action['due_date'] ?: '-') ?></td>
                        <td><?= e(status_label($action['status'])) ?></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (!$actions): ?>
                    <tr><td colspan="6" class="text-center text-muted py-4">Keine Maßnahmen vorhanden.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card report-files-card mb-4" data-print-section="fotos">
        <div class="card-header fw-bold d-flex justify-content-between align-items-center">
            <span>Allgemeine Anhänge ohne D-Zuordnung</span>
            <span class="badge bg-light text-dark border"><?= count($generalReportFiles) ?> Datei<?= count($generalReportFiles) === 1 ? '' : 'en' ?></span>
        </div>
        <div class="card-body">
            <?php if (!$files): ?>
                <div class="text-muted">Keine Anhänge vorhanden.</div>
            <?php elseif (!$generalReportFiles): ?>
                <div class="text-muted">Alle Bilder und Anhänge sind direkt den passenden D-Punkten zugeordnet.</div>
            <?php else: ?>
                <div class="text-muted small mb-3">
                    Diese Dateien haben noch keine D1–D8-Zuordnung und bleiben deshalb gesammelt in diesem Bereich.
                </div>
                <div class="row g-3">
                    <?php foreach ($generalReportFiles as $file): ?>
                        <?php render_report_file_card($file); ?>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="row g-4 mt-4" data-print-section="signaturen">
        <div class="col-md-6">
            <div class="report-signature-card">
                <div class="report-signature-line">Datum / Unterschrift Verantwortlicher</div>
            </div>
        </div>

        <div class="col-md-6">
            <div class="report-signature-card">
                <div class="report-signature-line">Datum / Freigabe Qualität</div>
            </div>
        </div>
    </div>
</div>


<script>
function openReportPrintOptions() {
    const panel = document.getElementById('reportPrintPanel');
    if (!panel) {
        window.print();
        return;
    }

    panel.hidden = false;
    panel.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function closeReportPrintOptions() {
    const panel = document.getElementById('reportPrintPanel');
    if (panel) {
        panel.hidden = true;
    }
    restoreReportPrintSections();
}

function setReportPrintSelection(checked) {
    document.querySelectorAll('[data-report-print-option]').forEach(function (input) {
        input.checked = checked;
    });
}

function restoreReportPrintSections() {
    document.querySelectorAll('[data-print-section]').forEach(function (section) {
        section.classList.remove('report-print-hide');
    });
}

function printSelectedReport() {
    const selected = Array.from(document.querySelectorAll('[data-report-print-option]:checked'))
        .map(function (input) { return input.value; });

    if (selected.length === 0) {
        alert('Bitte mindestens einen Bereich für den Druck auswählen.');
        return;
    }

    document.querySelectorAll('[data-print-section]').forEach(function (section) {
        const key = section.getAttribute('data-print-section');
        section.classList.toggle('report-print-hide', selected.indexOf(key) === -1);
    });

    window.print();
}

window.addEventListener('afterprint', restoreReportPrintSections);
</script>

<?php require __DIR__ . '/footer.php'; ?>
