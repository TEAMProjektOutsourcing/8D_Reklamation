<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/claim_group_helper.php';
require_once __DIR__ . '/qm_helper.php';
require_once __DIR__ . '/analytics_access_helper.php';
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

$claimPriority = (string)($claim['priority'] ?? 'medium');
$claimProcessingMap = [
    'low' => ['label' => '10 Tage (2 Arbeitswochen)', 'days' => 10],
    'medium' => ['label' => '7 Tage', 'days' => 7],
    'high' => ['label' => '5 Tage', 'days' => 5],
    'critical' => ['label' => '2 Tage', 'days' => 2],
];
$claimProcessingLabel = $claimProcessingMap[$claimPriority]['label'] ?? $claimProcessingMap['medium']['label'];
$claimProcessingDays = (int)($claimProcessingMap[$claimPriority]['days'] ?? 7);
$defaultActionDueDate = date('Y-m-d', strtotime('+' . $claimProcessingDays . ' days'));

$stepsStmt = $db->prepare('SELECT * FROM claim_steps WHERE claim_id = ? ORDER BY step_key');
$stepsStmt->execute([$id]);
$steps = $stepsStmt->fetchAll();

$claimViewUser = current_user();
$claimViewUserId = (int)($claimViewUser['id'] ?? 0);
$claimViewUserRole = (string)($claimViewUser['role'] ?? '');
$claimViewCanSeeAllActions = ($claimViewUserRole === 'admin');
$claimViewCanSeeAnalytics = analytics_can_view($claimViewUser);

$actionsWhereSql = 'WHERE a.claim_id = ?';
$actionsParams = [$id];

if (!$claimViewCanSeeAllActions) {
    /*
     * Normale Mitarbeiter sehen:
     * - zusätzliche Maßnahmen, die ihnen zugewiesen wurden
     * - zusätzliche Maßnahmen, die sie selbst an andere vergeben haben
     *
     * Fremde Maßnahmen bleiben weiterhin ausgeblendet.
     */
    $actionsWhereSql .= ' AND (a.responsible_user_id = ? OR a.created_by = ?)';
    $actionsParams[] = $claimViewUserId;
    $actionsParams[] = $claimViewUserId;
}

$actionsStmt = $db->prepare("SELECT a.*, u.name AS responsible_name, u.email AS responsible_email, creator.name AS created_by_name
    FROM claim_actions a
    LEFT JOIN users u ON u.id = a.responsible_user_id
    LEFT JOIN users creator ON creator.id = a.created_by
    " . $actionsWhereSql . "
    ORDER BY FIELD(a.status, 'open','in_progress','done','cancelled'), a.due_date ASC, a.created_at DESC");
$actionsStmt->execute($actionsParams);
$actions = $actionsStmt->fetchAll();

/*
 * Für den Abschluss von D8 werden alle noch offenen Zusatzmaßnahmen geprüft,
 * unabhängig davon, welche davon der aktuelle Benutzer in der Tabelle sehen darf.
 */
$closureBlockingActionsStmt = $db->prepare("SELECT
        a.id,
        a.title,
        a.status,
        a.due_date,
        a.responsible_user_id,
        COALESCE(NULLIF(u.name, ''), 'Nicht zugewiesen') AS responsible_name
    FROM claim_actions a
    LEFT JOIN users u ON u.id = a.responsible_user_id
    WHERE a.claim_id = ?
      AND a.status IN ('open', 'in_progress')
    ORDER BY
        CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END,
        a.due_date ASC,
        a.created_at ASC");
$closureBlockingActionsStmt->execute([$id]);
$closureBlockingActions = $closureBlockingActionsStmt->fetchAll();

$myAssignedActions = [];
$myDelegatedActions = [];
$actionGroups = [];

if ($claimViewCanSeeAllActions) {
    if ($actions) {
        $actionGroups[] = [
            'title' => 'Alle zusätzlichen Maßnahmen',
            'description' => 'Alle zusätzlich vergebenen Aufgaben dieser Reklamation.',
            'actions' => $actions,
        ];
    }
} else {
    foreach ($actions as $actionRow) {
        $isAssignedToCurrentUser = (int)($actionRow['responsible_user_id'] ?? 0) === $claimViewUserId;
        $isCreatedByCurrentUser = (int)($actionRow['created_by'] ?? 0) === $claimViewUserId;

        if ($isAssignedToCurrentUser) {
            $myAssignedActions[] = $actionRow;
            continue;
        }

        if ($isCreatedByCurrentUser) {
            $myDelegatedActions[] = $actionRow;
        }
    }

    if ($myAssignedActions) {
        $actionGroups[] = [
            'title' => 'Meine zugewiesenen Maßnahmen',
            'description' => 'Zusätzliche Aufgaben, die dir in dieser Reklamation persönlich zugewiesen wurden.',
            'actions' => $myAssignedActions,
        ];
    }

    if ($myDelegatedActions) {
        $actionGroups[] = [
            'title' => 'Von mir vergebene Maßnahmen',
            'description' => 'Zusätzliche Aufgaben, die du anderen Mitarbeitenden in dieser Reklamation übertragen hast.',
            'actions' => $myDelegatedActions,
        ];
    }
}

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

/*
 * Zusatzmaßnahmen sind Delegationen an andere Personen.
 * Die aktuell angemeldete Person wird deshalb nicht angeboten.
 */
$usersForActionAssignment = array_values(array_filter(
    $users,
    static fn(array $userRow): bool => (int)($userRow['id'] ?? 0) !== $claimViewUserId
));

$claimGroups = claim_groups_for_claim($id);

function claim_view_group_is_quality(array $group): bool
{
    $name = strtolower((string)($group['name'] ?? ''));

    return strpos($name, 'qualität') !== false
        || strpos($name, 'qualitaet') !== false
        || strpos($name, 'quality') !== false;
}

$allClaimGroups = function_exists('active_claim_groups_for_select')
    ? active_claim_groups_for_select(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null)
    : [];

$assignedGroupIds = array_map('intval', array_column($claimGroups, 'id'));

$qmFieldsEnabled = false;
$qmClaimLabels = [];
$qmSimilarClaims = [];
$qmRepeatLevel = 'green';
$qmAiAnalysis = null;
$qmAiSimilarities = [];
$qmAiFeedbackRows = [];

/*
 * Vertrauliche QM-Daten werden nur für Rollen geladen,
 * die auch auf auswertungen.php zugreifen dürfen.
 */
if ($claimViewCanSeeAnalytics) {
    try {
        $qmFieldsEnabled = qm_claim_fields_enabled();
        $qmClaimLabels = $qmFieldsEnabled ? qm_claim_labels($claim) : [];
        $qmSimilarClaims = $qmFieldsEnabled ? qm_find_similar_claims($db, $claim, 90, 8) : [];
        $qmRepeatLevel = qm_repeat_level($qmSimilarClaims);
    } catch (Throwable $e) {
        error_log('QM Block in claim_view fehlgeschlagen: ' . $e->getMessage());
    }

    try {
        $qmAiAnalysis = qm_latest_ai_analysis($db, $id);
        $qmAiSimilarities = qm_latest_ai_similarities($db, $id);
    } catch (Throwable $e) {
        error_log('QM KI-Light Block in claim_view fehlgeschlagen: ' . $e->getMessage());
    }

    try {
        $qmAiFeedbackRows = qm_latest_ai_feedback($db, $id);
    } catch (Throwable $e) {
        error_log('QM Feedback Block in claim_view fehlgeschlagen: ' . $e->getMessage());
    }
}


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

<style>
.claim-layout {
    align-items: flex-start;
}

.claim-main > .card,
.claim-side .card {
    border-radius: 16px;
}

.claim-side-sticky {
    position: sticky;
    top: 96px;
}

@media (max-width: 991.98px) {
    .claim-side-sticky {
        position: static;
    }
}

@media print {
    .claim-layout {
        display: block;
    }

    .claim-main,
    .claim-side {
        width: 100% !important;
    }
}

.step-staged-files {
    margin-top: .75rem;
    padding: .8rem;
    border: 1px dashed rgba(13, 110, 253, .35);
    border-radius: 12px;
    background: rgba(13, 110, 253, .035);
}

.step-staged-files-head {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    justify-content: space-between;
    gap: .45rem;
    margin-bottom: .55rem;
}

.step-staged-files-title {
    color: #0d6efd;
    font-size: .79rem;
    font-weight: 800;
}

.step-staged-file-list {
    display: grid;
    gap: .45rem;
}

.step-staged-file-item {
    display: grid;
    grid-template-columns: 88px minmax(0, 1fr) auto;
    align-items: center;
    gap: .75rem;
    padding: .55rem .65rem;
    border: 1px solid rgba(148, 163, 184, .2);
    border-radius: 10px;
    background: #fff;
}

.step-staged-file-preview-link {
    position: relative;
    display: flex;
    width: 88px;
    height: 66px;
    overflow: hidden;
    align-items: center;
    justify-content: center;
    border: 1px solid rgba(148, 163, 184, .28);
    border-radius: 9px;
    background: #f8fafc;
    color: #475569;
    text-decoration: none;
}

.step-staged-file-preview-link:hover {
    border-color: rgba(13, 110, 253, .55);
    color: #0d6efd;
}

.step-staged-file-preview-image {
    width: 100%;
    height: 100%;
    object-fit: cover;
}

.step-staged-file-preview-type {
    padding: .25rem;
    font-size: .72rem;
    font-weight: 900;
    letter-spacing: .04em;
    text-align: center;
    word-break: break-word;
}

.step-staged-file-preview-open {
    position: absolute;
    right: 3px;
    bottom: 3px;
    padding: 1px 5px;
    border-radius: 999px;
    background: rgba(15, 23, 42, .78);
    color: #fff;
    font-size: .58rem;
    font-weight: 700;
}

.step-staged-file-main {
    min-width: 0;
}

@media (max-width: 575.98px) {
    .step-staged-file-item {
        grid-template-columns: 76px minmax(0, 1fr);
    }

    .step-staged-file-preview-link {
        width: 76px;
        height: 60px;
    }

    .step-staged-file-remove {
        grid-column: 1 / -1;
        width: 100%;
    }
}

.step-staged-file-name {
    overflow: hidden;
    color: #0f172a;
    font-size: .79rem;
    font-weight: 800;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.step-staged-file-meta {
    margin-top: .1rem;
    color: #64748b;
    font-size: .7rem;
}

.step-staged-file-remove {
    flex: 0 0 auto;
}

.step-upload-hint {
    margin-top: .42rem;
    color: #64748b;
    font-size: .72rem;
}

.step-upload-hint strong {
    color: #334155;
}


.action-assignment-empty-note {
    width: min(100%, 520px);
    padding: .85rem 1rem;
    border: 1px solid rgba(148, 163, 184, .28);
    border-radius: 12px;
    background: #f8fafc;
    color: #64748b;
    font-size: .82rem;
}

.claim-center-notice {
    position: fixed;
    z-index: 1085;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
}

.claim-center-notice.is-hidden {
    display: none;
}

.claim-center-notice-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, .58);
    backdrop-filter: blur(2px);
}

.claim-center-notice-panel {
    position: relative;
    z-index: 1;
    width: min(100%, 460px);
    overflow: hidden;
    border: 1px solid rgba(148, 163, 184, .28);
    border-radius: 18px;
    background: #fff;
    box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
}

.claim-center-notice-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1rem 1.1rem .75rem;
    border-bottom: 1px solid rgba(148, 163, 184, .18);
}

