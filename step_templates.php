<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/step_template_helper.php';
require_admin();

$db = pdo();
$user = current_user();
$userId = (int)($user['id'] ?? 0);

function step_tpl_h(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function step_tpl_table_exists(PDO $db, string $table): bool
{
    if (function_exists('db_table_exists')) {
        return db_table_exists($table);
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function step_tpl_column_exists(PDO $db, string $table, string $column): bool
{
    if (function_exists('db_column_exists')) {
        return db_column_exists($table, $column);
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $stmt->execute([$table, $column]);
    return (int)$stmt->fetchColumn() > 0;
}

function step_tpl_table_ready(PDO $db): bool
{
    return eightd_step_templates_available($db);
}

function step_tpl_history_ready(PDO $db): bool
{
    return step_tpl_table_exists($db, 'step_template_history');
}

function step_tpl_seed_defaults(PDO $db, int $userId): void
{
    if (!step_tpl_table_ready($db)) {
        return;
    }

    $count = (int)$db->query('SELECT COUNT(*) FROM step_templates')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $stmt = $db->prepare("INSERT INTO step_templates
        (step_key, title, description, help_text, required_fields, sort_order, version_no, is_active, created_by, updated_by, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 1, 1, ?, ?, NOW(), NOW())");

    foreach (eightd_default_step_templates() as $stepKey => $tpl) {
        $stmt->execute([
            $stepKey,
            $tpl['title'],
            $tpl['description'],
            $tpl['help_text'],
            $tpl['required_fields'],
            (int)$tpl['sort_order'],
            $userId ?: null,
            $userId ?: null,
        ]);
    }
}

function step_tpl_active_version(PDO $db): int
{
    if (!step_tpl_table_ready($db)) {
        return 0;
    }

    return (int)$db->query('SELECT COALESCE(MAX(version_no), 0) FROM step_templates WHERE is_active = 1')->fetchColumn();
}

function step_tpl_all_versions(PDO $db): array
{
    if (!step_tpl_table_ready($db)) {
        return [];
    }

    $stmt = $db->query("
        SELECT version_no,
               MAX(is_active) AS is_active,
               MIN(created_at) AS created_at,
               MAX(updated_at) AS updated_at
        FROM step_templates
        GROUP BY version_no
        ORDER BY version_no DESC
    ");
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function step_tpl_load_version(PDO $db, int $version): array
{
    if (!step_tpl_table_ready($db) || $version <= 0) {
        return [];
    }

    $stmt = $db->prepare('SELECT * FROM step_templates WHERE version_no = ? ORDER BY sort_order ASC, step_key ASC');
    $stmt->execute([$version]);

    $templates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $templates[(string)$row['step_key']] = $row;
    }

    return $templates;
}

function step_tpl_normalize(?string $value): string
{
    $value = str_replace(["\r\n", "\r"], "\n", (string)$value);
    return trim($value);
}

function step_tpl_field_labels(): array
{
    return [
        'title' => 'Titel',
        'description' => 'Beschreibung',
        'help_text' => 'Hilfetext',
        'required_fields' => 'Pflichtangaben / Checkliste',
    ];
}

function step_tpl_user_label(PDO $db, ?int $userId): string
{
    if (!$userId) {
        return 'System / unbekannt';
    }

    if (!step_tpl_table_exists($db, 'users')) {
        return 'Benutzer #' . $userId;
    }

    $preferred = ['username', 'name', 'full_name', 'display_name', 'email'];
    $parts = [];

    foreach ($preferred as $col) {
        if (step_tpl_column_exists($db, 'users', $col)) {
            $parts[] = "NULLIF({$col}, '')";
        }
    }

    if (!$parts) {
        return 'Benutzer #' . $userId;
    }

    $sql = 'SELECT COALESCE(' . implode(', ', $parts) . ') AS label FROM users WHERE id = ? LIMIT 1';
    $stmt = $db->prepare($sql);
    $stmt->execute([$userId]);
    $label = trim((string)($stmt->fetchColumn() ?: ''));

    return $label !== '' ? $label : ('Benutzer #' . $userId);
}

function step_tpl_load_history(PDO $db, int $limit = 200): array
{
    if (!step_tpl_history_ready($db)) {
        return [];
    }

    $limit = max(10, min(500, $limit));

    $stmt = $db->query("
        SELECT *
        FROM step_template_history
        ORDER BY created_at DESC, id DESC
        LIMIT {$limit}
    ");

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function step_tpl_version_change_counts(PDO $db): array
{
    if (!step_tpl_history_ready($db)) {
        return [];
    }

    $stmt = $db->query("
        SELECT version_no, COUNT(*) AS cnt
        FROM step_template_history
        WHERE action = 'field_changed'
        GROUP BY version_no
    ");

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $out[(int)$row['version_no']] = (int)$row['cnt'];
    }

    return $out;
}

$tableReady = step_tpl_table_ready($db);
$historyReady = step_tpl_history_ready($db);
$error = '';

if ($tableReady) {
    step_tpl_seed_defaults($db, $userId);
} else {
    $error = 'Die Tabelle step_templates wurde nicht gefunden. Bitte zuerst die DB-Migration ausführen.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!$tableReady) {
        flash('danger', 'Tabelle step_templates fehlt. Speichern nicht möglich.');
        redirect('step_templates.php');
    }

    if (!$historyReady) {
        flash('danger', 'Tabelle step_template_history fehlt. Bitte zuerst die neue Migration ausführen, damit Änderungen audit-sicher protokolliert werden.');
        redirect('step_templates.php');
    }

    try {
        $baseVersion = max(1, (int)($_POST['base_version'] ?? step_tpl_active_version($db)));
        $baseTemplates = step_tpl_load_version($db, $baseVersion);
        if (!$baseTemplates) {
            $baseVersion = step_tpl_active_version($db);
            $baseTemplates = step_tpl_load_version($db, $baseVersion);
        }

        $newTemplates = [];
        $changes = [];
        $fieldLabels = step_tpl_field_labels();

        foreach (eightd_default_step_templates() as $stepKey => $default) {
            $newTemplates[$stepKey] = [
                'title' => trim((string)($_POST['title'][$stepKey] ?? $default['title'])),
                'description' => step_tpl_normalize((string)($_POST['description'][$stepKey] ?? $default['description'])),
                'help_text' => step_tpl_normalize((string)($_POST['help_text'][$stepKey] ?? $default['help_text'])),
                'required_fields' => step_tpl_normalize((string)($_POST['required_fields'][$stepKey] ?? $default['required_fields'])),
                'sort_order' => (int)$default['sort_order'],
            ];

            if ($newTemplates[$stepKey]['title'] === '') {
                throw new RuntimeException($stepKey . ': Titel darf nicht leer sein.');
            }

            $old = $baseTemplates[$stepKey] ?? $default;

            foreach ($fieldLabels as $field => $label) {
                $oldValue = step_tpl_normalize((string)($old[$field] ?? ''));
                $newValue = step_tpl_normalize((string)($newTemplates[$stepKey][$field] ?? ''));

                if ($oldValue !== $newValue) {
                    $changes[] = [
                        'step_key' => $stepKey,
                        'field_name' => $field,
                        'old_value' => $oldValue,
                        'new_value' => $newValue,
                    ];
                }
            }
        }

        if (!$changes) {
            flash('info', 'Keine Änderungen erkannt. Es wurde keine neue Version angelegt.');
            redirect('step_templates.php?version=' . $baseVersion);
        }

        $db->beginTransaction();

        $nextVersion = ((int)$db->query('SELECT COALESCE(MAX(version_no), 0) FROM step_templates')->fetchColumn()) + 1;

        $deactivate = $db->prepare('UPDATE step_templates SET is_active = 0, updated_by = ?, updated_at = NOW() WHERE is_active = 1');
        $deactivate->execute([$userId ?: null]);

        $insert = $db->prepare("INSERT INTO step_templates
            (step_key, title, description, help_text, required_fields, sort_order, version_no, is_active, created_by, updated_by, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?, ?, NOW(), NOW())");

        foreach ($newTemplates as $stepKey => $tpl) {
            $insert->execute([
                $stepKey,
                $tpl['title'],
                $tpl['description'],
                $tpl['help_text'],
                $tpl['required_fields'],
                (int)$tpl['sort_order'],
                $nextVersion,
                $userId ?: null,
                $userId ?: null,
            ]);
        }

        $historyInsert = $db->prepare("
            INSERT INTO step_template_history
                (version_no, base_version_no, step_key, field_name, old_value, new_value, action, changed_by, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, 'field_changed', ?, NOW())
        ");

        foreach ($changes as $change) {
            $historyInsert->execute([
                $nextVersion,
                $baseVersion,
                $change['step_key'],
                $change['field_name'],
                $change['old_value'],
                $change['new_value'],
                $userId ?: null,
            ]);
        }

        $summaryInsert = $db->prepare("
            INSERT INTO step_template_history
                (version_no, base_version_no, step_key, field_name, old_value, new_value, action, changed_by, created_at)
            VALUES
                (?, ?, NULL, NULL, NULL, ?, 'version_created', ?, NOW())
        ");
        $summaryInsert->execute([
            $nextVersion,
            $baseVersion,
            'Neue aktive Version ' . $nextVersion . ' aus Version ' . $baseVersion . ' erstellt. Änderungen: ' . count($changes),
            $userId ?: null,
        ]);

        $db->commit();

        flash('success', 'Neue aktive 8D-Vorlage Version ' . $nextVersion . ' wurde gespeichert. ' . count($changes) . ' Änderung(en) wurden protokolliert.');
        redirect('step_templates.php?version=' . $nextVersion);
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        flash('danger', APP_DEBUG ? $e->getMessage() : '8D-Vorlage konnte nicht gespeichert werden.');
        redirect('step_templates.php');
    }
}

$activeVersion = $tableReady ? step_tpl_active_version($db) : 0;
$viewVersion = isset($_GET['version']) ? max(1, (int)$_GET['version']) : $activeVersion;
if ($viewVersion <= 0) {
    $viewVersion = 1;
}

$templates = $tableReady ? step_tpl_load_version($db, $viewVersion) : [];
$versions = $tableReady ? step_tpl_all_versions($db) : [];
$isActiveView = $viewVersion === $activeVersion;
$changeCounts = $historyReady ? step_tpl_version_change_counts($db) : [];
$historyRows = $historyReady ? step_tpl_load_history($db, 200) : [];
$fieldLabels = step_tpl_field_labels();

require __DIR__ . '/header.php';
?>
<div class="card page-hero step-templates-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">Administration · 8D-Vorlagen</div>
                <h1 class="page-title display-6 fw-bold mb-2">8D-Vorlagen</h1>
                <div class="page-subtitle">
                    D1 bis D8 zentral verwalten, versionieren und Änderungen nachvollziehbar protokollieren.
                    Neue Reklamationen verwenden automatisch die aktive Version.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a href="claims.php" class="btn btn-outline-secondary">Zurück</a>
                    <a href="step_template_compare.php" class="btn btn-outline-primary">Version vergleichen</a>
                    <a href="claim_create.php" class="btn btn-primary">Neue Reklamation testen</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($error): ?>
    <div class="card step-template-warning-card mb-4">
        <div class="card-body d-flex gap-3 align-items-start">
            <div class="step-template-warning-icon">!</div>
            <div>
                <div class="fw-bold mb-1">Migration erforderlich</div>
                <div class="text-muted"><?= step_tpl_h($error) ?></div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($tableReady && !$historyReady): ?>
    <div class="card step-template-warning-card mb-4">
        <div class="card-body d-flex gap-3 align-items-start">
            <div class="step-template-warning-icon">!</div>
            <div>
                <div class="fw-bold mb-1">Historientabelle fehlt</div>
                <div class="text-muted">
                    Die Vorlagentabelle ist vorhanden, aber die Historientabelle <code>step_template_history</code> fehlt noch.
                    Bitte einmal <code>run_step_templates_migration.php</code> als Admin aufrufen.
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($tableReady): ?>
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card step-template-stat-card h-100">
                <div class="card-body">
                    <div class="text-muted small">Aktive Vorlage</div>
                    <div class="display-6 fw-bold">V<?= (int)$activeVersion ?></div>
                    <div class="text-muted small">Neue Reklamationen nutzen diese Version.</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card step-template-stat-card h-100">
                <div class="card-body">
                    <div class="text-muted small">Angezeigte Version</div>
                    <div class="display-6 fw-bold">V<?= (int)$viewVersion ?></div>
                    <div class="text-muted small"><?= $isActiveView ? 'Diese Version ist aktiv.' : 'Historische Version zur Ansicht.' ?></div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card step-template-stat-card h-100">
                <div class="card-body">
                    <div class="text-muted small">Versionen gesamt</div>
                    <div class="display-6 fw-bold"><?= count($versions) ?></div>
                    <div class="text-muted small">Jede Änderung erzeugt eine neue Version.</div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card step-template-stat-card h-100">
                <div class="card-body">
                    <div class="text-muted small">Historie</div>
                    <div class="display-6 fw-bold"><?= count($historyRows) ?></div>
                    <div class="text-muted small">Letzte protokollierte Änderungen.</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card step-template-version-card mb-4">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="fw-semibold">Versionsauswahl</div>
                <div class="text-muted small">Alte Reklamationen bleiben unverändert. Nur neue Reklamationen nutzen die aktive Vorlage.</div>
            </div>
            <form method="get" class="d-flex gap-2 align-items-center">
                <select name="version" class="form-select form-select-sm" onchange="this.form.submit()">
                    <?php foreach ($versions as $version): ?>
                        <?php
                            $v = (int)$version['version_no'];
                            $cnt = $changeCounts[$v] ?? 0;
                        ?>
                        <option value="<?= $v ?>" <?= $v === $viewVersion ? 'selected' : '' ?>>
                            Version <?= $v ?><?= (int)$version['is_active'] === 1 ? ' · aktiv' : '' ?><?= $cnt > 0 ? ' · ' . $cnt . ' Änderung(en)' : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <noscript><button class="btn btn-sm btn-outline-primary">Öffnen</button></noscript>
            </form>
        </div>
    </div>

    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="base_version" value="<?= (int)$viewVersion ?>">

        <?php if (!$isActiveView): ?>
            <div class="alert alert-warning step-template-notice">
                Du siehst gerade eine alte Version. Beim Speichern wird eine neue aktive Version aus den angezeigten Werten erstellt.
                Die Historie vergleicht dann gegen Version <?= (int)$viewVersion ?>.
            </div>
        <?php else: ?>
            <div class="alert alert-info step-template-notice">
                Änderungen werden als neue aktive Version gespeichert. Dabei wird protokolliert, wer was geändert hat.
            </div>
        <?php endif; ?>

        <div class="accordion step-template-accordion" id="templateAccordion">
            <?php foreach (eightd_default_step_templates() as $stepKey => $default): ?>
                <?php
                    $tpl = $templates[$stepKey] ?? $default;
                    $collapseId = 'tpl_' . strtolower($stepKey);
                ?>
                <div class="accordion-item mb-2 border rounded overflow-hidden">
                    <h2 class="accordion-header">
                        <button class="accordion-button <?= $stepKey === 'D1' ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#<?= step_tpl_h($collapseId) ?>">
                            <span class="badge bg-primary me-2"><?= step_tpl_h($stepKey) ?></span>
                            <strong><?= step_tpl_h((string)($tpl['title'] ?? $default['title'])) ?></strong>
                        </button>
                    </h2>
                    <div id="<?= step_tpl_h($collapseId) ?>" class="accordion-collapse collapse <?= $stepKey === 'D1' ? 'show' : '' ?>" data-bs-parent="#templateAccordion">
                        <div class="accordion-body">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Titel</label>
                                    <input class="form-control" name="title[<?= step_tpl_h($stepKey) ?>]" value="<?= step_tpl_h((string)($tpl['title'] ?? '')) ?>" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Pflichtangaben / Checkliste</label>
                                    <textarea class="form-control" name="required_fields[<?= step_tpl_h($stepKey) ?>]" rows="2"><?= step_tpl_h((string)($tpl['required_fields'] ?? '')) ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Beschreibung</label>
                                    <textarea class="form-control" name="description[<?= step_tpl_h($stepKey) ?>]" rows="4"><?= step_tpl_h((string)($tpl['description'] ?? '')) ?></textarea>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label fw-semibold">Hilfetext für Bearbeiter</label>
                                    <textarea class="form-control" name="help_text[<?= step_tpl_h($stepKey) ?>]" rows="4"><?= step_tpl_h((string)($tpl['help_text'] ?? '')) ?></textarea>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div class="d-flex flex-wrap justify-content-end gap-2 mt-4 mb-5 step-template-actions">
            <a href="claims.php" class="btn btn-outline-secondary">Abbrechen</a>
            <button class="btn btn-primary" type="submit" data-confirm="Als neue aktive 8D-Vorlagenversion speichern und Änderungen protokollieren?">
                Neue Version speichern
            </button>
        </div>
    </form>

    <div class="card step-template-history-card mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <h2 class="h5 mb-0">Änderungshistorie</h2>
                <div class="text-muted small">Zeigt, wer welche Vorlage geändert hat und was geändert wurde.</div>
            </div>
            <span class="badge bg-secondary"><?= count($historyRows) ?> Einträge</span>
        </div>
        <div class="card-body p-0">
            <?php if (!$historyReady): ?>
                <div class="p-3 text-muted">Historientabelle fehlt noch.</div>
            <?php elseif (!$historyRows): ?>
                <div class="p-3 text-muted">Noch keine Änderungen protokolliert.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle mb-0">
                        <thead class="table-light">
                        <tr>
                            <th>Version</th>
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
                                $who = step_tpl_user_label($db, isset($row['changed_by']) ? (int)$row['changed_by'] : null);
                                $versionNo = (int)($row['version_no'] ?? 0);
                                $baseVersionNo = (int)($row['base_version_no'] ?? 0);
                            ?>
                            <tr>
                                <td>
                                    <span class="badge bg-primary">V<?= $versionNo ?></span>
                                    <?php if ($baseVersionNo > 0): ?>
                                        <div class="small text-muted">aus V<?= $baseVersionNo ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($stepKey): ?>
                                        <span class="badge bg-dark"><?= step_tpl_h($stepKey) ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Version</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?= step_tpl_h($fieldLabels[$field] ?? ($field ?: 'Zusammenfassung')) ?>
                                </td>
                                <td>
                                    <?php if ($action === 'version_created'): ?>
                                        <div class="fw-semibold"><?= step_tpl_h($newValue) ?></div>
                                    <?php else: ?>
                                        <details>
                                            <summary class="fw-semibold">Änderung anzeigen</summary>
                                            <div class="row g-2 mt-2">
                                                <div class="col-md-6">
                                                    <div class="small text-muted mb-1">Vorher</div>
                                                    <pre class="step-template-diff-pre"><?= step_tpl_h($oldValue) ?></pre>
                                                </div>
                                                <div class="col-md-6">
                                                    <div class="small text-muted mb-1">Nachher</div>
                                                    <pre class="step-template-diff-pre"><?= step_tpl_h($newValue) ?></pre>
                                                </div>
                                            </div>
                                        </details>
                                    <?php endif; ?>
                                </td>
                                <td><?= step_tpl_h($who) ?></td>
                                <td><?= step_tpl_h((string)($row['created_at'] ?? '')) ?></td>
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
