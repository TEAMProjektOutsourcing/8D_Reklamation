<?php
require_once __DIR__ . '/auth.php';
require_login();

$db = pdo();
[$locationSql, $locationParams] = location_scope_condition('c');

$dashboardUser = current_user();
$dashboardUserId = (int)($dashboardUser['id'] ?? 0);

$dashboardIsAdmin = ($dashboardUser && (string)($dashboardUser['role'] ?? '') === 'admin');

function dashboard_claim_user_scope_condition(int $userId): array
{
    if ($userId <= 0) {
        return [' AND 1=0', []];
    }

    $user = current_user();

    // Admin darf alles sehen, damit er das System vollständig administrieren kann.
    if ($user && (string)($user['role'] ?? '') === 'admin') {
        return ['', []];
    }

    $conditions = ['c.responsible_user_id = ?'];
    $params = [$userId];

    if (db_table_exists('claim_group_assignments') && db_table_exists('claim_group_members')) {
        $conditions[] = "EXISTS (
            SELECT 1
            FROM claim_group_assignments d_cga
            JOIN claim_group_members d_cgm ON d_cgm.group_id = d_cga.group_id
            WHERE d_cga.claim_id = c.id
              AND d_cgm.user_id = ?
        )";
        $params[] = $userId;
    }

    if (db_table_exists('claim_actions')) {
        $conditions[] = "EXISTS (
            SELECT 1
            FROM claim_actions d_ca
            WHERE d_ca.claim_id = c.id
              AND d_ca.responsible_user_id = ?
              AND d_ca.status NOT IN ('done','cancelled')
        )";
        $params[] = $userId;
    }

    return [' AND (' . implode(' OR ', $conditions) . ')', $params];
}

[$dashboardUserScopeSql, $dashboardUserScopeParams] = dashboard_claim_user_scope_condition($dashboardUserId);


// Das Dashboard ist arbeitsorientiert:
// Die Maßnahmen-Zusammenfassung und die Maßnahmenliste zeigen immer nur die eigenen Aufgaben.
$dashboardActionWhereSql = "WHERE a.status NOT IN ('done','cancelled')"
    . $locationSql
    . " AND a.responsible_user_id = ?";
$dashboardActionParams = array_merge($locationParams, [$dashboardUserId]);