.claim-center-notice-title {
    margin: 0;
    color: #0f172a;
    font-size: 1rem;
    font-weight: 800;
}

.claim-center-notice-body {
    padding: 1rem 1.1rem;
    color: #475569;
    font-size: .9rem;
    line-height: 1.55;
    white-space: pre-line;
}

.claim-center-notice-actions {
    display: flex;
    justify-content: center;
    padding: 0 1.1rem 1.1rem;
}

.claim-center-notice-panel.is-danger {
    border-color: rgba(220, 53, 69, .28);
}

.claim-center-notice-panel.is-warning {
    border-color: rgba(255, 193, 7, .42);
}


.d8-close-dialog {
    position: fixed;
    z-index: 1095;
    inset: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 1.25rem;
}

.d8-close-dialog.is-hidden {
    display: none;
}

.d8-close-dialog-backdrop {
    position: absolute;
    inset: 0;
    background: rgba(15, 23, 42, .64);
    backdrop-filter: blur(3px);
}

.d8-close-dialog-panel {
    position: relative;
    z-index: 1;
    width: min(100%, 620px);
    max-height: calc(100vh - 2.5rem);
    overflow: auto;
    border: 1px solid rgba(148, 163, 184, .26);
    border-radius: 20px;
    background: #fff;
    box-shadow: 0 30px 90px rgba(15, 23, 42, .34);
}

.d8-close-dialog-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;
    padding: 1.15rem 1.25rem .9rem;
    border-bottom: 1px solid rgba(148, 163, 184, .18);
}

.d8-close-dialog-kicker {
    margin-bottom: .18rem;
    color: #0d6efd;
    font-size: .72rem;
    font-weight: 900;
    letter-spacing: .08em;
    text-transform: uppercase;
}

.d8-close-dialog-title {
    margin: 0;
    color: #0f172a;
    font-size: 1.16rem;
    font-weight: 850;
}

.d8-close-dialog-body {
    padding: 1.1rem 1.25rem;
    color: #475569;
    font-size: .91rem;
    line-height: 1.55;
}

.d8-close-dialog-note {
    padding: .85rem .95rem;
    border: 1px solid rgba(25, 135, 84, .24);
    border-radius: 12px;
    background: rgba(25, 135, 84, .055);
    color: #166534;
}

.d8-close-dialog-blocked {
    padding: .9rem .95rem;
    border: 1px solid rgba(220, 53, 69, .25);
    border-radius: 12px;
    background: rgba(220, 53, 69, .055);
    color: #991b1b;
}

.d8-close-blocker-list {
    display: grid;
    gap: .55rem;
    margin: .75rem 0 0;
    padding: 0;
    list-style: none;
}

.d8-close-blocker-item {
    padding: .7rem .75rem;
    border: 1px solid rgba(220, 53, 69, .16);
    border-radius: 10px;
    background: #fff;
}

.d8-close-blocker-title {
    color: #7f1d1d;
    font-weight: 800;
}

.d8-close-blocker-meta {
    margin-top: .18rem;
    color: #64748b;
    font-size: .78rem;
}

.d8-close-dialog-actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: .65rem;
    padding: .95rem 1.25rem 1.2rem;
    border-top: 1px solid rgba(148, 163, 184, .18);
    background: #f8fafc;
}

.d8-close-dialog-actions .btn {
    min-width: 150px;
}

.d8-close-dialog-actions .btn:disabled {
    cursor: not-allowed;
    opacity: .5;
}

@media (max-width: 575.98px) {
    .d8-close-dialog-actions {
        display: grid;
        grid-template-columns: 1fr;
    }

    .d8-close-dialog-actions .btn {
        width: 100%;
    }
}

</style>

<div class="row g-4 claim-layout">
    <div class="col-12 col-lg-8 claim-main">


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
                        <select name="status" class="form-select">                            <?php
                                $manualStatusOptions = ['new','in_progress','waiting','overdue','rejected','archived'];

                                if ((string)$claim['status'] === 'closed') {
                                    array_unshift($manualStatusOptions, 'closed');
                                }
                            ?>
                            <?php foreach ($manualStatusOptions as $s): ?>
                                <option value="<?= e($s) ?>" <?= $claim['status'] === $s ? 'selected' : '' ?>>
                                    <?= e(status_label($s)) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <button class="btn btn-outline-primary">Speichern</button>
                    </div>
                    <div class="small text-muted mt-1">
                        Der Abschluss erfolgt nach dem Speichern von <strong>D8</strong> über den Abschlussdialog.
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
            <span class="badge bg-dark" title="Bearbeitungszeitraum: <?= e($claimProcessingLabel) ?>"><?= e(priority_label($claim['priority'])) ?> · <?= e($claimProcessingLabel) ?></span>
            <?= location_badge(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null) ?>
            <span class="text-muted">Partner: <?= e($claim['partner_name']) ?></span>
            <?php foreach ($claimGroups as $group): ?>
                <?= claim_group_badge($group) ?>
            <?php endforeach; ?>
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
            <div class="card-header bg-white fw-bold d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>Stammdaten</span>
                <?php if (can_edit()): ?>
                    <button type="button" class="btn btn-sm btn-outline-primary no-print" id="claimMetaEditOpen" data-claim-meta-open>
                        Stammdaten bearbeiten
                    </button>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-3"><div class="text-muted small">Art</div><strong><?= e(status_label($claim['claim_type'])) ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Bearbeitungszeitraum</div><strong><?= e($claimProcessingLabel) ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Artikelnummer</div><strong><?= e($claim['article_number'] ?: '-') ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Artikelbezeichnung</div><strong><?= e($claim['article_name'] ?: '-') ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Menge betroffen</div><strong><?= e((string)($claim['quantity_affected'] ?? '-')) ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Verantwortlich</div><strong><?= e($claim['responsible_name'] ?? '-') ?></strong></div>
                    <div class="col-md-3"><div class="text-muted small">Gruppen</div>
                        <?php if ($claimGroups): ?>
                            <div class="d-flex flex-wrap gap-1">
                                <?php foreach ($claimGroups as $group): ?><?= claim_group_badge($group) ?><?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <strong>-</strong>
                        <?php endif; ?>
                    </div>
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


<?php if ($claimViewCanSeeAnalytics && $qmFieldsEnabled): ?>
<div class="row g-4 mb-4">
    <div class="col-xl-5">
        <div class="card h-100 qm-card">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center gap-2">
                <span>QM-Klassifizierung</span>
                <?= qm_repeat_badge($qmRepeatLevel) ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <div class="text-muted small">Fehlerkategorie</div>
                        <strong><?= e($qmClaimLabels['error_category'] ?? '-') ?></strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Fehlerbild</div>
                        <strong><?= e($qmClaimLabels['error_pattern'] ?? '-') ?></strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Prozessbereich</div>
                        <strong><?= e($qmClaimLabels['process_area'] ?? '-') ?></strong>
                    </div>
                    <div class="col-md-6">
                        <div class="text-muted small">Ursachenkategorie</div>
                        <strong><?= e($qmClaimLabels['root_cause_category'] ?? '-') ?></strong>
                    </div>
                </div>

                <hr>
                <div class="small text-muted">
                    <?= e(qm_effectiveness_hint($qmSimilarClaims)) ?>
                </div>
            </div>
        </div>
    </div>

    <div class="col-xl-7">
        <div class="card h-100 qm-card">
            <div class="card-header bg-white fw-bold">
                Ähnliche Berichte / mögliche Wiederholfehler <span class="text-muted fw-normal">(letzte 90 Tage)</span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Reklamation</th>
                        <th>Fehlerbild</th>
                        <th>Bereich</th>
                        <th class="text-end">Ähnlichkeit</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($qmSimilarClaims as $similar): ?>
                        <tr>
                            <td>
                                <a href="claim_view.php?id=<?= (int)$similar['id'] ?>" class="fw-bold text-decoration-none">
                                    <?= e((string)$similar['claim_number']) ?>
                                </a>
                                <div class="small text-muted"><?= e((string)$similar['claim_date']) ?> · <?= status_badge((string)$similar['status']) ?></div>
                            </td>
                            <td>
                                <?= e(qm_label(qm_error_pattern_options(), $similar['error_pattern'] ?? '')) ?>
                                <div class="small text-muted"><?= e((string)$similar['short_description']) ?></div>
                            </td>
                            <td><?= e(qm_label(qm_process_area_options(), $similar['process_area'] ?? '')) ?></td>
                            <td class="text-end fw-bold"><?= (int)($similar['similarity_score'] ?? 0) ?>%</td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$qmSimilarClaims): ?>
                        <tr>
                            <td colspan="4" class="text-center text-muted py-4">
                                Keine auffälligen Wiederholfehler gefunden.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php elseif ($claimViewCanSeeAnalytics && is_admin()): ?>
