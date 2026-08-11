<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/qm_helper.php';
require_once __DIR__ . '/analytics_access_helper.php';
require_login();



if (!analytics_can_view()) {
    http_response_code(403);
    require __DIR__ . '/header.php';
    ?>
    <style>
        .forbidden-wrap {
            min-height: calc(100vh - 170px);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }

        .forbidden-card {
            width: min(100%, 760px);
            background: linear-gradient(180deg, #ffffff 0%, #f7faff 100%);
            border: 1px solid rgba(13, 110, 253, .12);
            border-radius: 28px;
            box-shadow: 0 24px 60px rgba(15, 23, 42, .10);
            overflow: hidden;
            position: relative;
        }

        .forbidden-card::before {
            content: "";
            position: absolute;
            inset: 0 0 auto 0;
            height: 6px;
            background: linear-gradient(90deg, #0d6efd 0%, #57a6ff 45%, #d8ecff 100%);
        }

        .forbidden-card-body {
            padding: 2.5rem 2rem;
            text-align: center;
        }

        .forbidden-logo {
            max-width: 320px;
            width: 100%;
            height: auto;
            margin: 0 auto 1.5rem;
            display: block;
        }

        .forbidden-badge {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .45rem .9rem;
            border-radius: 999px;
            background: #eef5ff;
            color: #0d6efd;
            font-weight: 700;
            font-size: .92rem;
            margin-bottom: 1.1rem;
        }

        .forbidden-title {
            font-size: clamp(1.8rem, 3vw, 2.45rem);
            line-height: 1.1;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: .85rem;
        }

        .forbidden-text {
            font-size: 1rem;
            color: #526071;
            max-width: 560px;
            margin: 0 auto 1.35rem;
        }

        .forbidden-hint {
            background: #f8fbff;
            border: 1px solid rgba(15, 23, 42, .08);
            border-radius: 16px;
            padding: 1rem 1.1rem;
            color: #334155;
            max-width: 540px;
            margin: 0 auto 1.4rem;
        }

        .forbidden-countdown {
            font-weight: 800;
            color: #0d6efd;
        }

        .forbidden-actions {
            display: flex;
            justify-content: center;
            flex-wrap: wrap;
            gap: .75rem;
            margin-top: 1rem;
        }

        @media (max-width: 575.98px) {
            .forbidden-card-body {
                padding: 2rem 1.1rem;
            }

            .forbidden-logo {
                max-width: 240px;
            }
        }
    </style>

    <div class="forbidden-wrap">
        <div class="forbidden-card">
            <div class="forbidden-card-body">
                <img
                    src="assets/logo-reklamation8d-light.png?v=60"
                    alt="Reklamation8D"
                    class="forbidden-logo"
                    onerror="this.style.display='none';"
                >

                <div class="forbidden-badge">🔒 Zugriff gesperrt</div>

                <h1 class="forbidden-title">Du bist nicht berechtigt, diesen Inhalt zu lesen.</h1>

                <p class="forbidden-text">
                    Der Bereich <strong>Auswertung</strong> ist nur für Qualität, Management,
                    Betriebsmanagement und Admin freigegeben.
                </p>

                <div class="forbidden-hint">
                    Du wirst in <span class="forbidden-countdown" id="forbiddenCountdown">10</span> Sekunden
                    automatisch zurück zum Dashboard weitergeleitet.
                </div>

                <div class="forbidden-actions">
                    <a href="dashboard.php" class="btn btn-primary btn-lg">Jetzt zum Dashboard</a>
                </div>
            </div>
        </div>
    </div>

    <script>
        (function () {
            var seconds = 10;
            var countdownEl = document.getElementById('forbiddenCountdown');

            var interval = window.setInterval(function () {
                seconds -= 1;

                if (countdownEl && seconds >= 0) {
                    countdownEl.textContent = String(seconds);
                }

                if (seconds <= 0) {
                    window.clearInterval(interval);
                    window.location.href = 'dashboard.php';
                }
            }, 1000);
        })();
    </script>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

$db = pdo();
[$locationSql, $locationParams] = location_scope_condition('c');
$where = ' WHERE 1=1' . $locationSql;

function analytics_fetch_one(string $sql, array $params = []): array
{
    $stmt = pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetch() ?: [];
}

function analytics_fetch_all(string $sql, array $params = []): array
{
    $stmt = pdo()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

$scopeLabel = 'Meine erlaubten Standorte';
if (locations_enabled()) {
    $user = current_user();
    $selectedLocation = selected_location();
    if ($user && $user['role'] === 'admin' && selected_location_id() === null) {
        $scopeLabel = 'Alle Standorte';
    } elseif ($selectedLocation) {
        $scopeLabel = (string)$selectedLocation['kuerzel'] . ' · ' . (string)$selectedLocation['name'];
    }
}

$claimSummary = analytics_fetch_one("SELECT
        COUNT(*) AS total,
        SUM(CASE WHEN c.status = 'new' THEN 1 ELSE 0 END) AS new_count,
        SUM(CASE WHEN c.status IN ('in_progress','waiting','overdue') THEN 1 ELSE 0 END) AS active_count,
        SUM(CASE WHEN c.status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count,
        SUM(CASE WHEN c.status = 'closed' THEN 1 ELSE 0 END) AS closed_count,
        SUM(CASE WHEN c.priority IN ('high','critical') THEN 1 ELSE 0 END) AS high_count
    FROM claims c" . $where, $locationParams);

$actionSummary = analytics_fetch_one("SELECT
        COUNT(*) AS total_actions,
        SUM(CASE WHEN a.status IN ('open','in_progress') THEN 1 ELSE 0 END) AS open_actions,
        SUM(CASE WHEN a.status = 'done' THEN 1 ELSE 0 END) AS done_actions,
        SUM(CASE WHEN a.status IN ('open','in_progress') AND ((a.due_date IS NOT NULL AND a.due_date < CURDATE()) OR DATEDIFF(CURDATE(), DATE(a.created_at)) >= 11) THEN 1 ELSE 0 END) AS red_actions,
        SUM(CASE WHEN a.status IN ('open','in_progress') AND NOT (a.due_date IS NOT NULL AND a.due_date < CURDATE()) AND DATEDIFF(CURDATE(), DATE(a.created_at)) BETWEEN 6 AND 10 THEN 1 ELSE 0 END) AS yellow_actions,
        SUM(CASE WHEN a.status IN ('open','in_progress') AND NOT (a.due_date IS NOT NULL AND a.due_date < CURDATE()) AND DATEDIFF(CURDATE(), DATE(a.created_at)) <= 5 THEN 1 ELSE 0 END) AS green_actions
    FROM claim_actions a
    JOIN claims c ON c.id = a.claim_id" . $where, $locationParams);

$avgClose = analytics_fetch_one("SELECT
        AVG(TIMESTAMPDIFF(DAY, c.created_at, COALESCE(c.closed_at, c.updated_at, NOW()))) AS avg_days
    FROM claims c" . $where . " AND c.status = 'closed'", $locationParams);

$statusRows = analytics_fetch_all("SELECT c.status, COUNT(*) AS count_value
    FROM claims c" . $where . "
    GROUP BY c.status
    ORDER BY count_value DESC", $locationParams);

$priorityRows = analytics_fetch_all("SELECT c.priority, COUNT(*) AS count_value
    FROM claims c" . $where . "
    GROUP BY c.priority
    ORDER BY FIELD(c.priority, 'critical','high','medium','low')", $locationParams);

$partnerRows = analytics_fetch_all("SELECT c.partner_name, COUNT(*) AS count_value,
        SUM(CASE WHEN c.status NOT IN ('closed','rejected','archived') THEN 1 ELSE 0 END) AS open_count
    FROM claims c" . $where . "
    GROUP BY c.partner_name
    ORDER BY count_value DESC, c.partner_name ASC
    LIMIT 10", $locationParams);

$articleRows = analytics_fetch_all("SELECT COALESCE(NULLIF(c.article_number, ''), 'ohne Artikelnummer') AS article_number,
        COUNT(*) AS count_value
    FROM claims c" . $where . "
    GROUP BY COALESCE(NULLIF(c.article_number, ''), 'ohne Artikelnummer')
    ORDER BY count_value DESC, article_number ASC
    LIMIT 10", $locationParams);

$locationRows = [];
if (locations_enabled() && db_table_exists('standorte')) {
    $locationRows = analytics_fetch_all("SELECT s.kuerzel, s.name,
            COUNT(c.id) AS claim_count,
            SUM(CASE WHEN c.status NOT IN ('closed','rejected','archived') THEN 1 ELSE 0 END) AS open_count,
            SUM(CASE WHEN c.status = 'overdue' THEN 1 ELSE 0 END) AS overdue_count
        FROM claims c
        LEFT JOIN standorte s ON s.id = c.standort_id" . $where . "
        GROUP BY s.id, s.kuerzel, s.name
        ORDER BY claim_count DESC", $locationParams);
}

$monthRows = analytics_fetch_all("SELECT DATE_FORMAT(c.claim_date, '%Y-%m') AS month_key, COUNT(*) AS count_value
    FROM claims c" . $where . "
    GROUP BY DATE_FORMAT(c.claim_date, '%Y-%m')
    ORDER BY month_key DESC
    LIMIT 12", $locationParams);

function metric_number(array $row, string $key): int
{
    return (int)($row[$key] ?? 0);
}

function analytics_info(string $text): string
{
    return '<span class="analytics-info-wrap no-print">'
        . '<button type="button" class="analytics-info-icon" aria-label="Info anzeigen" title="Info">i</button>'
        . '<span class="analytics-info-text" role="tooltip">' . e($text) . '</span>'
        . '</span>';
}


$qmEnabled = false;
$qmRepeatRows = [];
$qmRootCauseRows = [];

try {
    $qmEnabled = qm_claim_fields_enabled();

    if ($qmEnabled) {
        $qmRepeatRows = analytics_fetch_all("SELECT
                COALESCE(c.error_category, '') AS error_category,
                COALESCE(c.error_pattern, '') AS error_pattern,
                COALESCE(c.process_area, '') AS process_area,
                COUNT(*) AS count_value,
                SUM(CASE WHEN c.status NOT IN ('closed','rejected','archived') THEN 1 ELSE 0 END) AS open_count,
                MAX(c.claim_date) AS last_claim_date
            FROM claims c" . $where . "
              AND c.claim_date >= DATE_SUB(CURDATE(), INTERVAL 180 DAY)
              AND (c.error_category IS NOT NULL OR c.error_pattern IS NOT NULL OR c.process_area IS NOT NULL)
            GROUP BY c.error_category, c.error_pattern, c.process_area
            HAVING count_value >= 2
            ORDER BY count_value DESC, last_claim_date DESC
            LIMIT 12", $locationParams);

        $qmRootCauseRows = analytics_fetch_all("SELECT
                COALESCE(c.root_cause_category, '') AS root_cause_category,
                COUNT(*) AS count_value
            FROM claims c" . $where . "
              AND c.root_cause_category IS NOT NULL
              AND c.root_cause_category <> ''
            GROUP BY c.root_cause_category
            ORDER BY count_value DESC
            LIMIT 10", $locationParams);
    }
} catch (Throwable $e) {
    error_log('QM Auswertung fehlgeschlagen: ' . $e->getMessage());
    $qmEnabled = false;
}


$qmAiWarningRows = [];
$qmAiWarningCounts = [];

try {
    if (qm_ai_tables_enabled()) {
        $qmAiWarningCounts = analytics_fetch_all("SELECT effectiveness_warning, COUNT(*) AS count_value
            FROM (
                SELECT a.*
                FROM claim_ai_analysis a
                INNER JOIN (
                    SELECT claim_id, MAX(id) AS max_id
                    FROM claim_ai_analysis
                    GROUP BY claim_id
                ) latest ON latest.max_id = a.id
            ) x
            GROUP BY effectiveness_warning
            ORDER BY count_value DESC", []);

        $qmAiWarningRows = analytics_fetch_all("SELECT a.*, c.claim_number, c.short_description, c.claim_date, c.status
            FROM claim_ai_analysis a
            JOIN claims c ON c.id = a.claim_id
            INNER JOIN (
                SELECT claim_id, MAX(id) AS max_id
                FROM claim_ai_analysis
                GROUP BY claim_id
            ) latest ON latest.max_id = a.id" . $where . "
            ORDER BY
              CASE a.effectiveness_warning WHEN 'red' THEN 1 WHEN 'yellow' THEN 2 ELSE 3 END,
              a.created_at DESC
            LIMIT 10", $locationParams);
    }
} catch (Throwable $e) {
    error_log('QM KI-Light Auswertung fehlgeschlagen: ' . $e->getMessage());
}


$qmFeedbackRows = [];
$qmFeedbackCounts = [];

try {
    if (qm_feedback_enabled()) {
        $qmFeedbackCounts = analytics_fetch_all("SELECT feedback_value, COUNT(*) AS count_value
            FROM claim_ai_feedback
            GROUP BY feedback_value
            ORDER BY count_value DESC", []);

        $qmFeedbackRows = analytics_fetch_all("SELECT f.*, c.claim_number, c.short_description, c.claim_date, c.status
            FROM claim_ai_feedback f
            JOIN claims c ON c.id = f.claim_id" . $where . "
            ORDER BY f.created_at DESC, f.id DESC
            LIMIT 12", $locationParams);
    }
} catch (Throwable $e) {
    error_log('QM Feedback Auswertung fehlgeschlagen: ' . $e->getMessage());
}


$qmAnalysisClaimRows = [];

try {
    if ($qmEnabled && qm_ai_tables_enabled()) {
        if (qm_feedback_enabled()) {
            $qmAnalysisClaimRows = analytics_fetch_all("SELECT
                    c.id,
                    c.claim_number,
                    c.short_description,
                    c.claim_date,
                    c.status,
                    c.error_category,
                    c.error_pattern,
                    c.process_area,
                    latest.max_id AS analysis_id,
                    a.effectiveness_warning,
                    a.detected_error_pattern,
                    a.created_at AS analysis_at,
                    f.feedback_value,
                    f.created_at AS feedback_at
                FROM claims c
                LEFT JOIN (
                    SELECT claim_id, MAX(id) AS max_id
                    FROM claim_ai_analysis
                    GROUP BY claim_id
                ) latest ON latest.claim_id = c.id
                LEFT JOIN claim_ai_analysis a ON a.id = latest.max_id
                LEFT JOIN (
                    SELECT analysis_id, MAX(id) AS max_feedback_id
                    FROM claim_ai_feedback
                    WHERE analysis_id IS NOT NULL
                    GROUP BY analysis_id
                ) latest_fb ON latest_fb.analysis_id = latest.max_id
                LEFT JOIN claim_ai_feedback f ON f.id = latest_fb.max_feedback_id
                " . $where . "
                  AND (
                    c.error_category IS NOT NULL
                    OR c.error_pattern IS NOT NULL
                    OR c.process_area IS NOT NULL
                    OR c.root_cause_category IS NOT NULL
                  )
                  AND (
                    latest.max_id IS NULL
                    OR latest_fb.max_feedback_id IS NULL
                  )
                ORDER BY
                  CASE WHEN latest.max_id IS NULL THEN 0 ELSE 1 END,
                  c.claim_date DESC,
                  c.id DESC
                LIMIT 25", $locationParams);
        } else {
            $qmAnalysisClaimRows = analytics_fetch_all("SELECT
                    c.id,
                    c.claim_number,
                    c.short_description,
                    c.claim_date,
                    c.status,
                    c.error_category,
                    c.error_pattern,
                    c.process_area,
                    latest.max_id AS analysis_id,
                    a.effectiveness_warning,
                    a.detected_error_pattern,
                    a.created_at AS analysis_at
                FROM claims c
                LEFT JOIN (
                    SELECT claim_id, MAX(id) AS max_id
                    FROM claim_ai_analysis
                    GROUP BY claim_id
                ) latest ON latest.claim_id = c.id
                LEFT JOIN claim_ai_analysis a ON a.id = latest.max_id
                " . $where . "
                  AND (
                    c.error_category IS NOT NULL
                    OR c.error_pattern IS NOT NULL
                    OR c.process_area IS NOT NULL
                    OR c.root_cause_category IS NOT NULL
                  )
                ORDER BY
                  CASE WHEN latest.max_id IS NULL THEN 0 ELSE 1 END,
                  c.claim_date DESC,
                  c.id DESC
                LIMIT 25", $locationParams);
        }
    }
} catch (Throwable $e) {
    error_log('QM Analyse Claim-Liste fehlgeschlagen: ' . $e->getMessage());
    $qmAnalysisClaimRows = [];
}

require __DIR__ . '/header.php';
?>

<style>
.analytics-info-wrap {
    position: relative;
    display: inline-flex;
    align-items: center;
    vertical-align: middle;
}

.analytics-info-icon {
    width: 18px;
    height: 18px;
    border: 1px solid #cbd5e1;
    border-radius: 999px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    margin-left: .35rem;
    padding: 0;
    background: #ffffff;
    color: #0d6efd;
    font-size: .72rem;
    font-weight: 800;
    line-height: 1;
    cursor: pointer;
}

.analytics-info-icon:hover,
.analytics-info-icon:focus {
    background: #eef6ff;
    border-color: #0d6efd;
    outline: none;
}

.analytics-info-text {
    position: absolute;
    z-index: 2500;
    top: calc(100% + 8px);
    left: 50%;
    transform: translateX(-50%);
    width: min(310px, calc(100vw - 2rem));
    display: none !important;
    padding: .7rem .8rem;
    border-radius: 14px;
    background: #0f172a;
    color: #ffffff;
    box-shadow: 0 18px 40px rgba(15, 23, 42, .25);
    font-size: .82rem;
    font-weight: 500;
    line-height: 1.35;
    text-align: left;
    white-space: normal;
}

.analytics-info-text::before {
    content: "";
    position: absolute;
    left: 50%;
    top: -6px;
    transform: translateX(-50%);
    border-left: 6px solid transparent;
    border-right: 6px solid transparent;
    border-bottom: 6px solid #0f172a;
}

.analytics-info-wrap:hover .analytics-info-text,
.analytics-info-wrap:focus-within .analytics-info-text,
.analytics-info-wrap.is-open .analytics-info-text {
    display: block !important;
}

.analytics-title-with-info,
.analytics-label-with-info {
    display: inline-flex;
    align-items: center;
    gap: .1rem;
}

.kpi-card {
    border-radius: 16px;
}

@media (max-width: 575.98px) {
    .analytics-info-wrap {
        position: static;
    }

    .analytics-info-text {
        position: fixed;
        left: 1rem;
        right: 1rem;
        top: auto;
        bottom: 1rem;
        transform: none;
        width: auto;
        max-width: none;
        border-radius: 16px;
    }

    .analytics-info-text::before {
        display: none;
    }
}
</style>

<script>
document.addEventListener('click', function (event) {
    const clickedInfoButton = event.target.closest('.analytics-info-icon');

    document.querySelectorAll('.analytics-info-wrap.is-open').forEach(function (wrap) {
        if (!clickedInfoButton || !wrap.contains(clickedInfoButton)) {
            wrap.classList.remove('is-open');
        }
    });

    if (!clickedInfoButton) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    const wrap = clickedInfoButton.closest('.analytics-info-wrap');
    if (wrap) {
        wrap.classList.toggle('is-open');
    }
});
</script>


<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <div class="text-muted small">Auswertung</div>
        <h1 class="h3 fw-bold mb-1">Qualitäts- und Reklamationsauswertung</h1>
        <div class="text-muted analytics-label-with-info">Bereich: <strong><?= e($scopeLabel) ?></strong><?= analytics_info('Diese Auswertung berücksichtigt nur Reklamationen und Maßnahmen im aktuell erlaubten Standortbereich bzw. im ausgewählten Standortfilter.') ?></div>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-6 col-xl"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small analytics-label-with-info">Reklamationen<?= analytics_info('Gesamtzahl aller Reklamationen im aktuell ausgewählten Bereich bzw. Standortfilter.') ?></div><div class="display-6 fw-bold"><?= metric_number($claimSummary, 'total') ?></div></div></div></div>
    <div class="col-md-6 col-xl"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small analytics-label-with-info">Aktiv<?= analytics_info('Reklamationen mit Status In Bearbeitung, Wartend oder Überfällig. Also Fälle, die noch nicht abgeschlossen sind.') ?></div><div class="display-6 fw-bold"><?= metric_number($claimSummary, 'active_count') ?></div></div></div></div>
    <div class="col-md-6 col-xl"><div class="card kpi-card h-100 border-danger-subtle"><div class="card-body"><div class="text-muted small analytics-label-with-info">Überfällig<?= analytics_info('Reklamationen mit dem Status Überfällig. Diese Fälle brauchen besondere Aufmerksamkeit.') ?></div><div class="display-6 fw-bold text-danger"><?= metric_number($claimSummary, 'overdue_count') ?></div></div></div></div>
    <div class="col-md-6 col-xl"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small analytics-label-with-info">Geschlossen<?= analytics_info('Alle Reklamationen mit Status Geschlossen im ausgewählten Bereich.') ?></div><div class="display-6 fw-bold text-success"><?= metric_number($claimSummary, 'closed_count') ?></div></div></div></div>
    <div class="col-md-6 col-xl"><div class="card kpi-card h-100"><div class="card-body"><div class="text-muted small analytics-label-with-info">Ø Abschluss<?= analytics_info('Durchschnittliche Dauer in Tagen vom Erstellen bis zum Abschluss einer geschlossenen Reklamation.') ?></div><div class="display-6 fw-bold"><?= $avgClose['avg_days'] !== null ? number_format((float)$avgClose['avg_days'], 1, ',', '.') : '-' ?></div><div class="small text-muted">Tage</div></div></div></div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold"><span class="analytics-title-with-info">Maßnahmen-Ampel<?= analytics_info('Bewertet offene und laufende Maßnahmen nach Alter und Frist: Grün bis 5 Tage, Gelb 6 bis 10 Tage, Rot ab 11 Tagen oder bei überschrittener Frist.') ?></span></div>
            <div class="card-body">
                <?php $openActions = max(1, metric_number($actionSummary, 'open_actions')); ?>
                <?php foreach ([
                    ['green_actions','Grün','success','Offene oder laufende Maßnahmen, die maximal 5 Tage alt sind und deren Frist nicht überschritten ist.'],
                    ['yellow_actions','Gelb','warning','Offene oder laufende Maßnahmen von 6 bis 10 Tagen, solange keine Frist überschritten wurde.'],
                    ['red_actions','Rot','danger','Offene oder laufende Maßnahmen ab 11 Tagen oder mit überschrittener Frist.']
                ] as [$key, $label, $class, $hint]): ?>
                    <?php $value = metric_number($actionSummary, $key); $percent = (int)round($value / $openActions * 100); ?>
                    <div class="d-flex justify-content-between small mb-1"><span class="analytics-label-with-info"><?= e($label) ?><?= analytics_info($hint) ?></span><strong><?= $value ?></strong></div>
                    <div class="progress mb-3" style="height: 12px;"><div class="progress-bar bg-<?= e($class) ?>" style="width: <?= $percent ?>%"></div></div>
                <?php endforeach; ?>
                <div class="small text-muted">Basis: offene und in Bearbeitung befindliche Maßnahmen.</div>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold"><span class="analytics-title-with-info">Status der Reklamationen<?= analytics_info('Zeigt, wie viele Reklamationen je Status vorhanden sind, zum Beispiel Neu, In Bearbeitung, Überfällig oder Geschlossen.') ?></span></div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle">
                    <tbody>
                    <?php foreach ($statusRows as $row): ?>
                        <tr><td><?= status_badge((string)$row['status']) ?></td><td class="text-end fw-bold"><?= (int)$row['count_value'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$statusRows): ?><tr><td class="text-muted p-3">Keine Daten.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold"><span class="analytics-title-with-info">Prioritäten<?= analytics_info('Verteilung der Reklamationen nach Priorität: kritisch, hoch, mittel oder niedrig.') ?></span></div>
            <div class="card-body p-0">
                <table class="table mb-0 align-middle">
                    <tbody>
                    <?php foreach ($priorityRows as $row): ?>
                        <tr><td><?= e(priority_label((string)$row['priority'])) ?></td><td class="text-end fw-bold"><?= (int)$row['count_value'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$priorityRows): ?><tr><td class="text-muted p-3">Keine Daten.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <?php if ($locationRows): ?>
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header bg-white fw-bold"><span class="analytics-title-with-info">Standorte<?= analytics_info('Vergleich der Reklamationen nach Standort mit Gesamtzahl, offenen Fällen und überfälligen Fällen.') ?></span></div>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light"><tr><th>Standort</th><th class="text-end">Gesamt</th><th class="text-end">Offen</th><th class="text-end">Überfällig</th></tr></thead>
                        <tbody>
                        <?php foreach ($locationRows as $row): ?>
                            <tr>
                                <td><strong><?= e((string)$row['kuerzel']) ?></strong> · <?= e((string)$row['name']) ?></td>
                                <td class="text-end fw-bold"><?= (int)$row['claim_count'] ?></td>
                                <td class="text-end"><?= (int)$row['open_count'] ?></td>
                                <td class="text-end text-danger fw-bold"><?= (int)$row['overdue_count'] ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold"><span class="analytics-title-with-info">Top Partner / Kunden / Lieferanten<?= analytics_info('Zeigt die Partner mit den meisten Reklamationen. Offen bedeutet: nicht geschlossen, nicht abgelehnt und nicht archiviert.') ?></span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Partner</th><th class="text-end">Gesamt</th><th class="text-end">Offen</th></tr></thead>
                    <tbody>
                    <?php foreach ($partnerRows as $row): ?>
                        <tr><td><?= e((string)$row['partner_name']) ?></td><td class="text-end fw-bold"><?= (int)$row['count_value'] ?></td><td class="text-end"><?= (int)$row['open_count'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$partnerRows): ?><tr><td class="text-muted p-3">Keine Daten.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold"><span class="analytics-title-with-info">Top Artikelnummern<?= analytics_info('Artikelnummern mit den meisten Reklamationen. Leere Artikelnummern werden als ohne Artikelnummer zusammengefasst.') ?></span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Artikelnummer</th><th class="text-end">Anzahl</th></tr></thead>
                    <tbody>
                    <?php foreach ($articleRows as $row): ?>
                        <tr><td><?= e((string)$row['article_number']) ?></td><td class="text-end fw-bold"><?= (int)$row['count_value'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$articleRows): ?><tr><td class="text-muted p-3">Keine Daten.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold"><span class="analytics-title-with-info">Reklamationen nach Monat<?= analytics_info('Anzahl der Reklamationen pro Monat, basierend auf dem Reklamationsdatum. Es werden die letzten 12 Monate angezeigt.') ?></span></div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Monat</th><th class="text-end">Anzahl</th></tr></thead>
                    <tbody>
                    <?php foreach ($monthRows as $row): ?>
                        <tr><td><?= e((string)$row['month_key']) ?></td><td class="text-end fw-bold"><?= (int)$row['count_value'] ?></td></tr>
                    <?php endforeach; ?>
                    <?php if (!$monthRows): ?><tr><td class="text-muted p-3">Keine Daten.</td></tr><?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>


<?php if ($qmEnabled): ?>
<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold">
                <span class="analytics-title-with-info">Wiederholfehler & Maßnahmenwirksamkeit<?= analytics_info('Zeigt Fehlerbilder, die mehrfach auftreten. Wenn ein Fehler trotz Maßnahmen erneut vorkommt, sollte das Qualitätsmanagement Ursache und Maßnahme prüfen.') ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Fehlerbild</th>
                        <th>Prozessbereich</th>
                        <th class="text-end">Anzahl</th>
                        <th class="text-end">Offen</th>
                        <th>Bewertung</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($qmRepeatRows as $row): ?>
                        <?php
                            $countValue = (int)$row['count_value'];
                            $level = $countValue >= 4 ? 'red' : ($countValue >= 2 ? 'yellow' : 'green');
                        ?>
                        <tr>
                            <td>
                                <strong><?= e(qm_label(qm_error_pattern_options(), $row['error_pattern'] ?? '')) ?></strong>
                                <div class="small text-muted"><?= e(qm_label(qm_error_category_options(), $row['error_category'] ?? '')) ?> · letzter Fall: <?= e((string)$row['last_claim_date']) ?></div>
                            </td>
                            <td><?= e(qm_label(qm_process_area_options(), $row['process_area'] ?? '')) ?></td>
                            <td class="text-end fw-bold"><?= $countValue ?></td>
                            <td class="text-end"><?= (int)$row['open_count'] ?></td>
                            <td>
                                <?php if ($level === 'red'): ?>
                                    <span class="badge bg-danger">Maßnahme prüfen</span>
                                <?php elseif ($level === 'yellow'): ?>
                                    <span class="badge bg-warning text-dark">Beobachten</span>
                                <?php else: ?>
                                    <span class="badge bg-success">Unauffällig</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$qmRepeatRows): ?>
                        <tr><td colspan="5" class="text-center text-muted py-4">Keine Wiederholfehler-Muster erkannt.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold">
                <span class="analytics-title-with-info">Ursachenkategorien<?= analytics_info('Zeigt, welche Ursachenarten in Reklamationen am häufigsten dokumentiert wurden.') ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light"><tr><th>Ursache</th><th class="text-end">Anzahl</th></tr></thead>
                    <tbody>
                    <?php foreach ($qmRootCauseRows as $row): ?>
                        <tr>
                            <td><?= e(qm_label(qm_root_cause_category_options(), $row['root_cause_category'] ?? '')) ?></td>
                            <td class="text-end fw-bold"><?= (int)$row['count_value'] ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$qmRootCauseRows): ?>
                        <tr><td colspan="2" class="text-center text-muted py-4">Noch keine Ursachen klassifiziert.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php elseif (is_admin()): ?>
<div class="alert alert-warning">
    QM-Auswertung ist vorbereitet, aber die SQL-Migration wurde noch nicht ausgeführt.
</div>
<?php endif; ?>




<?php if ($qmEnabled && qm_ai_tables_enabled()): ?>
<div class="row g-4 mb-4">
    <div class="col-12">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <span class="analytics-title-with-info">
                    Offene KI-Light Bewertungen<?= analytics_info('Zeigt nur Reklamationen, die noch analysiert oder vom QM bewertet werden müssen. Sobald QM-Feedback gesetzt wurde, verschwindet der Eintrag hier und bleibt unten in der Feedback-Historie sichtbar.') ?>
                </span>
                <?php if (is_admin()): ?>
                    <a href="claim_ai_training_export.php" class="btn btn-sm btn-outline-primary">Trainingsdaten exportieren</a>
                <?php endif; ?>
            </div>

            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Reklamation</th>
                        <th>QM-Einordnung</th>
                        <th>Analyse</th>
                        <th>Aktion</th>
                        <th>QM-Feedback</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($qmAnalysisClaimRows as $row): ?>
                        <tr>
                            <td>
                                <a href="claim_view.php?id=<?= (int)$row['id'] ?>" class="fw-bold text-decoration-none">
                                    <?= e((string)$row['claim_number']) ?>
                                </a>
                                <div class="small text-muted">
                                    <?= e((string)$row['claim_date']) ?> · <?= status_badge((string)$row['status']) ?>
                                </div>
                                <div class="small text-muted"><?= e((string)$row['short_description']) ?></div>
                            </td>
                            <td>
                                <div><strong><?= e(qm_label(qm_error_pattern_options(), $row['error_pattern'] ?? '')) ?></strong></div>
                                <div class="small text-muted">
                                    <?= e(qm_label(qm_error_category_options(), $row['error_category'] ?? '')) ?>
                                    · <?= e(qm_label(qm_process_area_options(), $row['process_area'] ?? '')) ?>
                                </div>
                            </td>
                            <td>
                                <?php if (!empty($row['analysis_id'])): ?>
                                    <?= qm_ai_warning_badge($row['effectiveness_warning'] ?? null) ?>
                                    <div class="small text-muted mt-1">
                                        <?= e((string)($row['detected_error_pattern'] ?? '')) ?>
                                        <?php if (!empty($row['analysis_at'])): ?>
                                            · <?= e((string)$row['analysis_at']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Noch nicht analysiert</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <form method="post" action="claim_ai_analyze.php" class="m-0">
                                    <input type="hidden" name="claim_id" value="<?= (int)$row['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary">
                                        KI-Light Analyse starten
                                    </button>
                                </form>
                            </td>
                            <td>
                                <?php if (!empty($row['analysis_id']) && qm_feedback_enabled()): ?>
                                    <div class="d-flex flex-wrap gap-1">
                                        <form method="post" action="claim_ai_feedback.php" class="m-0">
                                            <input type="hidden" name="claim_id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="analysis_id" value="<?= (int)$row['analysis_id'] ?>">
                                            <button type="submit" name="feedback" value="analysis_correct" class="btn btn-sm btn-success">KI-Bewertung stimmt</button>
                                        </form>
                                        <form method="post" action="claim_ai_feedback.php" class="m-0">
                                            <input type="hidden" name="claim_id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="analysis_id" value="<?= (int)$row['analysis_id'] ?>">
                                            <button type="submit" name="feedback" value="analysis_wrong" class="btn btn-sm btn-outline-danger">KI-Bewertung falsch</button>
                                        </form>
                                        <form method="post" action="claim_ai_feedback.php" class="m-0">
                                            <input type="hidden" name="claim_id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="analysis_id" value="<?= (int)$row['analysis_id'] ?>">
                                            <button type="submit" name="feedback" value="repeat_confirmed" class="btn btn-sm btn-warning">Wiederholfehler bestätigen</button>
                                        </form>
                                        <form method="post" action="claim_ai_feedback.php" class="m-0">
                                            <input type="hidden" name="claim_id" value="<?= (int)$row['id'] ?>">
                                            <input type="hidden" name="analysis_id" value="<?= (int)$row['analysis_id'] ?>">
                                            <button type="submit" name="feedback" value="measure_not_effective" class="btn btn-sm btn-danger">Maßnahme muss geprüft werden</button>
                                        </form>
                                    </div>
                                <?php else: ?>
                                    <span class="text-muted small">Nach Analyse bewertbar</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$qmAnalysisClaimRows): ?>
                        <tr>
                            <td colspan="5" class="text-center text-muted py-4">
                                Keine offenen KI-Light Bewertungen vorhanden.
                            </td>
                        </tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <div class="card-footer bg-white small text-muted">
                Diese Liste zeigt nur offene KI-Light Bewertungen. Sobald QM-Feedback gespeichert wurde, gilt die Analyse als erledigt und erscheint nur noch in der Feedback-Historie.
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<?php if (qm_ai_tables_enabled()): ?>
<div class="row g-4 mb-4">
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold">
                <span class="analytics-title-with-info">KI-Light Maßnahmenprüfung<?= analytics_info('Zeigt Reklamationen, bei denen die lokale Analyse Wiederholfehler oder mögliche unwirksame Maßnahmen erkannt hat.') ?></span>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Reklamation</th>
                        <th>Bewertung</th>
                        <th>Erkanntes Muster</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($qmAiWarningRows as $row): ?>
                        <tr>
                            <td>
                                <a href="claim_view.php?id=<?= (int)$row['claim_id'] ?>" class="fw-bold text-decoration-none">
                                    <?= e((string)$row['claim_number']) ?>
                                </a>
                                <div class="small text-muted"><?= e((string)$row['claim_date']) ?> · <?= status_badge((string)$row['status']) ?></div>
                            </td>
                            <td><?= qm_ai_warning_badge($row['effectiveness_warning'] ?? null) ?></td>
                            <td>
                                <strong><?= e((string)($row['detected_error_pattern'] ?? '-')) ?></strong>
                                <div class="small text-muted"><?= e((string)($row['ai_summary'] ?? '')) ?></div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$qmAiWarningRows): ?>
                        <tr><td colspan="3" class="text-center text-muted py-4">Noch keine KI-Light Analysen vorhanden.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold">KI-Light Ampelübersicht</div>
            <div class="card-body">
                <?php if ($qmAiWarningCounts): ?>
                    <?php foreach ($qmAiWarningCounts as $row): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <div><?= qm_ai_warning_badge($row['effectiveness_warning'] ?? null) ?></div>
                            <div class="fw-bold"><?= (int)$row['count_value'] ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted">Noch keine Auswertung vorhanden.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>



<?php if (qm_feedback_enabled()): ?>
<div class="row g-4 mb-4">
    <div class="col-xl-8">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <span class="analytics-title-with-info">QM-Feedback zur KI-Light Bewertung<?= analytics_info('Zeigt, ob das Qualitätsmanagement die KI-Light Einschätzung bestätigt oder korrigiert hat. Diese Daten bilden später die Trainingsbasis für eine eigene KI.') ?></span>
                <?php if (is_admin()): ?>
                    <a href="claim_ai_training_export.php" class="btn btn-sm btn-outline-primary">Trainingsdaten exportieren</a>
                <?php endif; ?>
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                    <tr>
                        <th>Reklamation</th>
                        <th>Feedback</th>
                        <th>Notiz</th>
                        <th>Datum</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($qmFeedbackRows as $row): ?>
                        <tr>
                            <td>
                                <a href="claim_view.php?id=<?= (int)$row['claim_id'] ?>" class="fw-bold text-decoration-none">
                                    <?= e((string)$row['claim_number']) ?>
                                </a>
                                <div class="small text-muted"><?= e((string)$row['claim_date']) ?> · <?= status_badge((string)$row['status']) ?></div>
                            </td>
                            <td><?= qm_feedback_badge((string)$row['feedback_value']) ?></td>
                            <td class="small text-muted"><?= e((string)($row['note'] ?? '')) ?></td>
                            <td class="small text-muted"><?= e((string)$row['created_at']) ?></td>
                        </tr>
                    <?php endforeach; ?>

                    <?php if (!$qmFeedbackRows): ?>
                        <tr><td colspan="4" class="text-center text-muted py-4">Noch kein QM-Feedback vorhanden.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="col-xl-4">
        <div class="card h-100">
            <div class="card-header bg-white fw-bold">Feedback-Übersicht</div>
            <div class="card-body">
                <?php if ($qmFeedbackCounts): ?>
                    <?php foreach ($qmFeedbackCounts as $row): ?>
                        <div class="d-flex justify-content-between align-items-center border-bottom py-2">
                            <div><?= qm_feedback_badge((string)$row['feedback_value']) ?></div>
                            <div class="fw-bold"><?= (int)$row['count_value'] ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-muted">Noch keine Feedback-Daten vorhanden.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>


<?php require __DIR__ . '/footer.php'; ?>

