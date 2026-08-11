<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/step_template_helper.php';
require_admin();

$db = pdo();

function tpl_cmp_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function tpl_cmp_table_exists(PDO $db, string $table): bool
{
    if (function_exists('db_table_exists')) {
        return db_table_exists($table);
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function tpl_cmp_user_label(PDO $db, ?int $userId): string
{
    if (!$userId || !tpl_cmp_table_exists($db, 'users')) {
        return $userId ? ('Benutzer #' . $userId) : 'System / unbekannt';
    }

    $columns = [];
    foreach (['username', 'name', 'full_name', 'display_name', 'email'] as $col) {
        $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = ?");
        $stmt->execute([$col]);
        if ((int)$stmt->fetchColumn() > 0) {
            $columns[] = "NULLIF({$col}, '')";
        }
    }

    if (!$columns) {
        return 'Benutzer #' . $userId;
    }

    $stmt = $db->prepare('SELECT COALESCE(' . implode(', ', $columns) . ') AS label FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $label = trim((string)($stmt->fetchColumn() ?: ''));

    return $label !== '' ? $label : ('Benutzer #' . $userId);
}

function tpl_cmp_versions(PDO $db): array
{
    if (!eightd_step_templates_available($db)) {
        return [];
    }

    $stmt = $db->query("\n        SELECT version_no,\n               MAX(is_active) AS is_active,\n               MIN(created_at) AS created_at,\n               MAX(updated_at) AS updated_at\n        FROM step_templates\n        GROUP BY version_no\n        ORDER BY version_no DESC\n    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function tpl_cmp_active_version(PDO $db): int
{
    if (!eightd_step_templates_available($db)) {
        return 0;
    }

    return (int)$db->query("SELECT COALESCE(MAX(version_no), 0) FROM step_templates WHERE is_active = 1")->fetchColumn();
}

function tpl_cmp_load_templates(PDO $db, int $version): array
{
    if (!eightd_step_templates_available($db) || $version <= 0) {
        return [];
    }

    $stmt = $db->prepare("SELECT * FROM step_templates WHERE version_no = ? ORDER BY sort_order ASC, step_key ASC");
    $stmt->execute([$version]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(string)$row['step_key']] = $row;
    }
    return $out;
}

function tpl_cmp_normalize(?string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", (string)$value);
    return trim($value);
}

function tpl_cmp_field_labels(): array
{
    return [
        'title' => 'Titel',
        'description' => 'Beschreibung',
        'help_text' => 'Hilfetext',
        'required_fields' => 'Pflichtangaben / Checkliste',
    ];
}

function tpl_cmp_history_for_version(PDO $db, int $toVersion): array
{
    if (!tpl_cmp_table_exists($db, 'step_template_history') || $toVersion <= 0) {
        return [];
    }

    $stmt = $db->prepare("\n        SELECT *\n        FROM step_template_history\n        WHERE version_no = ?\n        ORDER BY created_at DESC, id DESC\n    ");
    $stmt->execute([$toVersion]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$versions = tpl_cmp_versions($db);
$activeVersion = tpl_cmp_active_version($db);
$availableVersionNos = array_map(static fn(array $row): int => (int)$row['version_no'], $versions);

$fromVersion = isset($_GET['from_version']) ? (int)$_GET['from_version'] : max(1, $activeVersion - 1);
$toVersion = isset($_GET['to_version']) ? (int)$_GET['to_version'] : $activeVersion;

if ($availableVersionNos) {
    if (!in_array($fromVersion, $availableVersionNos, true)) {
        $fromVersion = $availableVersionNos[min(1, count($availableVersionNos) - 1)] ?? $availableVersionNos[0];
    }
    if (!in_array($toVersion, $availableVersionNos, true)) {
        $toVersion = $activeVersion ?: $availableVersionNos[0];
    }
}

$leftTemplates = tpl_cmp_load_templates($db, $fromVersion);
$rightTemplates = tpl_cmp_load_templates($db, $toVersion);
$fieldLabels = tpl_cmp_field_labels();
$defaults = eightd_default_step_templates();
$historyRows = tpl_cmp_history_for_version($db, $toVersion);

$totalChanges = 0;
$stepChanges = [];
foreach ($defaults as $stepKey => $default) {
    $stepChanges[$stepKey] = 0;
    $left = $leftTemplates[$stepKey] ?? $default;
    $right = $rightTemplates[$stepKey] ?? $default;

    foreach ($fieldLabels as $field => $label) {
        if (tpl_cmp_normalize((string)($left[$field] ?? '')) !== tpl_cmp_normalize((string)($right[$field] ?? ''))) {
            $totalChanges++;
            $stepChanges[$stepKey]++;
        }
    }
}

require __DIR__ . '/header.php';
?>
<div class="card page-hero step-compare-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">Administration · Versionsvergleich</div>
                <h1 class="page-title display-6 fw-bold mb-2">8D-Versionen vergleichen</h1>
                <div class="page-subtitle">
                    Vergleiche zwei Vorlagenversionen komplett nebeneinander und sieh sofort, welche D-Schritte und Felder verändert wurden.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a href="step_templates.php" class="btn btn-outline-secondary">Zur Vorlagenverwaltung</a>
                    <a href="step_templates.php?version=<?= (int)$toVersion ?>" class="btn btn-primary">Zielversion bearbeiten</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!$versions): ?>
    <div class="card step-compare-warning-card mb-4">
        <div class="card-body d-flex gap-3 align-items-start">
            <div class="step-compare-warning-icon">!</div>
            <div>
                <div class="fw-bold mb-1">Noch keine Versionen vorhanden</div>
                <div class="text-muted">Es wurden noch keine 8D-Vorlagenversionen gefunden. Bitte zuerst die Vorlagenverwaltung öffnen und eine Version anlegen.</div>
            </div>
        </div>
    </div>
<?php else: ?>
    <div class="card step-compare-filter-card mb-4">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Ausgangsversion</label>
                    <select name="from_version" class="form-select">
                        <?php foreach ($versions as $version): ?>
                            <?php $v = (int)$version['version_no']; ?>
                            <option value="<?= $v ?>" <?= $v === $fromVersion ? 'selected' : '' ?>>
                                Version <?= $v ?><?= (int)$version['is_active'] === 1 ? ' · aktiv' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Zielversion</label>
                    <select name="to_version" class="form-select">
                        <?php foreach ($versions as $version): ?>
                            <?php $v = (int)$version['version_no']; ?>
                            <option value="<?= $v ?>" <?= $v === $toVersion ? 'selected' : '' ?>>
                                Version <?= $v ?><?= (int)$version['is_active'] === 1 ? ' · aktiv' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4 d-flex flex-wrap gap-2">
                    <button class="btn btn-primary" type="submit">Vergleichen</button>
                    <a class="btn btn-outline-secondary" href="step_template_compare.php?from_version=<?= (int)$toVersion ?>&to_version=<?= (int)$fromVersion ?>">Tauschen</a>
                    <?php if ($activeVersion > 1): ?>
                        <a class="btn btn-outline-primary" href="step_template_compare.php?from_version=<?= (int)($activeVersion - 1) ?>&to_version=<?= (int)$activeVersion ?>">Letzte Änderung</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card step-compare-stat-card">
                <div class="card-body">
                    <div class="text-muted small">Ausgang</div>
                    <div class="display-6 fw-bold">V<?= (int)$fromVersion ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card step-compare-stat-card">
                <div class="card-body">
                    <div class="text-muted small">Ziel</div>
                    <div class="display-6 fw-bold">V<?= (int)$toVersion ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card step-compare-stat-card">
                <div class="card-body">
                    <div class="text-muted small">Gefundene Änderungen</div>
                    <div class="display-6 fw-bold"><?= (int)$totalChanges ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card step-compare-stat-card">
                <div class="card-body">
                    <div class="text-muted small">Aktive Version</div>
                    <div class="display-6 fw-bold">V<?= (int)$activeVersion ?></div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($fromVersion === $toVersion): ?>
        <div class="alert alert-info step-compare-notice">Du vergleichst dieselbe Version. Deshalb gibt es keine Unterschiede.</div>
    <?php endif; ?>

    <div class="accordion step-compare-accordion mb-4" id="compareAccordion">
        <?php foreach ($defaults as $stepKey => $default): ?>
            <?php
                $left = $leftTemplates[$stepKey] ?? $default;
                $right = $rightTemplates[$stepKey] ?? $default;
                $collapseId = 'cmp_' . strtolower($stepKey);
                $changedInStep = (int)($stepChanges[$stepKey] ?? 0);
            ?>
            <div class="accordion-item mb-2 border rounded overflow-hidden">
                <h2 class="accordion-header">
                    <button class="accordion-button <?= $changedInStep > 0 ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= tpl_cmp_h($collapseId) ?>">
                        <span class="badge bg-dark me-2"><?= tpl_cmp_h($stepKey) ?></span>
                        <strong class="me-2"><?= tpl_cmp_h((string)($right['title'] ?? $default['title'])) ?></strong>
                        <?php if ($changedInStep > 0): ?>
                            <span class="badge bg-warning text-dark"><?= $changedInStep ?> Änderung<?= $changedInStep === 1 ? '' : 'en' ?></span>
                        <?php else: ?>
                            <span class="badge bg-success">unverändert</span>
                        <?php endif; ?>
                    </button>
                </h2>
                <div id="<?= tpl_cmp_h($collapseId) ?>" class="accordion-collapse collapse <?= $changedInStep > 0 ? 'show' : '' ?>" data-bs-parent="#compareAccordion">
                    <div class="accordion-body">
                        <?php foreach ($fieldLabels as $field => $label): ?>
                            <?php
                                $leftValue = tpl_cmp_normalize((string)($left[$field] ?? ''));
                                $rightValue = tpl_cmp_normalize((string)($right[$field] ?? ''));
                                $changed = $leftValue !== $rightValue;
                            ?>
                            <div class="mb-3 p-3 step-compare-field <?= $changed ? 'is-changed' : 'is-equal' ?>">
                                <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                    <div class="fw-semibold"><?= tpl_cmp_h($label) ?></div>
                                    <?php if ($changed): ?>
                                        <span class="badge bg-warning text-dark">geändert</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">gleich</span>
                                    <?php endif; ?>
                                </div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="small text-muted mb-1">Version <?= (int)$fromVersion ?></div>
                                        <pre class="step-compare-pre"><?= tpl_cmp_h($leftValue) ?></pre>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-muted mb-1">Version <?= (int)$toVersion ?></div>
                                        <pre class="step-compare-pre"><?= tpl_cmp_h($rightValue) ?></pre>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="card step-compare-history-card mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="h5 mb-0">Historie zur Zielversion V<?= (int)$toVersion ?></h2>
                <div class="text-muted small">Diese Einträge wurden beim Erstellen der Zielversion protokolliert.</div>
            </div>
            <span class="badge bg-secondary"><?= count($historyRows) ?> Einträge</span>
        </div>
        <div class="card-body p-0">
            <?php if (!$historyRows): ?>
                <div class="p-3 text-muted">Keine Historie zur Zielversion gefunden.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Punkt</th>
                            <th>Feld</th>
                            <th>Änderung</th>
                            <th>Wer</th>
                            <th>Wann</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($historyRows as $row): ?>
                            <?php
                                $action = (string)($row['action'] ?? 'field_changed');
                                $stepKey = (string)($row['step_key'] ?? '');
                                $field = (string)($row['field_name'] ?? '');
                                $oldValue = (string)($row['old_value'] ?? '');
                                $newValue = (string)($row['new_value'] ?? '');
                                $who = tpl_cmp_user_label($db, isset($row['changed_by']) ? (int)$row['changed_by'] : null);
                            ?>
                            <tr>
                                <td>
                                    <?php if ($stepKey): ?>
                                        <span class="badge bg-dark"><?= tpl_cmp_h($stepKey) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Version</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= tpl_cmp_h($fieldLabels[$field] ?? ($field ?: 'Zusammenfassung')) ?></td>
                                <td>
                                    <?php if ($action === 'version_created'): ?>
                                        <span class="fw-semibold"><?= tpl_cmp_h($newValue) ?></span>
                                    <?php else: ?>
                                        <details>
                                            <summary class="fw-semibold">Vorher/Nachher anzeigen</summary>
                                            <div class="row g-2 mt-2">
                                                <div class="col-md-6">
                                                    <div class="small text-muted mb-1">Vorher</div>
                                                    <pre class="step-compare-pre"><?= tpl_cmp_h($oldValue) ?></pre>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="small text-muted mb-1">Nachher</div>
                                                    <pre class="step-compare-pre"><?= tpl_cmp_h($newValue) ?></pre>
                                                </div>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><?= tpl_cmp_h($who) ?></td>
                                <td><?= tpl_cmp_h((string)($row['created_at'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