<div class="alert alert-warning">
    QM-Wiederholfehler-Erkennung ist noch nicht aktiv. Bitte die SQL-Migration ausführen.
</div>
<?php endif; ?>





<?php if (can_edit()): ?>
<div class="modal fade claim-meta-edit-fallback" id="claimMetaEditModal" tabindex="-1" aria-hidden="true" data-claim-meta-modal>
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <form method="post" action="claim_meta_save.php" class="modal-content claim-meta-edit-modal">
            <?= csrf_field() ?>
            <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">

            <div class="modal-header">
                <div>
                    <h5 class="modal-title">Stammdaten bearbeiten</h5>
                    <div class="small text-muted">Änderungen werden in der Historie protokolliert.</div>
                </div>
                <button type="button" class="btn-close" data-claim-meta-close aria-label="Schließen"></button>
            </div>

            <div class="modal-body">
                <div class="claim-meta-edit-section">
                    <div class="claim-meta-edit-title">Grunddaten</div>
                    <div class="row g-3">
                        <div class="col-lg-6">
                            <label class="form-label">Titel *</label>
                            <input name="short_description" class="form-control" required maxlength="255" value="<?= e($claim['short_description']) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Art *</label>
                            <select name="claim_type" class="form-select" required>
                                <option value="customer" <?= $claim['claim_type'] === 'customer' ? 'selected' : '' ?>>Kundenreklamation</option>
                                <option value="supplier" <?= $claim['claim_type'] === 'supplier' ? 'selected' : '' ?>>Lieferantenreklamation</option>
                                <option value="internal" <?= $claim['claim_type'] === 'internal' ? 'selected' : '' ?>>Interne Reklamation</option>
                            </select>
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Priorität *</label>
                            <select name="priority" class="form-select" required>
                                <option value="low" <?= $claim['priority'] === 'low' ? 'selected' : '' ?>>Niedrig · 10 Tage</option>
                                <option value="medium" <?= $claim['priority'] === 'medium' ? 'selected' : '' ?>>Mittel · 7 Tage</option>
                                <option value="high" <?= $claim['priority'] === 'high' ? 'selected' : '' ?>>Hoch · 5 Tage</option>
                                <option value="critical" <?= $claim['priority'] === 'critical' ? 'selected' : '' ?>>Kritisch · 2 Tage</option>
                            </select>
                            <div class="form-text">Aktuell: <?= e($claimProcessingLabel) ?></div>
                        </div>

                        <div class="col-lg-6">
                            <label class="form-label">Kunde / Lieferant / Bereich *</label>
                            <input name="partner_name" class="form-control" required maxlength="190" value="<?= e($claim['partner_name']) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Reklamationsdatum *</label>
                            <input type="date" name="claim_date" class="form-control" required value="<?= e($claim['claim_date']) ?>">
                        </div>

                        <div class="col-md-3">
                            <label class="form-label">Verantwortlich</label>
                            <select name="responsible_user_id" class="form-select">
                                <option value="">Nicht zugewiesen</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= (int)($claim['responsible_user_id'] ?? 0) === (int)$u['id'] ? 'selected' : '' ?>>
                                        <?= e($u['name']) ?><?= !empty($u['role']) ? ' (' . e($u['role']) . ')' : '' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text">Hier kannst du den Verantwortlichen später neu zuweisen.</div>
                        </div>
                    </div>
                </div>

                <div class="claim-meta-edit-section">
                    <div class="claim-meta-edit-title">Artikel / Bezug</div>
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Artikelnummer</label>
                            <input name="article_number" class="form-control" maxlength="120" value="<?= e($claim['article_number'] ?: '') ?>">
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Artikelbezeichnung</label>
                            <input name="article_name" class="form-control" maxlength="190" value="<?= e($claim['article_name'] ?: '') ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Menge betroffen</label>
                            <input name="quantity_affected" class="form-control" maxlength="60" value="<?= e((string)($claim['quantity_affected'] ?? '')) ?>">
                        </div>

                        <div class="col-md-2">
                            <label class="form-label">Lieferdatum</label>
                            <input type="date" name="delivery_date" class="form-control" value="<?= e($claim['delivery_date'] ?: '') ?>">
                        </div>
                    </div>
                </div>

                <div class="claim-meta-edit-section">
                    <div class="claim-meta-edit-title">Quelle / Workbench / Sonstiges</div>
                    <div class="row g-3">
                        <div class="col-md-3">
                            <label class="form-label">Quelle / Modul</label>
                            <select name="source_module" class="form-select">
                                <?php
                                    $sourceOptions = [
                                        '' => 'Keine Quelle',
                                        'warenausgang' => 'Warenausgang',
                                        'wareneingang' => 'Wareneingang',
                                        'kommi' => 'Kommi',
                                        'cmr' => 'CMR',
                                        'urlaub' => 'Urlaub',
                                        'mitarbeiter' => 'Mitarbeiter',
                                        'schadensmeldung' => 'Schadensmeldung',
                                        'sonstiges' => 'Sonstiges',
                                    ];
                                ?>
                                <?php foreach ($sourceOptions as $value => $label): ?>
                                    <option value="<?= e($value) ?>" <?= (string)($claim['source_module'] ?? '') === (string)$value ? 'selected' : '' ?>>
                                        <?= e($label) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label">Quellnummer / Quellenangabe</label>
                            <input name="source_number" class="form-control" maxlength="190" value="<?= e($claim['source_number'] ?: '') ?>">
                        </div>

                        <div class="col-md-5">
                            <label class="form-label">Quell-Link</label>
                            <input name="source_url" class="form-control" maxlength="500" value="<?= e($claim['source_url'] ?: '') ?>" placeholder="https://...">
                        </div>
                    </div>
                </div>

                <div class="claim-meta-edit-section">
                    <div class="claim-meta-edit-title">Problembeschreibung</div>
                    <textarea name="problem_description" rows="6" class="form-control" placeholder="Problem / Vorfall beschreiben..."><?= e($claim['problem_description'] ?: '') ?></textarea>
                </div>


                <?php if ($claimViewCanSeeAnalytics && $qmFieldsEnabled): ?>
                    <div class="claim-meta-edit-section">
                        <div class="claim-meta-edit-title">QM-Klassifizierung</div>
                        <div class="row g-3">
                            <div class="col-md-3">
                                <label class="form-label">Fehlerkategorie</label>
                                <select name="error_category" class="form-select">
                                    <?= qm_select_options(qm_error_category_options(), $claim['error_category'] ?? '') ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Fehlerbild</label>
                                <select name="error_pattern" class="form-select">
                                    <?= qm_select_options(qm_error_pattern_options(), $claim['error_pattern'] ?? '') ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Prozessbereich</label>
                                <select name="process_area" class="form-select">
                                    <?= qm_select_options(qm_process_area_options(), $claim['process_area'] ?? '') ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Ursachenkategorie</label>
                                <select name="root_cause_category" class="form-select">
                                    <?= qm_select_options(qm_root_cause_category_options(), $claim['root_cause_category'] ?? '') ?>
                                </select>
                            </div>
                        </div>
                        <div class="form-text mt-2">
                            Diese Einordnung bildet die Grundlage für Wiederholfehler-Erkennung und spätere KI-Auswertung.
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($allClaimGroups): ?>
                    <div class="claim-meta-edit-section">
                        <div class="claim-meta-edit-title">Gruppen</div>
                        <div class="claim-meta-group-grid">
                            <?php foreach ($allClaimGroups as $group): ?>
                                <?php
                                    $groupId = (int)$group['id'];
                                    $isAssigned = in_array($groupId, $assignedGroupIds, true);
                                    $isQuality = claim_view_group_is_quality($group);
                                ?>

                                <?php if ($isQuality): ?>
                                    <input type="hidden" name="group_ids[]" value="<?= $groupId ?>">
                                <?php endif; ?>

                                <label class="claim-meta-group-option <?= $isQuality ? 'is-locked' : '' ?>">
                                    <input class="form-check-input"
                                           type="checkbox"
                                           name="<?= $isQuality ? 'group_ids_quality_locked[]' : 'group_ids[]' ?>"
                                           value="<?= $groupId ?>"
                                           <?= ($isAssigned || $isQuality) ? 'checked' : '' ?>
                                           <?= $isQuality ? 'disabled' : '' ?>>
                                    <span>
                                        <span class="claim-meta-group-name"><?= e($group['name']) ?></span>
                                        <span class="claim-meta-group-type"><?= !empty($group['standort_id']) ? 'Standortgruppe' : 'Global' ?><?= $isQuality ? ' · Pflichtgruppe' : '' ?></span>
                                    </span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text mt-2">Qualität bleibt als Pflichtgruppe immer gesetzt.</div>
                    </div>
                <?php endif; ?>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-claim-meta-close>Abbrechen</button>
                <button class="btn btn-primary">Stammdaten speichern</button>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>


