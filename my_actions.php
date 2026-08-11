<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_login();

$user = current_user();
$db = pdo();
[$locationSql, $locationParams] = location_scope_condition('c');

$status = trim((string)($_GET['status'] ?? 'open'));
$q = trim((string)($_GET['q'] ?? ''));
$allowedFilters = ['open', 'overdue', 'today', 'in_progress', 'done', 'all'];
if (!in_array($status, $allowedFilters, true)) {
    $status = 'open';
}

$countStmt = $db->prepare("SELECT
    SUM(CASE WHEN a.status IN ('open','in_progress') THEN 1 ELSE 0 END) AS open_count,
    SUM(CASE WHEN a.status IN ('open','in_progress') AND a.due_date IS NOT NULL AND a.due_date < CURDATE() THEN 1 ELSE 0 END) AS overdue_count,
    SUM(CASE WHEN a.status IN ('open','in_progress') AND a.due_date = CURDATE() THEN 1 ELSE 0 END) AS today_count,
    SUM(CASE WHEN a.status = 'done' THEN 1 ELSE 0 END) AS done_count
    FROM claim_actions a
    JOIN claims c ON c.id = a.claim_id
    WHERE a.responsible_user_id = ?" . $locationSql);
$countStmt->execute(array_merge([(int)$user['id']], $locationParams));
$counts = $countStmt->fetch() ?: [];
$openCount = (int)($counts['open_count'] ?? 0);
$overdueCount = (int)($counts['overdue_count'] ?? 0);
$todayCount = (int)($counts['today_count'] ?? 0);
$doneCount = (int)($counts['done_count'] ?? 0);

$signalStmt = $db->prepare("SELECT a.status, a.due_date, a.created_at
    FROM claim_actions a
    JOIN claims c ON c.id = a.claim_id
    WHERE a.responsible_user_id = ? AND a.status IN ('open','in_progress')" . $locationSql);
$signalStmt->execute(array_merge([(int)$user['id']], $locationParams));
$signalCounts = ['green' => 0, 'yellow' => 0, 'red' => 0];
foreach ($signalStmt->fetchAll() as $signalAction) {
    $level = action_traffic_level($signalAction);
    if (isset($signalCounts[$level])) {
        $signalCounts[$level]++;
    }
}

$sql = "SELECT
        a.*,
        r.read_at AS action_read_at,
        c.claim_number,
        c.short_description,
        c.partner_name,
        c.priority,
        c.status AS claim_status,
        c.standort_id,
        cs.title AS step_title,
        creator.name AS created_by_name
    FROM claim_actions a
    JOIN claims c ON c.id = a.claim_id
    LEFT JOIN claim_steps cs ON cs.claim_id = a.claim_id AND cs.step_key = a.step_key
    LEFT JOIN users creator ON creator.id = a.created_by
    LEFT JOIN claim_action_reads r ON r.action_id = a.id AND r.user_id = ?
    WHERE a.responsible_user_id = ?";
$params = array_merge([(int)$user['id'], (int)$user['id']], $locationParams);
$sql .= $locationSql;

if ($status === 'open') {
    $sql .= " AND a.status IN ('open','in_progress')";
} elseif ($status === 'overdue') {
    $sql .= " AND a.status IN ('open','in_progress') AND a.due_date IS NOT NULL AND a.due_date < CURDATE()";
} elseif ($status === 'today') {
    $sql .= " AND a.status IN ('open','in_progress') AND a.due_date = CURDATE()";
} elseif ($status === 'in_progress') {
    $sql .= " AND a.status = 'in_progress'";
} elseif ($status === 'done') {
    $sql .= " AND a.status = 'done'";
}

if ($q !== '') {
    $sql .= " AND (a.title LIKE ? OR a.description LIKE ? OR c.claim_number LIKE ? OR c.partner_name LIKE ? OR c.short_description LIKE ?)";
    $like = '%' . $q . '%';
    array_push($params, $like, $like, $like, $like, $like);
}

$sql .= " ORDER BY
    CASE WHEN a.status = 'done' THEN 2 ELSE 0 END,
    CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END,
    a.due_date ASC,
    a.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$actions = $stmt->fetchAll();

$returnTo = 'my_actions.php?status=' . rawurlencode($status) . ($q !== '' ? '&q=' . rawurlencode($q) : '');

require __DIR__ . '/header.php';
?>
<style>
    .my-actions-page-head {
        gap: 1rem;
    }

    .action-list-card {
        overflow: hidden;
        border: 1px solid rgba(148, 163, 184, .2);
        border-radius: 20px;
        box-shadow: 0 10px 28px rgba(15, 23, 42, .045);
    }

    .action-list-card .card-header {
        min-height: 54px;
        padding: .85rem 1rem;
        border-bottom: 1px solid rgba(148, 163, 184, .16);
    }

    .action-result-count {
        padding: .42rem .68rem;
        border: 1px solid rgba(148, 163, 184, .2);
        background: #f8fafc;
        color: #475569;
        font-size: .76rem;
        font-weight: 800;
    }

    .action-modern-table {
        min-width: 960px;
        font-size: .84rem;
    }

    .action-modern-table thead th {
        padding: .72rem .68rem;
        color: #64748b;
        font-size: .72rem;
        font-weight: 850;
        letter-spacing: .045em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .action-modern-table tbody td {
        padding: .82rem .68rem;
        border-color: rgba(148, 163, 184, .14);
        vertical-align: middle;
    }

    .action-click-row {
        cursor: pointer;
    }

    .action-click-row > td {
        background: #fff !important;
        transition: background .15s ease;
    }

    .action-click-row:hover > td {
        background: #f8fbff !important;
    }

    .action-click-row > td:first-child {
        border-left: 4px solid transparent;
    }

    .action-click-row.is-green > td:first-child {
        border-left-color: #198754;
    }

    .action-click-row.is-yellow > td:first-child {
        border-left-color: #d59a00;
    }

    .action-click-row.is-red > td:first-child {
        border-left-color: #dc3545;
    }

    .action-click-row.is-done > td:first-child {
        border-left-color: #94a3b8;
    }

    .action-click-row.is-unread > td {
        background: #fbfdff !important;
    }

    .action-due-cell {
        width: 14%;
        min-width: 120px;
        white-space: nowrap;
    }

    .action-main-cell {
        width: 38%;
        min-width: 330px;
    }

    .action-location-cell {
        width: 12%;
        min-width: 112px;
        white-space: nowrap;
    }

    .action-step-cell {
        width: 14%;
        min-width: 130px;
    }

    .action-status-cell {
        width: 10%;
        min-width: 112px;
        white-space: nowrap;
    }

    .action-controls-cell {
        width: 12%;
        min-width: 168px;
        white-space: nowrap;
    }

    .action-due-date {
        color: #0f172a;
        font-size: .91rem;
        font-weight: 850;
    }

    .action-due-state {
        margin-top: .18rem;
        font-size: .74rem;
        font-weight: 800;
    }

    .action-new-indicator {
        display: inline-flex;
        align-items: center;
        gap: .32rem;
        margin-top: .45rem;
        color: #0d6efd;
        font-size: .72rem;
        font-weight: 850;
    }

    .action-new-indicator::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: #0d6efd;
        box-shadow: 0 0 0 3px rgba(13, 110, 253, .1);
    }

    .action-title {
        color: #0f172a;
        font-size: .91rem;
        font-weight: 850;
        line-height: 1.35;
    }

    .action-description {
        display: -webkit-box;
        overflow: hidden;
        margin-top: .3rem;
        color: #64748b;
        font-size: .78rem;
        line-height: 1.42;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .action-claim-line {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .38rem;
        margin-top: .52rem;
    }

    .action-claim-number {
        color: #0d6efd;
        font-size: .79rem;
        font-weight: 850;
        text-decoration: none;
    }

    .action-claim-number:hover {
        text-decoration: underline;
    }

    .action-claim-context {
        overflow: hidden;
        max-width: 100%;
        color: #64748b;
        font-size: .76rem;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .action-meta-line {
        display: flex;
        flex-wrap: wrap;
        gap: .3rem .7rem;
        margin-top: .42rem;
        color: #64748b;
        font-size: .72rem;
    }

    .action-step-title {
        display: -webkit-box;
        overflow: hidden;
        margin-top: .32rem;
        color: #64748b;
        font-size: .74rem;
        line-height: 1.35;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .action-btn-group {
        display: inline-flex;
        flex-wrap: wrap;
        justify-content: flex-end;
        gap: .35rem;
    }

    .action-btn-group .btn {
        border-radius: 9px;
        font-weight: 750;
    }

    .action-mobile-list {
        display: grid;
        gap: .7rem;
        padding: .75rem;
        background: #f8fafc;
    }

    .action-mobile-card {
        position: relative;
        overflow: hidden;
        padding: .95rem;
        border: 1px solid rgba(148, 163, 184, .2);
        border-left: 4px solid #94a3b8;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 6px 18px rgba(15, 23, 42, .04);
        cursor: pointer;
    }

    .action-mobile-card.is-green {
        border-left-color: #198754;
    }

    .action-mobile-card.is-yellow {
        border-left-color: #d59a00;
    }

    .action-mobile-card.is-red {
        border-left-color: #dc3545;
    }

    .action-mobile-card.is-unread {
        background: #fbfdff;
    }

    .action-mobile-top,
    .action-mobile-meta,
    .action-mobile-footer {
        display: flex;
        flex-wrap: wrap;
        align-items: center;
        gap: .45rem;
    }

    .action-mobile-top {
        justify-content: space-between;
    }

    .action-mobile-title {
        margin-top: .65rem;
        color: #0f172a;
        font-size: .94rem;
        font-weight: 850;
        line-height: 1.35;
    }

    .action-mobile-description {
        display: -webkit-box;
        overflow: hidden;
        margin-top: .3rem;
        color: #64748b;
        font-size: .78rem;
        line-height: 1.4;
        -webkit-box-orient: vertical;
        -webkit-line-clamp: 2;
    }

    .action-mobile-meta {
        margin-top: .7rem;
        color: #64748b;
        font-size: .75rem;
    }

    .action-mobile-footer {
        justify-content: space-between;
        margin-top: .8rem;
        padding-top: .75rem;
        border-top: 1px solid rgba(148, 163, 184, .16);
    }

    .action-mobile-buttons {
        display: flex;
        flex-wrap: wrap;
        gap: .4rem;
    }

    .action-mobile-buttons .btn {
        border-radius: 9px;
        font-weight: 750;
    }

    .action-empty {
        padding: 2rem 1rem;
        color: #64748b;
        text-align: center;
    }

    @media (max-width: 767.98px) {
        .my-actions-page-head {
            align-items: flex-start !important;
        }

        .my-actions-page-head .btn {
            width: 100%;
        }
    }
</style>

<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4 my-actions-page-head">
    <div>
        <h1 class="h3 fw-bold mb-1">Meine Maßnahmen</h1>
        <div class="text-muted">Alle Aufgaben, für die du als Verantwortlicher eingetragen bist<?= locations_enabled() ? ' · Standort: ' . e(selected_location()['name'] ?? 'Alle Standorte') : '' ?>.</div>
    </div>
    <a href="claims.php" class="btn btn-outline-primary">Zu den Reklamationen</a>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <a class="text-decoration-none" href="my_actions.php?status=open">
            <div class="card stat-card p-3 h-100 <?= $status === 'open' ? 'border-primary' : '' ?>">
                <div class="text-muted">Offen</div>
                <div class="value"><?= $openCount ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a class="text-decoration-none" href="my_actions.php?status=overdue">
            <div class="card stat-card p-3 h-100 <?= $status === 'overdue' ? 'border-danger' : '' ?>">
                <div class="text-muted">Überfällig</div>
                <div class="value text-danger"><?= $overdueCount ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a class="text-decoration-none" href="my_actions.php?status=today">
            <div class="card stat-card p-3 h-100 <?= $status === 'today' ? 'border-warning' : '' ?>">
                <div class="text-muted">Heute fällig</div>
                <div class="value text-warning"><?= $todayCount ?></div>
            </div>
        </a>
    </div>
    <div class="col-md-3">
        <a class="text-decoration-none" href="my_actions.php?status=done">
            <div class="card stat-card p-3 h-100 <?= $status === 'done' ? 'border-success' : '' ?>">
                <div class="text-muted">Erledigt</div>
                <div class="value text-success"><?= $doneCount ?></div>
            </div>
        </a>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body d-flex flex-column flex-lg-row justify-content-between gap-3 align-items-lg-center">
        <div>
            <div class="fw-bold">Ampel für offene Maßnahmen</div>
            <div class="small text-muted">Grün = innerhalb 5 Tage, Gelb = Tag 6–10, Rot = ab Tag 11 oder Frist überschritten.</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <span class="badge bg-success">Grün: <?= (int)$signalCounts['green'] ?></span>
            <span class="badge bg-warning text-dark">Gelb: <?= (int)$signalCounts['yellow'] ?></span>
            <span class="badge bg-danger">Rot: <?= (int)$signalCounts['red'] ?></span>
        </div>
    </div>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form class="row g-2">
            <div class="col-md-7">
                <input class="form-control" name="q" value="<?= e($q) ?>" placeholder="Suche nach Maßnahme, Reklamation, Partner oder Problem">
            </div>
            <div class="col-md-3">
                <select class="form-select" name="status">
                    <option value="open" <?= $status === 'open' ? 'selected' : '' ?>>Offene Maßnahmen</option>
                    <option value="overdue" <?= $status === 'overdue' ? 'selected' : '' ?>>Überfällig</option>
                    <option value="today" <?= $status === 'today' ? 'selected' : '' ?>>Heute fällig</option>
                    <option value="in_progress" <?= $status === 'in_progress' ? 'selected' : '' ?>>In Bearbeitung</option>
                    <option value="done" <?= $status === 'done' ? 'selected' : '' ?>>Erledigt</option>
                    <option value="all" <?= $status === 'all' ? 'selected' : '' ?>>Alle</option>
                </select>
            </div>
            <div class="col-md-2 d-grid">
                <button class="btn btn-outline-primary">Filtern</button>
            </div>
        </form>
    </div>
</div>

<div class="card action-list-card">
    <div class="card-header bg-white d-flex justify-content-between align-items-center gap-3">
        <div>
            <div class="fw-bold">Maßnahmenliste</div>
            <div class="small text-muted">Klicke auf eine Zeile, um die Reklamation zu öffnen.</div>
        </div>
        <span class="badge rounded-pill action-result-count"><?= count($actions) ?> Treffer</span>
    </div>

    <!-- Desktop / großes Tablet -->
    <div class="table-responsive d-none d-lg-block">
        <table class="table table-hover align-middle mb-0 action-modern-table">
            <thead class="table-light">
            <tr>
                <th>Frist</th>
                <th>Maßnahme / Reklamation</th>
                <th>Standort</th>
                <th>D-Schritt</th>
                <th>Status</th>
                <th class="text-end">Bearbeitung</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($actions as $action): ?>
                <?php
                    $claimUrl = 'action_read_open.php?action_id=' . (int)$action['id'] . '&claim_id=' . (int)$action['claim_id'];
                    $isRead = !empty($action['action_read_at']);
                    $trafficLevel = $action['status'] === 'done'
                        ? 'done'
                        : action_traffic_level($action);
                    $rowVisualClass = $trafficLevel === 'done'
                        ? 'is-done'
                        : 'is-' . $trafficLevel;

                    $dueDateValue = trim((string)($action['due_date'] ?? ''));
                    $dueDateLabel = '-';
                    $dueStateLabel = 'Keine Frist';
                    $dueStateClass = 'text-muted';

                    if ($dueDateValue !== '') {
                        $dueTimestamp = strtotime($dueDateValue);
                        $dueDateLabel = $dueTimestamp
                            ? date('d.m.Y', $dueTimestamp)
                            : $dueDateValue;

                        if ($action['status'] === 'done') {
                            $dueStateLabel = 'Abgeschlossen';
                            $dueStateClass = 'text-success';
                        } elseif ($dueDateValue < date('Y-m-d')) {
                            $dueStateLabel = 'Überfällig';
                            $dueStateClass = 'text-danger';
                        } elseif ($dueDateValue === date('Y-m-d')) {
                            $dueStateLabel = 'Heute fällig';
                            $dueStateClass = 'text-warning';
                        } elseif ($trafficLevel === 'yellow') {
                            $dueStateLabel = 'Achtung';
                            $dueStateClass = 'text-warning';
                        } elseif ($trafficLevel === 'red') {
                            $dueStateLabel = 'Kritisch';
                            $dueStateClass = 'text-danger';
                        } else {
                            $dueStateLabel = 'Im Zeitplan';
                            $dueStateClass = 'text-success';
                        }
                    }
                ?>
                <tr
                    class="action-click-row <?= e($rowVisualClass) ?> <?= $isRead ? '' : 'is-unread' ?>"
                    data-claim-url="<?= e($claimUrl) ?>"
                >
                    <td class="action-due-cell">
                        <div class="action-due-date"><?= e($dueDateLabel) ?></div>
                        <div class="action-due-state <?= e($dueStateClass) ?>">
                            <?= e($dueStateLabel) ?>
                        </div>

                        <?php if (!$isRead): ?>
                            <div class="action-new-indicator">Neu</div>
                        <?php endif; ?>
                    </td>

                    <td class="action-main-cell">
                        <div class="action-title"><?= e($action['title']) ?></div>

                        <?php if ($action['description']): ?>
                            <div class="action-description"><?= e($action['description']) ?></div>
                        <?php endif; ?>

                        <div class="action-claim-line">
                            <a href="<?= e($claimUrl) ?>" class="action-claim-number">
                                <?= e($action['claim_number']) ?>
                            </a>
                            <span class="action-claim-context">
                                <?= e($action['partner_name']) ?> · <?= e($action['short_description']) ?>
                            </span>
                        </div>

                        <div class="action-meta-line">
                            <span>Priorität: <?= e(priority_label($action['priority'])) ?></span>
                            <span>Angelegt von: <?= e($action['created_by_name'] ?? '-') ?></span>
                            <span>Alter: <?= action_age_days($action) ?> Tage</span>
                        </div>
                    </td>

                    <td class="action-location-cell">
                        <?= location_badge(isset($action['standort_id']) ? (int)$action['standort_id'] : null) ?>
                    </td>

                    <td class="action-step-cell">
                        <span class="badge bg-secondary"><?= e($action['step_key']) ?></span>
                        <?php if (!empty($action['step_title'])): ?>
                            <div class="action-step-title"><?= e($action['step_title']) ?></div>
                        <?php endif; ?>
                    </td>

                    <td class="action-status-cell">
                        <?= status_badge($action['status']) ?>
                    </td>

                    <td class="action-controls-cell text-end">
                        <div class="action-btn-group">
                            <?php if (can_edit()): ?>
                                <?php if ($action['status'] === 'open'): ?>
                                    <form method="post" action="action_update.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="claim_id" value="<?= (int)$action['claim_id'] ?>">
                                        <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                        <input type="hidden" name="status" value="in_progress">
                                        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                        <button class="btn btn-sm btn-outline-primary">In Arbeit</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($action['status'] !== 'done'): ?>
                                    <form method="post" action="action_update.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="claim_id" value="<?= (int)$action['claim_id'] ?>">
                                        <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                        <input type="hidden" name="status" value="done">
                                        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                        <button class="btn btn-sm btn-success">Erledigt</button>
                                    </form>
                                <?php else: ?>
                                    <form method="post" action="action_update.php" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="claim_id" value="<?= (int)$action['claim_id'] ?>">
                                        <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                        <input type="hidden" name="status" value="open">
                                        <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                        <button class="btn btn-sm btn-outline-secondary">Wieder öffnen</button>
                                    </form>
                                <?php endif; ?>
                            <?php else: ?>
                                <span class="small text-muted">Nur ansehen</span>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>

            <?php if (!$actions): ?>
                <tr>
                    <td colspan="6" class="action-empty">Keine passenden Maßnahmen gefunden.</td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Smartphone / kleines Tablet -->
    <div class="action-mobile-list d-lg-none">
        <?php foreach ($actions as $action): ?>
            <?php
                $claimUrl = 'action_read_open.php?action_id=' . (int)$action['id'] . '&claim_id=' . (int)$action['claim_id'];
                $isRead = !empty($action['action_read_at']);
                $trafficLevel = $action['status'] === 'done'
                    ? 'done'
                    : action_traffic_level($action);
                $cardVisualClass = $trafficLevel === 'done'
                    ? 'is-done'
                    : 'is-' . $trafficLevel;

                $dueDateValue = trim((string)($action['due_date'] ?? ''));
                $dueDateLabel = '-';
                $dueStateLabel = 'Keine Frist';
                $dueStateClass = 'text-muted';

                if ($dueDateValue !== '') {
                    $dueTimestamp = strtotime($dueDateValue);
                    $dueDateLabel = $dueTimestamp
                        ? date('d.m.Y', $dueTimestamp)
                        : $dueDateValue;

                    if ($action['status'] === 'done') {
                        $dueStateLabel = 'Abgeschlossen';
                        $dueStateClass = 'text-success';
                    } elseif ($dueDateValue < date('Y-m-d')) {
                        $dueStateLabel = 'Überfällig';
                        $dueStateClass = 'text-danger';
                    } elseif ($dueDateValue === date('Y-m-d')) {
                        $dueStateLabel = 'Heute fällig';
                        $dueStateClass = 'text-warning';
                    } elseif ($trafficLevel === 'yellow') {
                        $dueStateLabel = 'Achtung';
                        $dueStateClass = 'text-warning';
                    } elseif ($trafficLevel === 'red') {
                        $dueStateLabel = 'Kritisch';
                        $dueStateClass = 'text-danger';
                    } else {
                        $dueStateLabel = 'Im Zeitplan';
                        $dueStateClass = 'text-success';
                    }
                }
            ?>
            <div
                class="action-mobile-card <?= e($cardVisualClass) ?> <?= $isRead ? '' : 'is-unread' ?>"
                data-claim-url="<?= e($claimUrl) ?>"
            >
                <div class="action-mobile-top">
                    <a href="<?= e($claimUrl) ?>" class="action-claim-number">
                        <?= e($action['claim_number']) ?>
                    </a>

                    <div class="d-flex flex-wrap align-items-center gap-1">
                        <?php if (!$isRead): ?>
                            <span class="badge bg-primary-subtle text-primary-emphasis border border-primary-subtle">Neu</span>
                        <?php endif; ?>
                        <?= status_badge($action['status']) ?>
                    </div>
                </div>

                <div class="action-mobile-title"><?= e($action['title']) ?></div>

                <?php if ($action['description']): ?>
                    <div class="action-mobile-description"><?= e($action['description']) ?></div>
                <?php endif; ?>

                <div class="action-mobile-meta">
                    <span><?= location_badge(isset($action['standort_id']) ? (int)$action['standort_id'] : null) ?></span>
                    <span class="badge bg-secondary"><?= e($action['step_key']) ?></span>
                    <span><?= e($action['partner_name']) ?> · <?= e($action['short_description']) ?></span>
                </div>

                <div class="action-mobile-footer">
                    <div>
                        <div class="action-due-date"><?= e($dueDateLabel) ?></div>
                        <div class="action-due-state <?= e($dueStateClass) ?>"><?= e($dueStateLabel) ?></div>
                    </div>

                    <div class="action-mobile-buttons">
                        <?php if (can_edit()): ?>
                            <?php if ($action['status'] === 'open'): ?>
                                <form method="post" action="action_update.php" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="claim_id" value="<?= (int)$action['claim_id'] ?>">
                                    <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                    <input type="hidden" name="status" value="in_progress">
                                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                    <button class="btn btn-sm btn-outline-primary">In Arbeit</button>
                                </form>
                            <?php endif; ?>

                            <?php if ($action['status'] !== 'done'): ?>
                                <form method="post" action="action_update.php" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="claim_id" value="<?= (int)$action['claim_id'] ?>">
                                    <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                    <input type="hidden" name="status" value="done">
                                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                    <button class="btn btn-sm btn-success">Erledigt</button>
                                </form>
                            <?php else: ?>
                                <form method="post" action="action_update.php" class="d-inline">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="claim_id" value="<?= (int)$action['claim_id'] ?>">
                                    <input type="hidden" name="action_id" value="<?= (int)$action['id'] ?>">
                                    <input type="hidden" name="status" value="open">
                                    <input type="hidden" name="return_to" value="<?= e($returnTo) ?>">
                                    <button class="btn btn-sm btn-outline-secondary">Wieder öffnen</button>
                                </form>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!$actions): ?>
            <div class="action-empty">Keine passenden Maßnahmen gefunden.</div>
        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    const clickableItem = event.target.closest('.action-click-row, .action-mobile-card');

    if (!clickableItem) {
        return;
    }

    if (event.target.closest('a, button, form, input, select, textarea, label')) {
        return;
    }

    const url = clickableItem.getAttribute('data-claim-url');

    if (url) {
        window.location.href = url;
    }
});
</script>
<?php require __DIR__ . '/footer.php'; ?>