$dueStmt = $db->prepare("SELECT a.*, c.claim_number, c.short_description, c.standort_id, u.name AS responsible_name
    FROM claim_actions a
    JOIN claims c ON c.id = a.claim_id
    LEFT JOIN users u ON u.id = a.responsible_user_id
    " . $dashboardActionWhereSql . "
    ORDER BY CASE WHEN a.due_date IS NULL THEN 1 ELSE 0 END, a.due_date ASC
    LIMIT 10");
$dueStmt->execute($dashboardActionParams);
$actions = $dueStmt->fetchAll();

$myActionStmt = $db->prepare("SELECT
    COALESCE(SUM(CASE WHEN a.status IN ('open','in_progress') THEN 1 ELSE 0 END), 0) AS open_count,
    COALESCE(SUM(CASE WHEN a.status IN ('open','in_progress') AND a.due_date IS NOT NULL AND a.due_date < CURDATE() THEN 1 ELSE 0 END), 0) AS overdue_count,
    COALESCE(SUM(CASE WHEN a.status IN ('open','in_progress') AND a.due_date = CURDATE() THEN 1 ELSE 0 END), 0) AS today_count,
    COALESCE(SUM(CASE WHEN a.status = 'done' THEN 1 ELSE 0 END), 0) AS done_count
    FROM claim_actions a
    JOIN claims c ON c.id = a.claim_id
    WHERE a.responsible_user_id = ?" . $locationSql);
$myActionStmt->execute(array_merge([(int)current_user()['id']], $locationParams));
$myActionCounts = $myActionStmt->fetch() ?: ['open_count' => 0, 'overdue_count' => 0, 'today_count' => 0, 'done_count' => 0];

$latestStmt = $db->prepare("SELECT c.*, u.name AS responsible_name
    FROM claims c
    LEFT JOIN users u ON u.id = c.responsible_user_id
    WHERE 1=1" . $locationSql . $dashboardUserScopeSql . "
    ORDER BY c.created_at DESC
    LIMIT 8");
$latestStmt->execute(array_merge($locationParams, $dashboardUserScopeParams));
$latestClaims = $latestStmt->fetchAll();

$selectedLocationName = locations_enabled() ? (selected_location()['name'] ?? 'Alle Standorte') : null;


function dashboard_claim_href(int $claimId): string
{
    return 'claim_view.php?id=' . $claimId;
}

function dashboard_action_href(array $action): string
{
    $claimId = (int)($action['claim_id'] ?? 0);
    $actionId = (int)($action['id'] ?? 0);

    if ($actionId > 0 && $claimId > 0 && file_exists(__DIR__ . '/action_read_open.php')) {
        return 'action_read_open.php?action_id=' . $actionId . '&claim_id=' . $claimId;
    }

    return 'claim_view.php?id=' . $claimId . '#actions';
}


require __DIR__ . '/header.php';
?>


<style>
    .dashboard-user-focus .dashboard-page-head {
        display: flex;
        align-items: flex-end;
        justify-content: space-between;
        gap: 1rem;
        margin-bottom: 1rem;
    }

    .dashboard-user-focus .dashboard-page-head h1 {
        margin: 0;
        color: #0f172a;
        font-size: clamp(1.55rem, 3vw, 2.15rem);
        font-weight: 900;
        letter-spacing: -.03em;
    }

    .dashboard-user-focus .dashboard-page-context {
        margin-top: .25rem;
        color: #64748b;
        font-size: .9rem;
    }

    .dashboard-user-focus .dashboard-view-label {
        color: #64748b;
        font-size: .78rem;
        font-weight: 800;
        white-space: nowrap;
    }







    .dashboard-user-focus .stat-card,
    .dashboard-user-focus .content-card {
        border: 1px solid rgba(148, 163, 184, .18);
        border-radius: 18px;
        box-shadow: 0 8px 22px rgba(15, 23, 42, .04);
        transition: transform .15s ease, box-shadow .15s ease;
    }

    .dashboard-user-focus .stat-card:hover {
        transform: translateY(-1px);
        box-shadow: 0 12px 28px rgba(15, 23, 42, .065);
    }

    .dashboard-user-focus .content-card {
        overflow: hidden;
    }

    .dashboard-user-focus .dashboard-click-row {
        cursor: pointer;
    }

    .dashboard-user-focus .dashboard-click-row:hover td {
        background: #f8fbff;
    }

    @media (max-width: 991.98px) {
    }

    @media (max-width: 575.98px) {
        .dashboard-user-focus .dashboard-page-head {
            align-items: flex-start;
            margin-bottom: .8rem;
        }

        .dashboard-user-focus .dashboard-view-label {
            display: none;
        }




        .dashboard-user-focus .stat-card,
        .dashboard-user-focus .content-card {
            border-radius: 16px;
        }
    }

    .dashboard-user-focus .content-card .card-header {
        min-height: 52px;
    }


    .dashboard-user-focus .dashboard-measure-cards .stat-card {
        min-height: 108px;
        background: #ffffff;
    }

    .dashboard-user-focus .dashboard-measure-cards .text-muted {
        font-size: .9rem;
        font-weight: 650;
    }

    .dashboard-user-focus .dashboard-measure-cards .value {
        margin-top: .35rem;
        color: #0f172a;
        font-size: 1.85rem;
        font-weight: 900;
        line-height: 1;
    }

    .dashboard-user-focus .dashboard-measure-cards a:focus-visible .stat-card {
        outline: 3px solid rgba(13, 110, 253, .22);
        outline-offset: 2px;
    }

    @media (max-width: 575.98px) {
        .dashboard-user-focus .dashboard-measure-cards {
            --bs-gutter-x: .65rem;
            --bs-gutter-y: .65rem;
        }

        .dashboard-user-focus .dashboard-measure-cards .stat-card {
            min-height: 94px;
            padding: .85rem !important;
        }

        .dashboard-user-focus .dashboard-measure-cards .text-muted {
            font-size: .82rem;
        }

        .dashboard-user-focus .dashboard-measure-cards .value {
            font-size: 1.55rem;
        }
    }


    .dashboard-user-focus .dashboard-measure-cards .stat-help {
        margin-top: .45rem;
        color: #64748b;
        font-size: .78rem;
        line-height: 1.35;
    }

    .dashboard-user-focus .dashboard-measure-cards .stat-card {
        min-height: 138px;
    }

    @media (max-width: 575.98px) {
        .dashboard-user-focus .dashboard-measure-cards .stat-card {
            min-height: 126px;
        }

        .dashboard-user-focus .dashboard-measure-cards .stat-help {
            font-size: .72rem;
            line-height: 1.3;
        }
    }


    .dashboard-user-focus .action-compact-table {
        min-width: 620px;
        font-size: .84rem;
    }

    .dashboard-user-focus .action-compact-table th {
        padding: .7rem .65rem;
        color: #475569;
        font-size: .74rem;
        font-weight: 800;
        letter-spacing: .025em;
        text-transform: uppercase;
        white-space: nowrap;
    }

    .dashboard-user-focus .action-compact-table td {
        padding: .72rem .65rem;
        vertical-align: middle;
    }

    .dashboard-user-focus .action-number-cell {
        width: 30%;
        min-width: 170px;
    }

    .dashboard-user-focus .action-title-cell {
        width: 32%;
        min-width: 175px;
    }

    .dashboard-user-focus .action-location-cell {
        width: 16%;
        min-width: 105px;
        white-space: nowrap;
    }

    .dashboard-user-focus .action-due-cell {
        width: 22%;
        min-width: 145px;
        white-space: nowrap;
    }

    .dashboard-user-focus .action-problem-text {
        max-width: 210px;
        margin-top: .2rem;
        overflow: hidden;
        text-overflow: ellipsis;
        white-space: nowrap;
    }

    .dashboard-user-focus .action-compact-table .badge {
        max-width: 100%;
        white-space: normal;
        text-align: left;
    }


    .dashboard-user-focus .table-modern th:nth-child(2),
    .dashboard-user-focus .table-modern td:nth-child(2) {
        width: 1%;
        white-space: nowrap;
    }

</style>


<div class="dashboard-shell dashboard-user-focus">
    <?php if (current_user()['email'] === 'admin@example.com'): ?>
        <div class="alert alert-warning">
            Du bist noch mit dem Demo-Admin angemeldet. Bitte ändere unter <a href="profile.php" class="alert-link">Mein Profil</a> die E-Mail und das Passwort.
        </div>
    <?php endif; ?>

    <div class="dashboard-page-head">
        <div>
            <h1>Dashboard</h1>
            <div class="dashboard-page-context">
                <?= $selectedLocationName ? e($selectedLocationName) . ' · ' : '' ?>
                <?= $dashboardIsAdmin ? 'Gesamtübersicht' : 'Mein Verantwortungsbereich' ?>
            </div>
        </div>
        <div class="dashboard-view-label">
            <?= $dashboardIsAdmin ? 'Admin-Ansicht' : 'Persönliche Ansicht' ?>
        </div>
    </div>

    <h2 class="h6 fw-bold mb-3">Meine Maßnahmen</h2>

    <div class="row g-3 mb-4 dashboard-measure-cards">
        <div class="col-6 col-md-3">
            <a class="text-decoration-none" href="my_actions.php?status=open">
                <div class="card stat-card p-3 h-100 border-primary">
                    <div class="text-muted">Offen</div>
                    <div class="value"><?= (int)($myActionCounts['open_count'] ?? 0) ?></div>
                    <div class="stat-help">Noch nicht abgeschlossene Maßnahmen.</div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-3">
            <a class="text-decoration-none" href="my_actions.php?status=overdue">
                <div class="card stat-card p-3 h-100">
                    <div class="text-muted">Überfällig</div>
                    <div class="value text-danger"><?= (int)($myActionCounts['overdue_count'] ?? 0) ?></div>
                    <div class="stat-help">Die hinterlegte Frist ist überschritten.</div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-3">
            <a class="text-decoration-none" href="my_actions.php?status=today">
                <div class="card stat-card p-3 h-100">
                    <div class="text-muted">Heute fällig</div>
                    <div class="value text-warning"><?= (int)($myActionCounts['today_count'] ?? 0) ?></div>
                    <div class="stat-help">Diese Maßnahmen sind heute zu erledigen.</div>
                </div>
            </a>
        </div>

        <div class="col-6 col-md-3">
            <a class="text-decoration-none" href="my_actions.php?status=done">
                <div class="card stat-card p-3 h-100">
                    <div class="text-muted">Erledigt</div>
                    <div class="value text-success"><?= (int)($myActionCounts['done_count'] ?? 0) ?></div>
                    <div class="stat-help">Bereits erfolgreich abgeschlossene Maßnahmen.</div>
                </div>
            </a>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-7">
            <div class="card content-card">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span><?= $dashboardIsAdmin ? 'Aktuelle Reklamationen' : 'Aktuelle Reklamationen in meinem Bereich' ?></span>
                    <a href="claims.php" class="btn btn-sm btn-outline-primary">Alle anzeigen</a>
                </div>

                <!-- Desktop / Tablet groß: Tabelle -->
                <div class="table-responsive d-none d-lg-block">
                    <table class="table table-hover align-middle mb-0 table-modern">
                        <thead class="table-light">
                        <tr>
                            <th>Nr.</th>
                            <th>Standort</th>
                            <th>Partner</th>
                            <th>Problem</th>
                            <th>Status</th>
                            <th>Verantwortlich</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($latestClaims as $claim): ?>
                            <?php $claimHref = dashboard_claim_href((int)$claim['id']); ?>
                            <tr class="dashboard-click-row" data-href="<?= e($claimHref) ?>">
                                <td>
                                    <a href="<?= e($claimHref) ?>" class="fw-bold"><?= e($claim['claim_number']) ?></a>
                                </td>
                                <td class="text-nowrap">
                                    <?= location_badge(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null) ?>
                                </td>
                                <td><?= e($claim['partner_name']) ?></td>
                                <td><?= e($claim['short_description']) ?></td>
                                <td><?= status_badge($claim['status']) ?></td>
                                <td><?= e($claim['responsible_name'] ?? '-') ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (!$latestClaims): ?>
                            <tr><td colspan="6" class="text-center text-muted py-4">Keine Reklamationen in deinem Bereich.</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Smartphone / Tablet klein: Karten -->
                <div class="mobile-card-list d-lg-none">
                    <?php foreach ($latestClaims as $claim): ?>
                        <a href="<?= e(dashboard_claim_href((int)$claim['id'])) ?>" class="mobile-item-card">
                            <div class="mobile-item-top">
                                <div class="mobile-item-number"><?= e($claim['claim_number']) ?></div>
                                <div><?= status_badge($claim['status']) ?></div>
                            </div>

                            <div class="mobile-item-title"><?= e($claim['short_description']) ?></div>

                            <div class="mobile-meta">
                                <div class="mobile-meta-row">
                                    <span class="mobile-meta-label">Partner:</span>
                                    <span><?= e($claim['partner_name']) ?></span>
                                </div>
                                <div class="mobile-meta-row">
                                    <span class="mobile-meta-label">Verantw.:</span>
                                    <span><?= e($claim['responsible_name'] ?? '-') ?></span>
                                </div>
                            </div>

                            <div class="mobile-card-footer">
                                <?= location_badge(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null) ?>
                                <span class="badge text-bg-light">Öffnen</span>
                            </div>
                        </a>
                    <?php endforeach; ?>

                    <?php if (!$latestClaims): ?>
                        <div class="mobile-empty">Keine Reklamationen in deinem Bereich.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card content-card">
                <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center">
                    <span>Meine offenen Maßnahmen</span>
                    <a href="my_actions.php" class="btn btn-sm btn-outline-primary">Meine Liste</a>
                </div>

                <!-- Desktop / Tablet groß: kompakte Tabelle -->
                <div class="table-responsive d-none d-lg-block">
                    <table class="table table-hover align-middle mb-0 table-modern action-compact-table">
                        <thead class="table-light">
                        <tr>
                            <th>Nr.</th>
                            <th>Maßnahme</th>
                            <th>Standort</th>
                            <th>Frist</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($actions as $action): ?>
                            <?php $actionHref = dashboard_action_href($action); ?>
                            <tr class="dashboard-click-row <?= e(action_row_class($action)) ?>" data-href="<?= e($actionHref) ?>">
                                <td class="action-number-cell">
                                    <a href="<?= e($actionHref) ?>" class="fw-bold text-decoration-none">
                                        <?= e($action['claim_number']) ?>
                                    </a>
                                    <div class="small text-muted action-problem-text">
                                        <?= e($action['short_description']) ?>
                                    </div>
                                </td>
                                <td class="action-title-cell">
                                    <div class="fw-semibold"><?= e($action['title']) ?></div>
                                    <span class="badge bg-secondary mt-1"><?= e($action['step_key']) ?></span>
                                </td>
                                <td class="action-location-cell">
                                    <?= location_badge(isset($action['standort_id']) ? (int)$action['standort_id'] : null) ?>
                                </td>
                                <td class="action-due-cell">
                                    <div class="fw-semibold">
                                        <?= $action['due_date'] ? e(date('d.m.Y', strtotime((string)$action['due_date']))) : '-' ?>
                                    </div>
                                    <div class="mt-1"><?= action_traffic_badge($action) ?></div>
                                </td>
                            </tr>
                        <?php endforeach; ?>

                        <?php if (!$actions): ?>
                            <tr>
                                <td colspan="4" class="text-center text-muted py-4">
                                    Keine offenen Maßnahmen für dich.
                                </td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Smartphone / Tablet klein: Karten -->
                <div class="mobile-card-list d-lg-none">
                    <?php foreach ($actions as $action): ?>
                        <?php
                            $trafficClass = action_row_class($action);
                            $mobileActionClass = strpos((string)$trafficClass, 'danger') !== false ? 'red' : (strpos((string)$trafficClass, 'warning') !== false ? 'yellow' : '');
                        ?>
                        <a class="mobile-item-card mobile-action-card <?= e($mobileActionClass) ?>" href="<?= e(dashboard_action_href($action)) ?>">
                            <div class="mobile-item-top">
                                <div class="mobile-item-number"><?= e($action['claim_number']) ?></div>
                                <span class="badge bg-secondary"><?= e($action['step_key']) ?></span>
                            </div>

                            <div class="mobile-item-title"><?= e($action['title']) ?></div>

                            <div class="mobile-meta">
                                <div class="mobile-meta-row">
                                    <span class="mobile-meta-label">Problem:</span>
                                    <span><?= e($action['short_description']) ?></span>
                                </div>
                                <div class="mobile-meta-row">
                                    <span class="mobile-meta-label">Verantw.:</span>
                                    <span><?= e($action['responsible_name'] ?? '-') ?></span>
                                </div>
                                <div class="mobile-meta-row">
                                    <span class="mobile-meta-label">Frist:</span>
                                    <span><?= e($action['due_date'] ?: '-') ?></span>
                                </div>
                            </div>

                            <div class="mobile-card-footer">
                                <?= action_traffic_badge($action) ?>
                                <?= location_badge(isset($action['standort_id']) ? (int)$action['standort_id'] : null) ?>
                                <span class="badge text-bg-light">Zur Maßnahme</span>
                            </div>
                        </a>
                    <?php endforeach; ?>

                    <?php if (!$actions): ?>
                        <div class="mobile-empty">Keine offenen Maßnahmen für dich.</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>


<script>
document.addEventListener('click', function (event) {
    const clickedInfoButton = event.target.closest('.dashboard-info-icon');

    document.querySelectorAll('.dashboard-info-wrap.is-open').forEach(function (wrap) {
        if (!clickedInfoButton || !wrap.contains(clickedInfoButton)) {
            wrap.classList.remove('is-open');
        }
    });

    if (clickedInfoButton) {
        event.preventDefault();
        event.stopPropagation();

        const wrap = clickedInfoButton.closest('.dashboard-info-wrap');
        if (wrap) {
            wrap.classList.toggle('is-open');
        }
        return;
    }

    const row = event.target.closest('.dashboard-click-row');
    if (row && !event.target.closest('a, button, form, input, select, textarea, label')) {
        const href = row.getAttribute('data-href');
        if (href) {
            window.location.href = href;
        }
    }
});
</script>

<?php require __DIR__ . '/footer.php'; ?>