<div class="card mb-4">
    <div class="card-header bg-white fw-bold">8D-Schritte</div>
    <div class="accordion accordion-flush" id="stepsAccordion">
        <?php foreach ($steps as $index => $step): ?>
            <?php
                $stepStatusClass = 'is-step-' . preg_replace('/[^a-z0-9_-]/i', '', (string)$step['status']);
                $isCurrentAccordion = $index === $currentStepIndex;
                $isInitiallyOpen = $isCurrentAccordion;

                /*
                 * Nach dem Abschluss dieses Schrittes soll der nächste noch
                 * offene D-Schritt geöffnet werden. Bereits erledigte Schritte
                 * werden dabei übersprungen.
                 */
                $nextOpenStepKey = '';

                for ($nextIndex = $index + 1, $stepTotal = count($steps); $nextIndex < $stepTotal; $nextIndex++) {
                    if ((string)($steps[$nextIndex]['status'] ?? '') !== 'done') {
                        $nextOpenStepKey = (string)($steps[$nextIndex]['step_key'] ?? '');
                        break;
                    }
                }

                if ($nextOpenStepKey === '') {
                    foreach ($steps as $candidateIndex => $candidateStep) {
                        if ($candidateIndex === $index) {
                            continue;
                        }

                        if ((string)($candidateStep['status'] ?? '') !== 'done') {
                            $nextOpenStepKey = (string)($candidateStep['step_key'] ?? '');
                            break;
                        }
                    }
                }

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
                            <?php
                                $stepSaveFormId = 'stepSaveForm' . preg_replace('/[^A-Za-z0-9_-]/', '', (string)$step['step_key']);
                                $stepDraftKey = 'claim-step-content-' . (int)$claim['id'] . '-' . (string)$step['step_key'];
                            ?>
                            <div class="mb-3">
                                <label class="form-label">Inhalt / Dokumentation</label>
                                <textarea
                                    name="content"
                                    rows="7"
                                    class="form-control js-step-content-draft"
                                    form="<?= e($stepSaveFormId) ?>"
                                    data-draft-key="<?= e($stepDraftKey) ?>"
                                    placeholder="Dokumentation für <?= e($step['step_key']) ?> eintragen..."
                                ><?= e($step['content']) ?></textarea>
                                <div class="form-text">
                                    Fotos und Nachweise kannst du anschließend hochladen. Dein noch nicht gespeicherter Text bleibt dabei im Browser erhalten.
                                </div>
                            </div>
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
                                <form
                                    method="post"
                                    action="upload_file.php"
                                    enctype="multipart/form-data"
                                    class="step-upload-form row g-2 align-items-end mb-2 js-step-file-stage-form"
                                    data-claim-id="<?= (int)$claim['id'] ?>"
                                    data-step-key="<?= e((string)$step['step_key']) ?>"
                                >
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                    <input type="hidden" name="step_key" value="<?= e($step['step_key']) ?>">

                                    <div class="col-lg-4">
                                        <label class="form-label small">Foto / Datei</label>
                                        <input
                                            type="file"
                                            name="file"
                                            class="form-control form-control-sm js-step-file-input"
                                            multiple
                                            required
                                        >
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
                                        <button class="btn btn-sm btn-outline-primary">
                                            Datei vormerken
                                        </button>
                                    </div>
                                </form>

                                <div class="step-upload-hint">
                                    Die Datei wird zunächst nur <strong>lokal vorgemerkt</strong>.
                                    Der echte Upload erfolgt gemeinsam mit „D-Schritt speichern“.
                                </div>

                                <div
                                    class="step-staged-files d-none js-step-staged-files"
                                    data-step-key="<?= e((string)$step['step_key']) ?>"
                                >
                                    <div class="step-staged-files-head">
                                        <div class="step-staged-files-title">
                                            Vorgemerkte Dateien
                                        </div>
                                        <span class="badge bg-primary-subtle text-primary-emphasis js-step-staged-count">
                                            0
                                        </span>
                                    </div>
                                    <div class="step-staged-file-list js-step-staged-list"></div>
                                </div>
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
                        <div class="mb-3">
                            <h3 class="h6 fw-bold mb-1">
                                Zusätzliche Aufgabe an Mitarbeiter vergeben
                            </h3>
                            <div class="small text-muted">
                                Nur verwenden, wenn du bei <?= e($step['step_key']) ?> Unterstützung,
                                eine Prüfung oder eine Rückmeldung von einer anderen Person benötigst.
                                Du selbst bist hier bewusst nicht auswählbar.
                            </div>
                        </div>

                        <?php if (can_edit() && $usersForActionAssignment): ?>
                            <form method="post" action="action_store.php" class="row g-2">
                                <?= csrf_field() ?>
                                <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                <input type="hidden" name="step_key" value="<?= e($step['step_key']) ?>">

                                <div class="col-md-3">
                                    <label class="form-label small">Aufgabe</label>
                                    <input
                                        name="title"
                                        class="form-control"
                                        placeholder="Was soll erledigt werden?"
                                        required
                                    >
                                </div>

                                <div class="col-md-3">
                                    <label class="form-label small">Beschreibung</label>
                                    <input
                                        name="description"
                                        class="form-control"
                                        placeholder="Hinweise oder Hintergrund"
                                    >
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label small">Mitarbeiter</label>
                                    <select name="responsible_user_id" class="form-select" required>
                                        <option value="">Bitte auswählen</option>
                                        <?php foreach ($usersForActionAssignment as $u): ?>
                                            <option value="<?= (int)$u['id'] ?>">
                                                <?= e($u['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <div class="col-md-2">
                                    <label class="form-label small">Frist</label>
                                    <input
                                        type="date"
                                        name="due_date"
                                        class="form-control"
                                        value="<?= e($defaultActionDueDate) ?>"
                                    >
                                    <div class="form-text">Standard: <?= e($claimProcessingLabel) ?></div>
                                </div>

                                <div class="col-md-2 d-grid align-self-end">
                                    <button class="btn btn-outline-primary">
                                        Aufgabe vergeben
                                    </button>
                                </div>
                            </form>
                        <?php elseif (can_edit()): ?>
                            <div class="d-flex justify-content-center">
                                <div class="action-assignment-empty-note text-center">
                                    Für diesen Standort ist aktuell kein anderer Mitarbeiter auswählbar.
                                </div>
                            </div>
                        <?php endif; ?>

                        <?php if (can_edit()): ?>
                            <div class="step-final-save mt-4 pt-3 border-top">
                                <form
                                    method="post"
                                    action="claim_step_save.php"
                                    id="<?= e($stepSaveFormId) ?>"
                                    class="row g-3 align-items-center js-step-final-save-form"
                                    data-draft-key="<?= e($stepDraftKey) ?>"
                                    data-claim-id="<?= (int)$claim['id'] ?>"
                                    data-current-step-key="<?= e((string)$step['step_key']) ?>"
                                    data-next-step-key="<?= e($nextOpenStepKey) ?>"
                                    data-is-final-step="<?= (string)$step['step_key'] === 'D8' ? '1' : '0' ?>"
                                    data-claim-closed="<?= (string)$claim['status'] === 'closed' ? '1' : '0' ?>"
                                >
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
                                    <input type="hidden" name="step_key" value="<?= e($step['step_key']) ?>">
                                    <input type="hidden" name="status" value="done">

                                    <div class="col-md">
                                        <div class="fw-bold">
                                            <?= e($step['step_key']) ?> abschließen
                                        </div>
                                        <div class="small text-muted">
                                            Mit dem Speichern wird dieser D-Schritt automatisch als erledigt markiert
                                            und der nächste offene D-Schritt geöffnet.
                                        </div>
                                    </div>

                                    <div class="col-md-auto d-grid">
                                        <button class="btn btn-primary px-4">
                                            <?= $step['status'] === 'done'
                                                ? 'Änderungen speichern'
                                                : 'D-Schritt speichern & erledigen' ?>
                                        </button>
                                    </div>
                                </form>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php if ($actionGroups): ?>
<div id="actions" class="mb-4">
    <?php foreach ($actionGroups as $actionGroup): ?>
        <div class="card mb-4">
            <div class="card-header bg-white d-flex flex-column flex-lg-row justify-content-between gap-2 align-items-lg-center">
                <div>
                    <div class="fw-bold"><?= e((string)$actionGroup['title']) ?></div>
                    <div class="small text-muted">
                        <?= e((string)$actionGroup['description']) ?>
                        Bearbeitungszeitraum nach Priorität:
                        <?= e(priority_label($claimPriority)) ?> = <?= e($claimProcessingLabel) ?>.
                    </div>
                </div>
                <?php $actionGroupCount = count($actionGroup['actions']); ?>
                <span class="badge bg-light text-dark">
                    <?= $actionGroupCount ?>
                    Maßnahme<?= $actionGroupCount === 1 ? '' : 'n' ?>
                </span>
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
                    <?php foreach ($actionGroup['actions'] as $action): ?>
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

<button
  type="button"
  class="btn btn-sm btn-outline-primary btn-create-service-from-measure"
  data-measure-id="<?= (int)$action['id'] ?>"
>
  🚐 Serviceeinsatz
</button>

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
                                                            <?php
                                                                $actionResponsibleId = (int)($action['responsible_user_id'] ?? 0);
                                                                $actionKeepsCurrentUser = $actionResponsibleId === $claimViewUserId;
                                                            ?>

                                                            <?php if ($actionKeepsCurrentUser): ?>
                                                                <input
                                                                    type="hidden"
                                                                    name="responsible_user_id"
                                                                    value="<?= $actionResponsibleId ?>"
                                                                >
                                                                <select class="form-select" disabled>
                                                                    <option selected>
                                                                        Bestehende Zuordnung bleibt unverändert
                                                                    </option>
                                                                </select>
                                                            <?php else: ?>
                                                                <select name="responsible_user_id" class="form-select">
                                                                    <option value="">Nicht zugewiesen</option>
                                                                    <?php foreach ($usersForActionAssignment as $u): ?>
                                                                        <option
                                                                            value="<?= (int)$u['id'] ?>"
                                                                            <?= $actionResponsibleId === (int)$u['id'] ? 'selected' : '' ?>
                                                                        >
                                                                            <?= e($u['name']) ?>
                                                                        </option>
                                                                    <?php endforeach; ?>
                                                                </select>
                                                            <?php endif; ?>
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
                    </tbody>
                </table>
            </div>
                </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

    </div><!-- /.claim-main -->

    <div class="col-12 col-lg-4 claim-side" id="files">
        <div class="claim-side-sticky">
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
        </div><!-- /.claim-side-sticky -->
    </div><!-- /.claim-side -->
</div><!-- /.claim-layout -->

<style>
.service-dialog-backdrop {
  position: fixed;
  inset: 0;
  z-index: 3000;
  display: none;
  align-items: center;
  justify-content: center;
  padding: 1rem;
  background: rgba(15, 23, 42, .48);
  backdrop-filter: blur(5px);
  -webkit-backdrop-filter: blur(5px);
}

.service-dialog-backdrop.is-open {
  display: flex;
}

.service-dialog-box {
  width: min(520px, 100%);
  background: #fff;
  border-radius: 22px;
  box-shadow: 0 24px 70px rgba(15, 23, 42, .28);
  overflow: hidden;
  border: 1px solid rgba(226, 232, 240, .9);
  animation: serviceDialogIn .16s ease-out;
}

@keyframes serviceDialogIn {
  from {
    opacity: 0;
    transform: translateY(12px) scale(.98);
  }
  to {
    opacity: 1;
    transform: translateY(0) scale(1);
  }
}

.service-dialog-head {
  display: flex;
  gap: .9rem;
  align-items: flex-start;
  padding: 1.25rem 1.25rem .6rem;
}

.service-dialog-icon {
  width: 44px;
  height: 44px;
  border-radius: 16px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  flex: 0 0 auto;
  font-size: 1.35rem;
  background: rgba(13, 110, 253, .1);
  color: #0d6efd;
}

.service-dialog-backdrop.is-success .service-dialog-icon {
  background: rgba(25, 135, 84, .12);
  color: #198754;
}

.service-dialog-backdrop.is-error .service-dialog-icon {
  background: rgba(220, 53, 69, .12);
  color: #dc3545;
}

.service-dialog-backdrop.is-warning .service-dialog-icon {
  background: rgba(245, 158, 11, .14);
  color: #b45309;
}

.service-dialog-title {
  font-size: 1.15rem;
  font-weight: 800;
  color: #0f172a;
  margin: 0 0 .25rem;
  line-height: 1.25;
}

.service-dialog-text {
  color: #64748b;
  margin: 0;
  line-height: 1.45;
}

.service-dialog-actions {
  display: flex;
  gap: .65rem;
  justify-content: flex-end;
  padding: 1rem 1.25rem 1.25rem;
  background: #f8fafc;
  border-top: 1px solid #eef2f7;
}

.service-dialog-actions .btn {
  min-width: 125px;
  border-radius: 999px;
  font-weight: 700;
}

@media (max-width: 575.98px) {
  .service-dialog-box {
    border-radius: 18px;
  }

  .service-dialog-head {
    padding: 1rem 1rem .55rem;
  }

  .service-dialog-actions {
    display: grid;
    grid-template-columns: 1fr;
    padding: .9rem 1rem 1rem;
  }

  .service-dialog-actions .btn {
    width: 100%;
  }
}
</style>

<div id="serviceDialog" class="service-dialog-backdrop no-print" aria-hidden="true">
  <div class="service-dialog-box" role="dialog" aria-modal="true" aria-labelledby="serviceDialogTitle" aria-describedby="serviceDialogText">
    <div class="service-dialog-head">
      <div class="service-dialog-icon" id="serviceDialogIcon">🚐</div>
      <div>
        <h5 class="service-dialog-title" id="serviceDialogTitle">Serviceeinsatz erstellen</h5>
        <p class="service-dialog-text" id="serviceDialogText">Aus dieser 8D-Maßnahme einen Serviceeinsatz in der Workbench erstellen?</p>
      </div>
    </div>
    <div class="service-dialog-actions">
      <button type="button" class="btn btn-outline-secondary" id="serviceDialogCancel">Abbrechen</button>
      <button type="button" class="btn btn-primary" id="serviceDialogConfirm">Erstellen</button>
    </div>
  </div>
</div>

<script>
function serviceDialog(options = {}) {
  return new Promise((resolve) => {
    const backdrop = document.getElementById('serviceDialog');
    const titleEl = document.getElementById('serviceDialogTitle');
    const textEl = document.getElementById('serviceDialogText');
    const iconEl = document.getElementById('serviceDialogIcon');
    const cancelBtn = document.getElementById('serviceDialogCancel');
    const confirmBtn = document.getElementById('serviceDialogConfirm');

    if (!backdrop || !titleEl || !textEl || !iconEl || !cancelBtn || !confirmBtn) {
      resolve(window.confirm(options.message || 'Fortfahren?'));
      return;
    }

    const type = options.type || 'info';

    backdrop.classList.remove('is-success', 'is-error', 'is-info', 'is-warning');
    backdrop.classList.add('is-open', 'is-' + type);
    backdrop.setAttribute('aria-hidden', 'false');

    titleEl.textContent = options.title || 'Hinweis';
    textEl.textContent = options.message || '';
    iconEl.textContent = options.icon || (
      type === 'success' ? '✅' :
      type === 'error' ? '⚠️' :
      type === 'warning' ? '🗑️' :
      'ℹ️'
    );

    confirmBtn.textContent = options.confirmText || 'OK';
    confirmBtn.className = 'btn ' + (options.confirmClass || (
      type === 'error' ? 'btn-danger' :
      type === 'warning' ? 'btn-danger' :
      type === 'success' ? 'btn-success' :
      'btn-primary'
    ));

    cancelBtn.textContent = options.cancelText || 'Abbrechen';
    cancelBtn.style.display = options.showCancel === false ? 'none' : '';

    const close = (value) => {
      backdrop.classList.remove('is-open');
      backdrop.setAttribute('aria-hidden', 'true');

      confirmBtn.removeEventListener('click', onConfirm);
      cancelBtn.removeEventListener('click', onCancel);
      backdrop.removeEventListener('click', onBackdropClick);
      document.removeEventListener('keydown', onKeyDown);

      resolve(value);
    };

    const onConfirm = () => close(true);
    const onCancel = () => close(false);

    const onBackdropClick = (event) => {
      if (event.target === backdrop && options.closeOnBackdrop !== false) {
        close(false);
      }
    };

    const onKeyDown = (event) => {
      if (event.key === 'Escape') {
        close(false);
      }
    };

    confirmBtn.addEventListener('click', onConfirm);
    cancelBtn.addEventListener('click', onCancel);
    backdrop.addEventListener('click', onBackdropClick);
    document.addEventListener('keydown', onKeyDown);

    setTimeout(() => {
      confirmBtn.focus();
    }, 40);
  });
}

/**
 * Ersetzt alle normalen data-confirm Browsermeldungen durch den mittigen Dialog.
 * Betrifft z. B.:
 * - Erinnerung senden
 * - Maßnahme löschen
 */
document.addEventListener('click', async (ev) => {
  const confirmElement = ev.target.closest('[data-confirm]');

  if (!confirmElement) {
    return;
  }

  // Serviceeinsatz hat seinen eigenen Dialog weiter unten.
  if (confirmElement.classList.contains('btn-create-service-from-measure')) {
    return;
  }

  ev.preventDefault();
  ev.stopPropagation();

  const message = confirmElement.getAttribute('data-confirm') || 'Aktion wirklich ausführen?';
  const lowerMessage = message.toLowerCase();

  const isDelete = lowerMessage.includes('löschen') || lowerMessage.includes('delete');
  const isReminder = lowerMessage.includes('erinnerung') || lowerMessage.includes('erinnern') || lowerMessage.includes('e-mail');

  const ok = await serviceDialog({
    type: isDelete ? 'warning' : 'info',
    icon: isDelete ? '🗑️' : (isReminder ? '📧' : '❓'),
    title: isDelete ? 'Wirklich löschen?' : (isReminder ? 'Erinnerung senden?' : 'Bitte bestätigen'),
    message: message,
    confirmText: isDelete ? 'Ja, löschen' : (isReminder ? 'Ja, senden' : 'Ja'),
    cancelText: 'Abbrechen',
    confirmClass: isDelete ? 'btn-danger' : 'btn-primary'
  });

  if (!ok) {
    return;
  }

  const form = confirmElement.closest('form');

  if (form) {
    if (form.requestSubmit) {
      form.requestSubmit();
    } else {
      form.submit();
    }
    return;
  }

  const href = confirmElement.getAttribute('href');

  if (href) {
    window.location.href = href;
  }
}, true);

document.addEventListener('click', async (ev) => {
  const btn = ev.target.closest('.btn-create-service-from-measure');

  if (!btn) {
    return;
  }

  ev.preventDefault();
  ev.stopPropagation();

  const row = btn.closest('tr');

  const measureId = Number(
    btn.dataset.measureId ||
    btn.getAttribute('data-measure-id') ||
    row?.dataset.measureId ||
    row?.dataset.actionId ||
    row?.dataset.id ||
    0
  );

  if (!measureId) {
    console.error('Button ohne Maßnahmen-ID:', btn);
    console.error('Zeile:', row);

    await serviceDialog({
      type: 'error',
      title: 'Maßnahmen-ID fehlt',
      message: 'Bitte prüfen: Der Button braucht data-measure-id="ID".',
      confirmText: 'OK',
      showCancel: false
    });

    return;
  }

  const shouldCreate = await serviceDialog({
    type: 'info',
    icon: '🚐',
    title: 'Serviceeinsatz erstellen?',
    message: 'Aus dieser 8D-Maßnahme einen Serviceeinsatz in der Workbench erstellen?',
    confirmText: 'Ja, erstellen',
    cancelText: 'Abbrechen',
    confirmClass: 'btn-primary'
  });

  if (!shouldCreate) {
    return;
  }

  const oldText = btn.innerHTML;

  try {
    btn.disabled = true;
    btn.innerHTML = 'Erstelle ...';

    const res = await fetch('./create_service_from_measure.php', {
      method: 'POST',
      credentials: 'same-origin',
      cache: 'no-store',
      headers: {
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({
        measure_id: measureId
      })
    });

    const responseText = await res.text();

    let json;

    try {
      json = JSON.parse(responseText);
    } catch (e) {
      console.error('Keine JSON-Antwort vom Server:', responseText);
      throw new Error('Server liefert kein JSON. Antwort beginnt mit: ' + responseText.slice(0, 300));
    }

    console.log('Serviceeinsatz-Antwort:', json);

    if (!res.ok || !json.ok) {
      throw new Error(json.error || 'Serviceeinsatz konnte nicht erstellt werden.');
    }

    const targetUrl = json.service_url || json.url || '';

    if (!targetUrl) {
      throw new Error('Workbench hat keine Service-URL zurückgegeben. Antwort: ' + JSON.stringify(json).slice(0, 500));
    }

    const copied = Number(json.copied_images || 0);

    const successMessage = copied > 0
      ? 'Serviceeinsatz wurde erstellt. ' + copied + ' Bild(er) wurden übernommen. Jetzt öffnen?'
      : 'Serviceeinsatz wurde erstellt. Jetzt öffnen?';

    const shouldOpen = await serviceDialog({
      type: 'success',
      icon: '✅',
      title: 'Serviceeinsatz erstellt',
      message: successMessage,
      confirmText: 'Jetzt öffnen',
      cancelText: 'Schließen',
      confirmClass: 'btn-success'
    });

    if (shouldOpen) {
      window.location.href = targetUrl;
    }

  } catch (e) {
    await serviceDialog({
      type: 'error',
      title: 'Fehler',
      message: e.message,
      confirmText: 'OK',
      showCancel: false,
      confirmClass: 'btn-danger'
    });
  } finally {
    btn.disabled = false;
    btn.innerHTML = oldText;
  }
});
</script>



<?php if (can_edit()): ?>
<form
    id="claimAutoCloseForm"
    method="post"
    action="claim_status_save.php"
    class="d-none"
>
    <?= csrf_field() ?>
    <input type="hidden" name="claim_id" value="<?= (int)$claim['id'] ?>">
    <input type="hidden" name="status" value="closed">
    <input type="hidden" name="close_source" value="d8">
</form>

<div
    id="d8CloseDialog"
    class="d8-close-dialog is-hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="d8CloseDialogTitle"
>
    <div class="d8-close-dialog-backdrop" data-d8-close-cancel></div>

    <div class="d8-close-dialog-panel">
        <div class="d8-close-dialog-head">
            <div>
                <div class="d8-close-dialog-kicker">D8 · Abschluss</div>
                <h2 class="d8-close-dialog-title" id="d8CloseDialogTitle">
                    Soll die Reklamation abgeschlossen werden?
                </h2>
            </div>

            <button
                type="button"
                class="btn-close"
                data-d8-close-cancel
                aria-label="Abschlussdialog schließen"
            ></button>
        </div>

        <div class="d8-close-dialog-body">
            <p class="mb-3">
                D8 wird jetzt gespeichert und als erledigt markiert.
                Du kannst anschließend nur D8 speichern oder die komplette
                Reklamation abschließen.
            </p>

            <?php if ($closureBlockingActions): ?>
                <div class="d8-close-dialog-blocked">
                    <strong>Abschluss derzeit nicht möglich.</strong><br>
                    Mindestens eine vergebene Maßnahme wurde von der betroffenen
                    Person noch nicht als erledigt markiert.

                    <ul class="d8-close-blocker-list">
                        <?php foreach ($closureBlockingActions as $blockingAction): ?>
                            <li class="d8-close-blocker-item">
                                <div class="d8-close-blocker-title">
                                    <?= e((string)($blockingAction['title'] ?? 'Maßnahme')) ?>
                                </div>
                                <div class="d8-close-blocker-meta">
                                    Verantwortlich:
                                    <?= e((string)($blockingAction['responsible_name'] ?? 'Nicht zugewiesen')) ?>
                                    · Status:
                                    <?= e(status_label((string)($blockingAction['status'] ?? 'open'))) ?>
                                    <?php if (!empty($blockingAction['due_date'])): ?>
                                        · Frist:
                                        <?= e(date('d.m.Y', strtotime((string)$blockingAction['due_date']))) ?>
                                    <?php endif; ?>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php else: ?>
                <div class="d8-close-dialog-note">
                    Alle zusätzlichen Maßnahmen sind erledigt. Beim Abschluss
                    erhalten Verantwortliche, Ersteller, Gruppenmitglieder und
                    an Maßnahmen beteiligte Personen automatisch eine E-Mail
                    mit dem Hinweis, dass die Reklamation erledigt wurde.
                </div>
            <?php endif; ?>
        </div>

        <div class="d8-close-dialog-actions">
            <button
                type="button"
                class="btn btn-outline-secondary"
                data-d8-close-cancel
            >
                Zurück
            </button>

            <button
                type="button"
                class="btn btn-outline-primary"
                id="d8SaveOnlyButton"
            >
                Nur D8 speichern
            </button>

            <button
                type="button"
                class="btn btn-success"
                id="d8CloseClaimButton"
                <?= $closureBlockingActions ? 'disabled' : '' ?>
                title="<?= $closureBlockingActions
                    ? 'Abschluss erst möglich, wenn alle Maßnahmen erledigt sind.'
                    : 'D8 speichern und Reklamation abschließen.' ?>"
            >
                Ja, Reklamation abschließen
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<div
    id="claimCenterNotice"
    class="claim-center-notice is-hidden"
    role="dialog"
    aria-modal="true"
    aria-labelledby="claimCenterNoticeTitle"
    aria-describedby="claimCenterNoticeBody"
>
    <div class="claim-center-notice-backdrop" data-claim-notice-close></div>

    <div class="claim-center-notice-panel" id="claimCenterNoticePanel">
        <div class="claim-center-notice-head">
            <h2 class="claim-center-notice-title" id="claimCenterNoticeTitle">
                Hinweis
            </h2>

            <button
                type="button"
                class="btn-close"
                data-claim-notice-close
                aria-label="Hinweis schließen"
            ></button>
        </div>

        <div class="claim-center-notice-body" id="claimCenterNoticeBody"></div>

        <div class="claim-center-notice-actions">
            <button
                type="button"
                class="btn btn-primary px-4"
                data-claim-notice-close
            >
                Verstanden
            </button>
        </div>
    </div>
</div>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const modal = document.getElementById('claimMetaEditModal');
    const openButton = document.querySelector('[data-claim-meta-open]');
    const closeButtons = document.querySelectorAll('[data-claim-meta-close]');
    let backdrop = null;
    let lastFocus = null;

    if (!modal || !openButton) {
        return;
    }

    function createBackdrop() {
        backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop fade show claim-meta-edit-backdrop';
        backdrop.setAttribute('data-claim-meta-backdrop', '1');
        document.body.appendChild(backdrop);
        backdrop.addEventListener('click', closeModal);
    }

    function openModal() {
        lastFocus = document.activeElement;

        if (!backdrop) {
            createBackdrop();
        }

        modal.style.display = 'block';
        modal.removeAttribute('aria-hidden');
        modal.setAttribute('aria-modal', 'true');
        modal.setAttribute('role', 'dialog');

        void modal.offsetWidth;

        modal.classList.add('show');
        document.body.classList.add('modal-open');

        const firstInput = modal.querySelector('input:not([type="hidden"]), select, textarea, button');
        if (firstInput) {
            setTimeout(function () {
                firstInput.focus();
            }, 60);
        }
    }

    function closeModal() {
        modal.classList.remove('show');
        modal.setAttribute('aria-hidden', 'true');
        modal.removeAttribute('aria-modal');
        modal.removeAttribute('role');
        modal.style.display = 'none';

        document.body.classList.remove('modal-open');

        if (backdrop) {
            backdrop.remove();
            backdrop = null;
        }

        if (lastFocus && typeof lastFocus.focus === 'function') {
            lastFocus.focus();
        }
    }

    openButton.addEventListener('click', function (event) {
        event.preventDefault();
        openModal();
    });

    closeButtons.forEach(function (button) {
        button.addEventListener('click', function (event) {
            event.preventDefault();
            closeModal();
        });
    });

    modal.addEventListener('click', function (event) {
        if (event.target === modal) {
            closeModal();
        }
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && modal.classList.contains('show')) {
            closeModal();
        }
    });
});
</script>


