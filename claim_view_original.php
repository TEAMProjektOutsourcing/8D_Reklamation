<?php
require_once __DIR__ . '/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    redirect('claims.php');
}

$db = pdo();
require_claim_access($id);

$stmt = $db->prepare("SELECT c.*, u.name AS responsible_name, creator.name AS creator_name
    FROM claims c
    LEFT JOIN users u ON u.id = c.responsible_user_id
    LEFT JOIN users creator ON creator.id = c.created_by
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

$actionsStmt = $db->prepare("SELECT a.*, u.name AS responsible_name, u.email AS responsible_email, creator.name AS created_by_name
    FROM claim_actions a
    LEFT JOIN users u ON u.id = a.responsible_user_id
    LEFT JOIN users creator ON creator.id = a.created_by
    WHERE a.claim_id = ?
    ORDER BY FIELD(a.status, 'open','in_progress','done','cancelled'), a.due_date ASC, a.created_at DESC");
$actionsStmt->execute([$id]);
$actions = $actionsStmt->fetchAll();

$filesStmt = $db->prepare("SELECT f.*, u.name AS uploaded_by_name
    FROM claim_files f
    LEFT JOIN users u ON u.id = f.uploaded_by
    WHERE f.claim_id = ?
    ORDER BY f.created_at DESC");
$filesStmt->execute([$id]);
$files = $filesStmt->fetchAll();

$fileMetaEnabled = db_column_exists('claim_files', 'step_key') && db_column_exists('claim_files', 'category') && db_column_exists('claim_files', 'caption');

$imageFiles = [];
$otherFiles = [];
$stepImageFiles = [];
$stepOtherFiles = [];
$generalImageFiles = [];
$generalOtherFiles = [];

foreach ($files as $fileRow) {
    $isImage = is_image_mime($fileRow['mime_type'] ?? null);
    $rawStepKey = $fileMetaEnabled ? strtoupper(trim((string)($fileRow['step_key'] ?? ''))) : '';
    $hasStepAssignment = $rawStepKey !== '' && array_key_exists($rawStepKey, claim_step_definitions());

    if ($isImage) {
        $imageFiles[] = $fileRow;
        if ($hasStepAssignment) {
            $stepImageFiles[$rawStepKey][] = $fileRow;
        } else {
            $generalImageFiles[] = $fileRow;
        }
    } else {
        $otherFiles[] = $fileRow;
        if ($hasStepAssignment) {
            $stepOtherFiles[$rawStepKey][] = $fileRow;
        } else {
            $generalOtherFiles[] = $fileRow;
        }
    }
}

$assignedImageCount = count($imageFiles) - count($generalImageFiles);
$assignedOtherFileCount = count($otherFiles) - count($generalOtherFiles);

$historyStmt = $db->prepare("SELECT h.*, u.name AS user_name
    FROM claim_history h
    LEFT JOIN users u ON u.id = h.user_id
    WHERE h.claim_id = ?
    ORDER BY h.created_at DESC");
$historyStmt->execute([$id]);
$history = $historyStmt->fetchAll();

$users = get_users_for_select(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null);

$doneCount = 0;
$currentStepIndex = 0;
$inProgressIndex = null;

foreach ($steps as $index => $step) {
    if ($step['status'] === 'done') {
        $doneCount++;
    }
    if ($step['status'] === 'in_progress' && $inProgressIndex === null) {
        $inProgressIndex = $index;
    }
}

$stepCount = count($steps);
$progress = $stepCount > 0 ? (int)round($doneCount / $stepCount * 100) : 0;

if ($stepCount > 0) {
    if ($inProgressIndex !== null) {
        $currentStepIndex = $inProgressIndex;
    } elseif ($doneCount >= $stepCount) {
        $currentStepIndex = $stepCount - 1;
    } else {
        $currentStepIndex = max(0, min($doneCount, $stepCount - 1));
    }
}

$trackProgress = $stepCount > 1 ? (int)round(($currentStepIndex / ($stepCount - 1)) * 100) : 0;

require __DIR__ . '/header.php';
?>

<div class="card mb-4 eightd-progress-card no-print">
    <div class="card-body">
        <div class="d-flex flex-column flex-xl-row justify-content-between gap-3 mb-4">
            <div>
                <div class="text-muted small">8D-Fortschritt</div>
                <div class="h4 fw-bold mb-1"><?= $progress ?>% abgeschlossen</div>
                <div class="text-muted small">Aktueller Schritt: <?= e($steps[$currentStepIndex]['step_key'] ?? '-') ?> · <?= e($steps[$currentStepIndex]['title'] ?? '-') ?></div>
            </div>
            <?php if (can_edit()): ?>
                <form method="post" action="claim_status_save.php" class="eightd-status-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                    <label class="form-label small text-muted mb-1">Fallstatus ändern</label>
                    <div class="input-group">
                        <select name="status" class="form-select">
                            <?php foreach (['new','in_progress','waiting','overdue','closed','rejected','archived'] as $s): ?>
                                <?php if ($s === 'closed' && !can_close_claim()) continue; ?>
                                <option value="<?= e($s) ?>" <?= $claim['status'] === $s ? 'selected' : '' ?>><?= e(status_label($s)) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-primary">Speichern</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>

        <div class="eightd-tracker" style="--eightd-progress: <?= $trackProgress ?>%;">
            <div class="eightd-track"><div class="eightd-track-fill"></div></div>
            <?php foreach ($steps as $index => $step): ?>
                <?php
                    $stepClass = $step['status'] === 'done' ? 'is-completed' : ($index === $currentStepIndex ? 'is-current' : 'is-future');
                    if ($step['status'] === 'done' && $index === $currentStepIndex && $doneCount >= $stepCount) {
                        $stepClass = 'is-completed is-final';
                    }
                ?>
                <a class="eightd-step <?= e($stepClass) ?>" href="#step<?= e($step['step_key']) ?>" data-open-step="<?= e($step['step_key']) ?>" title="<?= e($step['title']) ?>">
                    <span class="eightd-badge"><?= e($step['step_key']) ?></span>
                    <span class="eightd-step-title"><?= e($step['title']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <div class="text-muted small">Reklamation</div>
        <h1 class="h3 fw-bold claim-title mb-1"><?= e($claim['claim_number']) ?> · <?= e($claim['short_description']) ?></h1>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <?= status_badge($claim['status']) ?>
            <span class="badge bg-dark"><?= e(priority_label($claim['priority'])) ?></span>
            <?= location_badge(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null) ?>
            <span class="text-muted">Partner: <?= e($claim['partner_name']) ?></span>
        </div>
    </div>
    <div class="d-flex gap-2 no-print">
        <a href="claims.php" class="btn btn-outline-secondary">Zurück</a>
        <a href="claim_report.php?id=<?= (int)$claim['id'] ?>" class="btn btn-outline-dark" target="_blank">Bericht / PDF</a>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card">
            <div class="card-header bg-white fw-bold">Stammdaten</div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><div class="text-muted small">Art</div><strong><?= e(status_label($claim['claim_type'])) ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Artikelnummer</div><strong><?= e($claim['article_number'] ?: '-') ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Menge betroffen</div><strong><?= e((string)($claim['quantity_affected'] ?? '-')) ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Verantwortlich</div><strong><?= e($claim['responsible_name'] ?? '-') ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Reklamationsdatum</div><strong><?= e($claim['claim_date']) ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Lieferdatum</div><strong><?= e($claim['delivery_date'] ?: '-') ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Erstellt von</div><strong><?= e($claim['creator_name'] ?? '-') ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Erstellt am</div><strong><?= e($claim['created_at']) ?></strong></div>
                    <?php if (!empty($claim['source_module']) || !empty($claim['source_number'])): ?>
                        <div class="col-md-3"><div class="text-muted small">Quelle</div><strong><?= e($claim['source_module'] ?: '-') ?></strong></div>
                        <div class="col-md-3"><div class="text-muted small">Quellnummer</div><strong><?= e($claim['source_number'] ?: '-') ?></strong></div>
                        <div class="col-md-6"><div class="text-muted small">Quell-Link</div>
                            <?php if (!empty($claim['source_url'])): ?>
                                <a href="<?= e($claim['source_url']) ?>" target="_blank" rel="noopener">Workbench-Vorgang öffnen</a>
                            <?php else: ?>
                                <strong>-</strong>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php if ($claim['problem_description']): ?>
                    <hr>
                    <div class="text-muted small mb-1">Problembeschreibung</div>
                    <div><?= nl2br(e($claim['problem_description'])) ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white fw-bold">8D-Schritte</div>
    <div class="accordion accordion-flush" id="stepsAccordion">
        <?php foreach ($steps as $index => $step): ?>
            <?php
                $stepStatusClass = 'is-step-' . preg_replace('/[^a-z0-9_-]/i', '', (string)$step['status']);
                $isCurrentAccordion = $index === $currentStepIndex;
                $isInitiallyOpen = $isCurrentAccordion;

                if ($step['status'] === 'done') {
                    $stepHeaderIcon = '✅';
                    $stepHeaderIconClass = 'is-done';
                    $stepHeaderIconTitle = 'Erledigt';
                } elseif ($isCurrentAccordion || $step['status'] === 'in_progress') {
                    $stepHeaderIcon = '🔵';
                    $stepHeaderIconClass = 'is-current';
                    $stepHeaderIconTitle = 'Aktueller Schritt';
                } else {
                    $stepHeaderIcon = '⏳';
                    $stepHeaderIconClass = 'is-open';
                    $stepHeaderIconTitle = 'Offen';
                }
            ?>
            <div class="accordion-item claim-step-item <?= e($stepStatusClass) ?> <?= $isInitiallyOpen ? 'is-open' : '' ?> <?= $isCurrentAccordion ? 'is-current-step' : '' ?>" id="accordionItem<?= e($step['step_key']) ?>">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= $isInitiallyOpen ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#step<?= e($step['step_key']) ?>">
                        <span class="step-header-icon <?= e($stepHeaderIconClass) ?>" title="<?= e($stepHeaderIconTitle) ?>" aria-hidden="true"><?= $stepHeaderIcon ?></span>
                        <strong class="me-2 step-header-key"><?= e($step['step_key']) ?></strong>
                        <span class="step-header-title"><?= e($step['title']) ?></span>
                        <span class="ms-3 step-header-status"><?= status_badge($step['status']) ?></span>
                    </button>
                </h2>
                <div id="step<?= e($step['step_key']) ?>" class="accordion-collapse collapse <?= $isInitiallyOpen ? 'show' : '' ?>" data-bs-parent="#stepsAccordion">
                    <div class="accordion-body">
                        <p class="text-muted mb-3"><?= e($step['description']) ?></p>

                        <?php if (can_edit()): ?>
                            <form method="post" action="claim_step_save.php" class="mb-3">
                                <?= csrf_field() ?>
                                <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                <input type="hidden" name="step_key" value="<?= e($step['step_key']) ?>">
                                <div class="mb-3">
                                    <label class="form-label">Inhalt / Dokumentation</label>
                                    <textarea name="content" rows="7" class="form-control" placeholder="Dokumentation für <?= e($step['step_key']) ?> eintragen..."><?= e($step['content']) ?></textarea>
                                </div>
                                <div class="row g-2 align-items-end">
                                    <div class="col-md-4">
                                        <label class="form-label">Status</label>
                                        <select name="status" class="form-select">
                                            <option value="open" <?= $step['status'] === 'open' ? 'selected' : '' ?>>Offen</option>
                                            <option value="in_progress" <?= $step['status'] === 'in_progress' ? 'selected' : '' ?>>In Bearbeitung</option>
                                            <option value="done" <?= $step['status'] === 'done' ? 'selected' : '' ?>>Erledigt</option>
                                        </select>
                                    </div>
                                    <div class="col-md-8 d-flex gap-2">
                                        <button class="btn btn-primary">D-Schritt speichern</button>
                                    </div>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="border rounded p-3 bg-light"><?= nl2br(e($step['content'] ?: 'Noch kein Inhalt.')) ?></div>
                        <?php endif; ?>

                        <hr>
                        <?php
                            $currentStepKeyForFiles = (string)$step['step_key'];
                            $currentStepImages = $stepImageFiles[$currentStepKeyForFiles] ?? [];
                            $currentStepOtherFiles = $stepOtherFiles[$currentStepKeyForFiles] ?? [];
                            $currentStepFileCount = count($currentStepImages) + count($currentStepOtherFiles);
                        ?>
                        <div class="step-file-section mb-4">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                <div>
                                    <h3 class="h6 fw-bold mb-1">Fotos & Nachweise zu <?= e($step['step_key']) ?></h3>
                                    <div class="small text-muted">Alles, was diesem D-Schritt zugeordnet ist, wird direkt hier angezeigt.</div>
                                </div>
                                <span class="badge bg-light text-dark"><?= $currentStepFileCount ?> Dateien</span>
                            </div>

                            <?php if (can_edit() && $fileMetaEnabled): ?>
                                <form method="post" action="upload_file.php" enctype="multipart/form-data" class="step-upload-form row g-2 align-items-end mb-3">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                    <input type="hidden" name="step_key" value="<?= e($step['step_key']) ?>">
                                    <div class="col-lg-4">
                                        <label class="form-label small">Foto / Datei</label>
                                        <input type="file" name="file" class="form-control form-control-sm" required>
                                    </div>
                                    <div class="col-lg-3">
                                        <label class="form-label small">Kategorie</label>
                                        <select name="category" class="form-select form-select-sm">
                                            <?php foreach (file_category_options() as $value => $label): ?>
                                                <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                    <div class="col-lg-3">
                                        <label class="form-label small">Beschreibung</label>
                                        <input name="caption" class="form-control form-control-sm" placeholder="Kurzbeschreibung">
                                    </div>
                                    <div class="col-lg-2 d-grid">
                                        <button class="btn btn-sm btn-outline-primary">Hochladen</button>
                                    </div>
                                </form>
                            <?php elseif (can_edit() && !$fileMetaEnabled && is_admin()): ?>
                                <div class="alert alert-warning small mb-3">
                                    Für Fotos direkt im D-Schritt bitte einmal
                                    <a href="run_photo_analytics_migration.php">die Fotodoku-Migration</a> ausführen.
                                </div>
                            <?php endif; ?>

                            <?php if ($currentStepImages): ?>
                                <div class="photo-grid step-photo-grid mb-3">
                                    <?php foreach ($currentStepImages as $file): ?>
                                        <div class="photo-card">
                                            <a href="file_download.php?id=<?= (int)$file['id'] ?>" target="_blank" class="photo-thumb-link">
                                                <img src="file_download.php?id=<?= (int)$file['id'] ?>" alt="<?= e($file['original_name']) ?>" class="photo-thumb">
                                            </a>
                                            <div class="photo-card-body">
                                                <div class="d-flex flex-wrap gap-1 mb-2">
                                                    <?= file_step_badge($file['step_key'] ?? null) ?>
                                                    <?= file_category_badge($file['category'] ?? 'other') ?>
                                                </div>
                                                <div class="fw-bold small text-truncate" title="<?= e($file['original_name']) ?>"><?= e($file['original_name']) ?></div>
                                                <?php if (!empty($file['caption'])): ?>
                                                    <div class="small text-muted mt-1"><?= nl2br(e((string)$file['caption'])) ?></div>
                                                <?php endif; ?>
                                                <div class="small text-muted mt-2"><?= e($file['uploaded_by_name'] ?? '-') ?> · <?= e(substr((string)$file['created_at'], 0, 16)) ?></div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if ($currentStepOtherFiles): ?>
                                <div class="list-group step-file-list mb-3">
                                    <?php foreach ($currentStepOtherFiles as $file): ?>
                                        <a class="list-group-item list-group-item-action" href="file_download.php?id=<?= (int)$file['id'] ?>" target="_blank">
                                            <div class="d-flex flex-wrap gap-1 mb-1">
                                                <?= file_step_badge($file['step_key'] ?? null) ?>
                                                <?= file_category_badge($file['category'] ?? 'other') ?>
                                            </div>
                                            <div class="fw-bold"><?= e($file['original_name']) ?></div>
                                            <?php if (!empty($file['caption'])): ?>
                                                <div class="small text-muted mb-1"><?= nl2br(e((string)$file['caption'])) ?></div>
                                            <?php endif; ?>
                                            <div class="small text-muted"><?= e($file['uploaded_by_name'] ?? '-') ?> · <?= e($file['created_at']) ?> · <?= number_format((int)$file['size_bytes'] / 1024, 1, ',', '.') ?> KB</div>
                                        </a>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <?php if (!$currentStepImages && !$currentStepOtherFiles): ?>
                                <div class="text-muted border rounded p-3 bg-light">Noch keine Fotos oder Nachweise für <?= e($step['step_key']) ?> vorhanden.</div>
                            <?php endif; ?>
                        </div>

                        <hr>
                        <h3 class="h6 fw-bold">Maßnahme zu <?= e($step['step_key']) ?> hinzufügen</h3>
                        <?php if (can_edit()): ?>
                            <form method="post" action="action_store.php" class="row g-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                <input type="hidden" name="step_key" value="<?= e($step['step_key']) ?>">
                                <div class="col-md-3">
                                    <input name="title" class="form-control" placeholder="Maßnahme" required>
                                </div>
                                <div class="col-md-3">
                                    <input name="description" class="form-control" placeholder="Beschreibung">
                                </div>
                                <div class="col-md-2">
                                    <select name="responsible_user_id" class="form-select">
                                        <option value="">Verantwortlich</option>
                                        <?php foreach ($users as $u): ?>
                                            <option value="<?= (int)$u['id'] ?>"><?= e($u['name']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-2">
                                    <input type="date" name="due_date" class="form-control">
                                </div>
                                <div class="col-md-2 d-grid">
                                    <button class="btn btn-outline-primary">Hinzufügen</button>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-8" id="actions">
        <div class="card">
            <div class="card-header bg-white d-flex flex-column flex-lg-row justify-content-between gap-2 align-items-lg-center">
                <div>
                    <div class="fw-bold">Maßnahmen</div>
                    <div class="small text-muted">Ampel: grün bis 5 Tage, gelb Tag 6–10, rot ab Tag 11 oder bei überschrittener Frist.</div>
                </div>
                <span class="badge bg-light text-dark"><?= count($actions) ?> Maßnahmen</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0 action-table">
                    <thead class="table-light">
                    <tr>
                        <th>Ampel</th>
                        <th>D</th>
                        <th>Maßnahme</th>
                        <th>Verantwortlich</th>
                        <th>Frist</th>
                        <th>Status</th>
                        <th class="text-end">Aktionen</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($actions as $action): ?>
                        <tr class="<?= e(action_row_class($action)) ?>">
                            <td class="text-nowrap"><?= action_traffic_badge($action) ?></td>
                            <td><span class="badge bg-secondary"><?= e($action['step_key']) ?></span></td>
                            <td>
                                <strong><?= e($action['title']) ?></strong>
                                <?php if ($action['description']): ?><div class="small text-muted"><?= e($action['description']) ?></div><?php endif; ?>
                                <div class="small text-muted mt-1">
                                    Angelegt: <?= e(substr((string)$action['created_at'], 0, 10)) ?>
                                    · Alter: <?= action_age_days($action) ?> Tage
                                    · von <?= e($action['created_by_name'] ?? '-') ?>
                                </div>
                            </td>
                            <td><?= e($action['responsible_name'] ?? '-') ?></td>
                            <td class="text-nowrap"><?= action_due_hint($action) ?></td>
                            <td><?= status_badge($action['status']) ?></td>
                            <td class="text-end text-nowrap">
                                <?php if (can_edit()): ?>
                                    <button class="btn btn-sm btn-outline-primary" data-bs-toggle="modal" data-bs-target="#actionEdit<?= (int)$action['id'] ?>">Bearbeiten</button>
                                    <?php if (!action_is_closed($action) && !empty($action['responsible_email'])): ?>
                                        <form method="post" action="action_reminder.php" class="d-inline">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                            <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                            <button class="btn btn-sm btn-outline-warning" data-confirm="E-Mail-Erinnerung an <?= e($action['responsible_name'] ?? 'Verantwortlichen') ?> senden?">Erinnern</button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="post" action="action_update.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                        <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                        <input type="hidden" name="status" value="<?= $action['status'] === 'done' ? 'open' : 'done' ?>">
                                        <button class="btn btn-sm <?= $action['status'] === 'done' ? 'btn-outline-secondary' : 'btn-success' ?>">
                                            <?= $action['status'] === 'done' ? 'Wieder öffnen' : 'Erledigt' ?>
                                        </button>
                                    </form>
                                    <form method="post" action="action_delete.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                        <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                        <button class="btn btn-sm btn-outline-danger" data-confirm="Maßnahme wirklich löschen?">Löschen</button>
                                    </form>

                                    <div class="modal fade text-start" id="actionEdit<?= (int)$action['id'] ?>" tabindex="-1" aria-hidden="true">
                                        <div class="modal-dialog modal-lg modal-dialog-scrollable">
                                            <form method="post" action="action_save.php" class="modal-content">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                                <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                                <div class="modal-header">
                                                    <h5 class="modal-title">Maßnahme bearbeiten</h5>
                                                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Schließen"></button>
                                                </div>
                                                <div class="modal-body">
                                                    <div class="row g-3">
                                                        <div class="col-md-3">
                                                            <label class="form-label">D-Schritt</label>
                                                            <select name="step_key" class="form-select" required>
                                                                <?php foreach (array_keys(claim_step_definitions()) as $key): ?>
                                                                    <option value="<?= e($key) ?>" <?= $action['step_key'] === $key ? 'selected' : '' ?>><?= e($key) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-9">
                                                            <label class="form-label">Maßnahme</label>
                                                            <input name="title" class="form-control" value="<?= e($action['title']) ?>" required>
                                                        </div>
                                                        <div class="col-12">
                                                            <label class="form-label">Beschreibung</label>
                                                            <textarea name="description" rows="4" class="form-control"><?= e($action['description']) ?></textarea>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label">Verantwortlich</label>
                                                            <select name="responsible_user_id" class="form-select">
                                                                <option value="">Nicht zugewiesen</option>
                                                                <?php foreach ($users as $u): ?>
                                                                    <option value="<?= (int)$u['id'] ?>" <?= (int)($action['responsible_user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>><?= e($u['name']) ?></option>
                                                                <?php endforeach; ?>
                                                            </select>
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label">Frist</label>
                                                            <input type="date" name="due_date" class="form-control" value="<?= e($action['due_date']) ?>">
                                                        </div>
                                                        <div class="col-md-4">
                                                            <label class="form-label">Status</label>
                                                            <select name="status" class="form-select">
                                                                <option value="open" <?= $action['status'] === 'open' ? 'selected' : '' ?>>Offen</option>
                                                                <option value="in_progress" <?= $action['status'] === 'in_progress' ? 'selected' : '' ?>>In Bearbeitung</option>
                                                                <option value="done" <?= $action['status'] === 'done' ? 'selected' : '' ?>>Erledigt</option>
                                                                <option value="cancelled" <?= $action['status'] === 'cancelled' ? 'selected' : '' ?>>Abgebrochen</option>
                                                            </select>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="modal-footer">
                                                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Abbrechen</button>
                                                    <button class="btn btn-primary">Speichern</button>
                                                </div>
                                            </form>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (!$actions): ?>
                        <tr><td colspan="7" class="text-center text-muted py-4">Noch keine Maßnahmen.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-lg-4" id="files">
        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">Allgemeine Fotodokumentation</span>
                <span class="badge bg-light text-dark"><?= count($generalImageFiles) ?> Bilder</span>
            </div>
            <div class="card-body">
                <?php if (!$fileMetaEnabled && is_admin()): ?>
                    <div class="alert alert-warning small">
                        Für D-Schritt, Kategorie und Beschreibung bitte einmal
                        <a href="run_photo_analytics_migration.php">die Fotodoku-Migration</a> ausführen.
                    </div>
                <?php endif; ?>

                <?php if ($assignedImageCount > 0 || $assignedOtherFileCount > 0): ?>
                    <div class="alert alert-info small">
                        <?= (int)$assignedImageCount ?> Bilder und <?= (int)$assignedOtherFileCount ?> Dateien sind D1–D8 zugeordnet und werden direkt im jeweiligen Accordion angezeigt.
                    </div>
                <?php endif; ?>

                <?php if (can_edit()): ?>
                    <form method="post" action="upload_file.php" enctype="multipart/form-data" class="mb-4 upload-photo-form">
                        <?= csrf_field() ?>
                        <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                        <div class="mb-2">
                            <label class="form-label">Datei / Foto</label>
                            <input type="file" name="file" class="form-control" required>
                        </div>
                        <?php if ($fileMetaEnabled): ?>
                            <div class="row g-2">
                                <div class="col-md-6">
                                    <label class="form-label">D-Schritt</label>
                                    <select name="step_key" class="form-select">
                                        <option value="">Allgemein</option>
                                        <?php foreach (claim_step_definitions() as $stepKey => $def): ?>
                                            <option value="<?= e($stepKey) ?>"><?= e($stepKey) ?> · <?= e($def['title']) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Kategorie</label>
                                    <select name="category" class="form-select">
                                        <?php foreach (file_category_options() as $value => $label): ?>
                                            <option value="<?= e($value) ?>"><?= e($label) ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>
                            <div class="mt-2">
                                <label class="form-label">Beschreibung</label>
                                <textarea name="caption" rows="2" class="form-control" placeholder="Was sieht man auf dem Foto / Nachweis?"></textarea>
                            </div>
                        <?php endif; ?>
                        <div class="d-grid mt-3">
                            <button class="btn btn-outline-primary">Hochladen</button>
                        </div>
                        <div class="form-text">Erlaubt: jpg, png, webp, pdf, docx, xlsx, txt. Max. je nach PHP-Serverlimit.</div>
                    </form>
                <?php endif; ?>

                <?php if ($generalImageFiles): ?>
                    <div class="photo-grid">
                        <?php foreach ($generalImageFiles as $file): ?>
                            <div class="photo-card">
                                <a href="file_download.php?id=<?= (int)$file['id'] ?>" target="_blank" class="photo-thumb-link">
                                    <img src="file_download.php?id=<?= (int)$file['id'] ?>" alt="<?= e($file['original_name']) ?>" class="photo-thumb">
                                </a>
                                <div class="photo-card-body">
                                    <div class="d-flex flex-wrap gap-1 mb-2">
                                        <?= file_step_badge($file['step_key'] ?? null) ?>
                                        <?= file_category_badge($file['category'] ?? 'other') ?>
                                    </div>
                                    <div class="fw-bold small text-truncate" title="<?= e($file['original_name']) ?>"><?= e($file['original_name']) ?></div>
                                    <?php if (!empty($file['caption'])): ?>
                                        <div class="small text-muted mt-1"><?= nl2br(e((string)$file['caption'])) ?></div>
                                    <?php endif; ?>
                                    <div class="small text-muted mt-2"><?= e($file['uploaded_by_name'] ?? '-') ?> · <?= e(substr((string)$file['created_at'], 0, 16)) ?></div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-muted border rounded p-3 bg-light mb-3">Keine allgemeinen Fotos vorhanden. D1–D8-Fotos findest du direkt im passenden Accordion.</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="card mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="fw-bold">Allgemeine Dateien</span>
                <span class="badge bg-light text-dark"><?= count($generalOtherFiles) ?> Dateien</span>
            </div>
            <div class="card-body">
                <div class="list-group">
                    <?php foreach ($generalOtherFiles as $file): ?>
                        <a class="list-group-item list-group-item-action" href="file_download.php?id=<?= (int)$file['id'] ?>" target="_blank">
                            <div class="d-flex flex-wrap gap-1 mb-1">
                                <?= file_step_badge($file['step_key'] ?? null) ?>
                                <?= file_category_badge($file['category'] ?? 'other') ?>
                            </div>
                            <div class="fw-bold"><?= e($file['original_name']) ?></div>
                            <?php if (!empty($file['caption'])): ?>
                                <div class="small text-muted mb-1"><?= nl2br(e((string)$file['caption'])) ?></div>
                            <?php endif; ?>
                            <div class="small text-muted"><?= e($file['uploaded_by_name'] ?? '-') ?> · <?= e($file['created_at']) ?> · <?= number_format((int)$file['size_bytes'] / 1024, 1, ',', '.') ?> KB</div>
                        </a>
                    <?php endforeach; ?>
                    <?php if (!$generalOtherFiles): ?>
                        <div class="text-muted">Keine allgemeinen Dateien vorhanden. D1–D8-Dateien findest du direkt im passenden Accordion.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="card">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                <span>Historie</span>
                <span class="badge bg-light text-dark"><?= count($history) ?> Einträge</span>
            </div>
            <div class="card-body">
                <div class="audit-timeline">
                    <?php foreach ($history as $item): ?>
                        <div class="audit-timeline-item">
                            <div class="audit-timeline-icon <?= e(history_icon_class((string)$item['action'])) ?>"></div>
                            <div class="audit-timeline-content">
                                <div class="d-flex flex-wrap justify-content-between gap-2">
                                    <div class="fw-bold"><?= e($item['action']) ?></div>
                                    <div class="small text-muted"><?= e($item['created_at']) ?></div>
                                </div>
                                <div class="small text-muted mb-1"><?= e($item['user_name'] ?? 'System') ?></div>
                                <?php if ($item['details']): ?>
                                    <div class="small audit-timeline-details"><?= nl2br(e($item['details'])) ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!$history): ?>
                        <div class="text-muted">Noch keine Historie vorhanden.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/footer.php'; ?>