<script>
document.addEventListener('DOMContentLoaded', function () {
    const centerNotice = document.getElementById('claimCenterNotice');
    const centerNoticePanel = document.getElementById('claimCenterNoticePanel');
    const centerNoticeTitle = document.getElementById('claimCenterNoticeTitle');
    const centerNoticeBody = document.getElementById('claimCenterNoticeBody');

    function closeClaimCenterNotice() {
        if (!centerNotice) {
            return;
        }

        centerNotice.classList.add('is-hidden');
        document.body.classList.remove('overflow-hidden');
    }

    function showClaimCenterNotice(message, options) {
        if (!centerNotice || !centerNoticePanel || !centerNoticeTitle || !centerNoticeBody) {
            return;
        }

        const settings = Object.assign({
            title: 'Hinweis',
            type: 'warning'
        }, options || {});

        centerNoticeTitle.textContent = String(settings.title || 'Hinweis');
        centerNoticeBody.textContent = String(message || '');

        centerNoticePanel.classList.remove('is-warning', 'is-danger', 'is-info');
        centerNoticePanel.classList.add('is-' + String(settings.type || 'warning'));

        centerNotice.classList.remove('is-hidden');
        document.body.classList.add('overflow-hidden');

        const closeButton = centerNotice.querySelector('[data-claim-notice-close]');

        if (closeButton) {
            window.setTimeout(function () {
                closeButton.focus();
            }, 50);
        }
    }

    document.querySelectorAll('[data-claim-notice-close]').forEach(function (button) {
        button.addEventListener('click', closeClaimCenterNotice);
    });

    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && centerNotice && !centerNotice.classList.contains('is-hidden')) {
            closeClaimCenterNotice();
        }
    });

    const d8CloseDialog = document.getElementById('d8CloseDialog');
    const d8SaveOnlyButton = document.getElementById('d8SaveOnlyButton');
    const d8CloseClaimButton = document.getElementById('d8CloseClaimButton');

    function requestD8CloseDecision() {
        return new Promise(function (resolve) {
            if (!d8CloseDialog || !d8SaveOnlyButton || !d8CloseClaimButton) {
                resolve('save');
                return;
            }

            let finished = false;

            const finish = function (decision) {
                if (finished) {
                    return;
                }

                finished = true;
                d8CloseDialog.classList.add('is-hidden');
                document.body.classList.remove('overflow-hidden');

                d8SaveOnlyButton.removeEventListener('click', onSaveOnly);
                d8CloseClaimButton.removeEventListener('click', onCloseClaim);
                document.querySelectorAll('[data-d8-close-cancel]').forEach(function (button) {
                    button.removeEventListener('click', onCancel);
                });
                document.removeEventListener('keydown', onKeyDown);

                resolve(decision);
            };

            const onSaveOnly = function () {
                finish('save');
            };

            const onCloseClaim = function () {
                if (d8CloseClaimButton.disabled) {
                    return;
                }

                finish('close');
            };

            const onCancel = function () {
                finish(null);
            };

            const onKeyDown = function (event) {
                if (event.key === 'Escape') {
                    finish(null);
                }
            };

            d8SaveOnlyButton.addEventListener('click', onSaveOnly);
            d8CloseClaimButton.addEventListener('click', onCloseClaim);
            document.querySelectorAll('[data-d8-close-cancel]').forEach(function (button) {
                button.addEventListener('click', onCancel);
            });
            document.addEventListener('keydown', onKeyDown);

            d8CloseDialog.classList.remove('is-hidden');
            document.body.classList.add('overflow-hidden');

            window.setTimeout(function () {
                if (!d8CloseClaimButton.disabled) {
                    d8CloseClaimButton.focus();
                } else {
                    d8SaveOnlyButton.focus();
                }
            }, 60);
        });
    }

    const draftTextareas = document.querySelectorAll('.js-step-content-draft');

    draftTextareas.forEach(function (textarea) {
        const draftKey = textarea.dataset.draftKey || '';

        if (!draftKey) {
            return;
        }

        const storedDraft = window.sessionStorage.getItem(draftKey);

        if (storedDraft !== null && storedDraft !== textarea.value) {
            textarea.value = storedDraft;
        }

        textarea.addEventListener('input', function () {
            window.sessionStorage.setItem(draftKey, textarea.value);
        });
    });

    const pendingStoragePrefix = 'claim-step-pending-next-';

    function openStepAccordion(stepKey) {
        if (!stepKey) {
            return;
        }

        const target = document.getElementById('step' + stepKey);

        if (!target) {
            return;
        }

        document.querySelectorAll('#stepsAccordion .accordion-collapse.show').forEach(function (collapseEl) {
            if (collapseEl === target) {
                return;
            }

            if (window.bootstrap && window.bootstrap.Collapse) {
                window.bootstrap.Collapse.getOrCreateInstance(collapseEl, {
                    toggle: false
                }).hide();
            } else {
                collapseEl.classList.remove('show');
            }
        });

        if (window.bootstrap && window.bootstrap.Collapse) {
            window.bootstrap.Collapse.getOrCreateInstance(target, {
                toggle: false
            }).show();
        } else {
            target.classList.add('show');

            const button = document.querySelector(
                '[data-bs-target="#' + CSS.escape(target.id) + '"]'
            );

            if (button) {
                button.classList.remove('collapsed');
                button.setAttribute('aria-expanded', 'true');
            }
        }

        const targetHash = '#step' + stepKey;

        if (window.location.hash !== targetHash) {
            window.history.replaceState(
                null,
                '',
                window.location.pathname + window.location.search + targetHash
            );
        }

        window.setTimeout(function () {
            const item = target.closest('.accordion-item');

            if (item) {
                item.scrollIntoView({
                    behavior: 'smooth',
                    block: 'start'
                });
            }
        }, 180);
    }

    /*
     * Nach der normalen Weiterleitung von claim_step_save.php prüfen wir,
     * ob der Inhalt tatsächlich auf der Seite angekommen ist. Erst dann
     * wird der nächste Schritt geöffnet und der lokale Entwurf gelöscht.
     */
    const currentClaimId = String(<?= (int)$claim['id'] ?>);
    const pendingStorageKey = pendingStoragePrefix + currentClaimId;
    const pendingRaw = window.sessionStorage.getItem(pendingStorageKey);

    if (pendingRaw) {
        try {
            const pending = JSON.parse(pendingRaw);
            const pendingAge = Date.now() - Number(pending.createdAt || 0);
            const currentTextarea = pending.draftKey
                ? document.querySelector(
                    '.js-step-content-draft[data-draft-key="' + CSS.escape(pending.draftKey) + '"]'
                )
                : null;

            const savedContentMatches = !currentTextarea
                || String(currentTextarea.value || '') === String(pending.content || '');

            if (
                String(pending.claimId || '') === currentClaimId
                && pendingAge >= 0
                && pendingAge < 5 * 60 * 1000
                && savedContentMatches
            ) {
                window.sessionStorage.removeItem(pendingStorageKey);

                if (pending.draftKey) {
                    window.sessionStorage.removeItem(String(pending.draftKey));
                }

                const closeAfterD8 = pending.closeAfterD8 === true
                    && String(pending.currentStepKey || '') === 'D8';

                if (closeAfterD8) {
                    const closeForm = document.getElementById('claimAutoCloseForm');

                    if (closeForm) {
                        window.setTimeout(function () {
                            HTMLFormElement.prototype.submit.call(closeForm);
                        }, 80);
                    } else {
                        openStepAccordion('D8');
                    }
                } else {
                    openStepAccordion(
                        String(pending.nextStepKey || pending.currentStepKey || '')
                    );
                }
            } else if (pendingAge >= 5 * 60 * 1000) {
                window.sessionStorage.removeItem(pendingStorageKey);
            }
        } catch (error) {
            console.error('D-Schritt-Weiterleitung konnte nicht ausgewertet werden:', error);
            window.sessionStorage.removeItem(pendingStorageKey);
        }
    }

    /*
     * Dateien werden bis zum Abschluss des D-Schritts nur im Browser gehalten.
     * Pro D-Schritt kann der Mitarbeiter mehrere Dateien vormerken.
     */
    const stagedFilesByStep = new Map();
    let stepSaveNavigationInProgress = false;

    function stagedEntryId() {
        if (window.crypto && typeof window.crypto.randomUUID === 'function') {
            return window.crypto.randomUUID();
        }

        return 'staged-' + Date.now() + '-' + Math.random().toString(16).slice(2);
    }

    function formatStagedFileSize(bytes) {
        const size = Number(bytes || 0);

        if (size < 1024) {
            return size + ' B';
        }

        if (size < 1024 * 1024) {
            return (size / 1024).toLocaleString('de-DE', {
                maximumFractionDigits: 1
            }) + ' KB';
        }

        return (size / 1024 / 1024).toLocaleString('de-DE', {
            maximumFractionDigits: 1
        }) + ' MB';
    }

    function stagedFileExtension(filename) {
        const cleanName = String(filename || '').trim();
        const dotPosition = cleanName.lastIndexOf('.');

        if (dotPosition < 0 || dotPosition === cleanName.length - 1) {
            return 'DATEI';
        }

        return cleanName.slice(dotPosition + 1).toUpperCase().slice(0, 8);
    }

    function createStagedPreviewUrl(file) {
        if (!file || !window.URL || typeof window.URL.createObjectURL !== 'function') {
            return '';
        }

        return window.URL.createObjectURL(file);
    }

    function revokeStagedPreviewUrl(entry) {
        if (
            entry
            && entry.previewUrl
            && window.URL
            && typeof window.URL.revokeObjectURL === 'function'
        ) {
            window.URL.revokeObjectURL(entry.previewUrl);
            entry.previewUrl = '';
        }
    }

    function stagedFilesForStep(stepKey) {
        if (!stagedFilesByStep.has(stepKey)) {
            stagedFilesByStep.set(stepKey, []);
        }

        return stagedFilesByStep.get(stepKey);
    }

    function renderStagedFiles(stepKey) {
        const container = document.querySelector(
            '.js-step-staged-files[data-step-key="' + CSS.escape(stepKey) + '"]'
        );

        if (!container) {
            return;
        }

        const list = container.querySelector('.js-step-staged-list');
        const count = container.querySelector('.js-step-staged-count');
        const entries = stagedFilesForStep(stepKey);

        if (!list || !count) {
            return;
        }

        list.replaceChildren();
        count.textContent = String(entries.length);
        container.classList.toggle('d-none', entries.length === 0);

        entries.forEach(function (entry) {
            const item = document.createElement('div');
            item.className = 'step-staged-file-item';

            const previewLink = document.createElement('a');
            previewLink.className = 'step-staged-file-preview-link';
            previewLink.href = entry.previewUrl || '#';
            previewLink.target = '_blank';
            previewLink.rel = 'noopener';
            previewLink.title = 'Vorschau groß öffnen';

            if (!entry.previewUrl) {
                previewLink.removeAttribute('target');
                previewLink.addEventListener('click', function (event) {
                    event.preventDefault();
                });
            }

            if (entry.isImage && entry.previewUrl) {
                const previewImage = document.createElement('img');
                previewImage.className = 'step-staged-file-preview-image';
                previewImage.src = entry.previewUrl;
                previewImage.alt = 'Vorschau von ' + entry.file.name;
                previewLink.append(previewImage);
            } else {
                const previewType = document.createElement('span');
                previewType.className = 'step-staged-file-preview-type';
                previewType.textContent = entry.extension || 'DATEI';
                previewLink.append(previewType);
            }

            if (entry.previewUrl) {
                const previewOpen = document.createElement('span');
                previewOpen.className = 'step-staged-file-preview-open';
                previewOpen.textContent = 'Öffnen';
                previewLink.append(previewOpen);
            }

            const main = document.createElement('div');
            main.className = 'step-staged-file-main';

            const name = document.createElement('div');
            name.className = 'step-staged-file-name';
            name.textContent = entry.file.name;

            const meta = document.createElement('div');
            meta.className = 'step-staged-file-meta';

            const metaParts = [
                formatStagedFileSize(entry.file.size),
                entry.categoryLabel || entry.category
            ];

            if (entry.caption) {
                metaParts.push(entry.caption);
            }

            meta.textContent = metaParts.filter(Boolean).join(' · ');

            const removeButton = document.createElement('button');
            removeButton.type = 'button';
            removeButton.className =
                'btn btn-sm btn-outline-danger step-staged-file-remove';
            removeButton.textContent = 'Entfernen';
            removeButton.dataset.stagedFileId = entry.id;
            removeButton.dataset.stepKey = stepKey;

            main.append(name, meta);
            item.append(previewLink, main, removeButton);
            list.append(item);
        });
    }

    document.querySelectorAll('.js-step-file-stage-form').forEach(function (stageForm) {
        stageForm.addEventListener('submit', function (event) {
            event.preventDefault();

            const stepKey = String(stageForm.dataset.stepKey || '');
            const fileInput = stageForm.querySelector('.js-step-file-input');
            const categorySelect = stageForm.querySelector('[name="category"]');
            const captionInput = stageForm.querySelector('[name="caption"]');
            const selectedFiles = fileInput
                ? Array.from(fileInput.files || [])
                : [];

            if (!stepKey || selectedFiles.length === 0) {
                showClaimCenterNotice(
                    'Bitte zuerst mindestens eine Datei auswählen.',
                    {
                        title: 'Keine Datei ausgewählt',
                        type: 'warning'
                    }
                );
                return;
            }

            const entries = stagedFilesForStep(stepKey);
            const category = categorySelect
                ? String(categorySelect.value || 'other')
                : 'other';
            const categoryLabel = categorySelect
                && categorySelect.selectedOptions.length > 0
                ? String(categorySelect.selectedOptions[0].textContent || category)
                : category;
            const caption = captionInput
                ? String(captionInput.value || '').trim()
                : '';

            selectedFiles.forEach(function (file) {
                entries.push({
                    id: stagedEntryId(),
                    file: file,
                    category: category,
                    categoryLabel: categoryLabel,
                    caption: caption,
                    isImage: String(file.type || '').toLowerCase().startsWith('image/'),
                    extension: stagedFileExtension(file.name),
                    previewUrl: createStagedPreviewUrl(file)
                });
            });

            if (fileInput) {
                fileInput.value = '';
            }

            if (captionInput) {
                captionInput.value = '';
            }

            renderStagedFiles(stepKey);
        });
    });

    document.addEventListener('click', function (event) {
        const removeButton = event.target.closest('.step-staged-file-remove');

        if (!removeButton) {
            return;
        }

        const stepKey = String(removeButton.dataset.stepKey || '');
        const entryId = String(removeButton.dataset.stagedFileId || '');
        const entries = stagedFilesForStep(stepKey);
        const removedEntry = entries.find(function (entry) {
            return entry.id === entryId;
        });
        const nextEntries = entries.filter(function (entry) {
            return entry.id !== entryId;
        });

        revokeStagedPreviewUrl(removedEntry);
        stagedFilesByStep.set(stepKey, nextEntries);
        renderStagedFiles(stepKey);
    });

    function rememberPendingStepNavigation(form, closeAfterD8) {
        const draftKey = form.dataset.draftKey || '';
        const claimId = String(form.dataset.claimId || '');
        const currentStepKey = String(form.dataset.currentStepKey || '');
        const nextStepKey = String(form.dataset.nextStepKey || '');
        const contentTextarea = draftKey
            ? document.querySelector(
                '.js-step-content-draft[data-draft-key="' + CSS.escape(draftKey) + '"]'
            )
            : null;

        window.sessionStorage.setItem(
            pendingStoragePrefix + claimId,
            JSON.stringify({
                claimId: claimId,
                currentStepKey: currentStepKey,
                nextStepKey: nextStepKey,
                draftKey: draftKey,
                content: contentTextarea
                    ? String(contentTextarea.value || '')
                    : '',
                closeAfterD8: closeAfterD8 === true,
                createdAt: Date.now()
            })
        );
    }

    async function uploadStagedFiles(stepKey, submitButton) {
        const entries = stagedFilesForStep(stepKey);
        const stageForm = document.querySelector(
            '.js-step-file-stage-form[data-step-key="' + CSS.escape(stepKey) + '"]'
        );

        if (!stageForm || entries.length === 0) {
            return;
        }

        const total = entries.length;
        let completed = 0;

        while (entries.length > 0) {
            const entry = entries[0];
            completed++;

            if (submitButton) {
                submitButton.innerHTML =
                    '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>'
                    + 'Datei ' + completed + ' von ' + total + ' hochladen...';
            }

            const uploadData = new FormData(stageForm);
            uploadData.set('file', entry.file, entry.file.name);
            uploadData.set('category', entry.category);
            uploadData.set('caption', entry.caption);

            const response = await fetch(stageForm.action, {
                method: 'POST',
                body: uploadData,
                credentials: 'same-origin',
                redirect: 'follow',
                cache: 'no-store',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            });

            const responseText = await response.text();

            if (!response.ok) {
                throw new Error(
                    'Datei „' + entry.file.name
                    + '“ konnte nicht hochgeladen werden. HTTP '
                    + response.status
                );
            }

            if (
                response.url
                && (
                    response.url.includes('login.php')
                    || response.url.includes('logout.php')
                )
            ) {
                throw new Error(
                    'Die Sitzung ist abgelaufen. Bitte erneut anmelden.'
                );
            }

            /*
             * Der bestehende upload_file.php-Endpunkt antwortet nach dem
             * Speichern mit einer Weiterleitung zur Reklamation. fetch folgt
             * dieser Weiterleitung vollständig; die Hauptseite bleibt stehen.
             */
            void responseText;

            const uploadedEntry = entries.shift();
            revokeStagedPreviewUrl(uploadedEntry);
            renderStagedFiles(stepKey);
        }
    }

    document.querySelectorAll('.js-step-final-save-form').forEach(function (form) {
        form.addEventListener('submit', async function (event) {
            if (form.dataset.saving === '1') {
                event.preventDefault();
                return;
            }

            const stepKey = String(form.dataset.currentStepKey || '');
            const isFinalStep = form.dataset.isFinalStep === '1';
            const claimAlreadyClosed = form.dataset.claimClosed === '1';
            let closeAfterD8 = false;
            let submitMustBeStartedManually = false;

            if (isFinalStep && !claimAlreadyClosed) {
                event.preventDefault();

                const decision = await requestD8CloseDecision();

                if (decision === null) {
                    return;
                }

                closeAfterD8 = decision === 'close';
                submitMustBeStartedManually = true;
            }

            const stagedEntries = stagedFilesForStep(stepKey);
            const submitButton = form.querySelector(
                'button[type="submit"], button:not([type])'
            );
            const originalButtonHtml = submitButton
                ? submitButton.innerHTML
                : '';

            if (stagedEntries.length === 0) {
                rememberPendingStepNavigation(form, closeAfterD8);
                form.dataset.saving = '1';
                stepSaveNavigationInProgress = true;

                if (submitButton) {
                    submitButton.disabled = true;
                    submitButton.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>'
                        + (closeAfterD8
                            ? 'D8 speichern und abschließen...'
                            : 'Speichern...');
                }

                if (submitMustBeStartedManually) {
                    HTMLFormElement.prototype.submit.call(form);
                }

                return;
            }

            event.preventDefault();
            form.dataset.saving = '1';

            if (submitButton) {
                submitButton.disabled = true;
            }

            try {
                await uploadStagedFiles(stepKey, submitButton);

                if (submitButton) {
                    submitButton.innerHTML =
                        '<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>'
                        + (closeAfterD8
                            ? 'D8 speichern und abschließen...'
                            : 'D-Schritt speichern...');
                }

                rememberPendingStepNavigation(form, closeAfterD8);
                stepSaveNavigationInProgress = true;

                HTMLFormElement.prototype.submit.call(form);
            } catch (error) {
                console.error(error);
                form.dataset.saving = '0';
                stepSaveNavigationInProgress = false;

                if (submitButton) {
                    submitButton.disabled = false;
                    submitButton.innerHTML = originalButtonHtml;
                }

                showClaimCenterNotice(
                    error instanceof Error
                        ? error.message
                        : 'Die vorgemerkte Datei konnte nicht hochgeladen werden.',
                    {
                        title: 'Upload nicht möglich',
                        type: 'danger'
                    }
                );
            }
        });
    });

    window.addEventListener('beforeunload', function (event) {
        if (stepSaveNavigationInProgress) {
            return;
        }

        const hasStagedFiles = Array.from(stagedFilesByStep.values()).some(
            function (entries) {
                return entries.length > 0;
            }
        );

        if (!hasStagedFiles) {
            return;
        }

        event.preventDefault();
        event.returnValue = '';
    });
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
