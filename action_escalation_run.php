<?php
declare(strict_types=1);

/**
 * Ampel-Eskalation ausführen / testen.
 *
 * Manuell:
 *   /action_escalation_run.php
 *
 * Cron/URL:
 *   /action_escalation_run.php?token=DEIN_TOKEN
 *
 * Token:
 *   Entweder in config.php definieren:
 *     const ACTION_ESCALATION_TOKEN = 'langer-zufalls-token';
 *   oder unten als Fallback eintragen.
 */

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/claim_group_helper.php';
require_once __DIR__ . '/action_escalation_helper.php';

if (!defined('ACTION_ESCALATION_TOKEN')) {
    define('ACTION_ESCALATION_TOKEN', 'HIER_LANGEN_TOKEN_EINTRAGEN');
}

$token = (string)($_GET['token'] ?? '');
$tokenMode = ACTION_ESCALATION_TOKEN !== ''
    && ACTION_ESCALATION_TOKEN !== 'HIER_LANGEN_TOKEN_EINTRAGEN'
    && hash_equals((string)ACTION_ESCALATION_TOKEN, $token);

if ($tokenMode) {
    header('Content-Type: application/json; charset=utf-8');
    $result = run_action_escalation_check(false);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

require_admin();

$run = ($_POST['run'] ?? '') === '1';
$dryRun = isset($_POST['dry_run']);
$result = null;

if ($run) {
    require_csrf();
    $result = run_action_escalation_check($dryRun);
}

require __DIR__ . '/header.php';
?>

<div class="card page-hero escalation-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">Administration · Eskalation</div>
                <h1 class="page-title display-6 fw-bold mb-2">Ampel-Eskalation</h1>
                <div class="page-subtitle">
                    Automatische E-Mail an Management und Geschäftsleitung bei gelben und roten Maßnahmen.
                    Hier kannst du die Prüfung manuell testen oder für den Cronjob vorbereiten.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a href="groups.php" class="btn btn-outline-secondary">Gruppen verwalten</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if (!action_escalation_ready()): ?>
    <div class="card escalation-warning-card mb-4">
        <div class="card-body d-flex gap-3 align-items-start">
            <div class="escalation-warning-icon">!</div>
            <div>
                <div class="fw-bold mb-1">Migration erforderlich</div>
                <div class="text-muted">
                    Die Datenbankstruktur für die Ampel-Eskalation ist noch nicht vollständig.
                    Bitte einmal die Migration ausführen:
                    <a href="run_action_escalation_migration.php" class="fw-bold">run_action_escalation_migration.php</a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="row g-3 mb-4">
    <div class="col-md-4">
        <div class="card escalation-level-card green">
            <div class="card-body">
                <div class="escalation-level-icon">G</div>
                <h2 class="h5">Grün</h2>
                <p class="mb-0 text-muted">
                    Tag 0–5. Die ausgewählten Gruppenmitglieder erhalten beim Erstellen der Reklamation ihre Info/Aufgabe.
                </p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card escalation-level-card yellow">
            <div class="card-body">
                <div class="escalation-level-icon">G</div>
                <h2 class="h5 text-warning-emphasis">Gelb</h2>
                <p class="mb-0 text-muted">
                    Tag 6–10. Gruppen mit <strong>Gelb-Eskalation</strong>, z. B. Management, werden per E-Mail informiert.
                </p>
            </div>
        </div>
    </div>

    <div class="col-md-4">
        <div class="card escalation-level-card red">
            <div class="card-body">
                <div class="escalation-level-icon">R</div>
                <h2 class="h5 text-danger">Rot</h2>
                <p class="mb-0 text-muted">
                    Ab Tag 11 oder bei überschrittener Frist. Gruppen mit Gelb- und Rot-Eskalation werden informiert.
                </p>
            </div>
        </div>
    </div>
</div>

<div class="card escalation-run-card mb-4">
    <div class="card-body">
        <h2 class="h5 mb-3">Prüfung ausführen</h2>
        <form method="post" class="d-flex flex-wrap gap-2 align-items-center">
            <?= csrf_field() ?>
            <input type="hidden" name="run" value="1">

            <label class="form-check mb-0">
                <input class="form-check-input" type="checkbox" name="dry_run" value="1" checked>
                <span class="form-check-label">Nur testen, keine E-Mails senden</span>
            </label>

            <button class="btn btn-primary" data-confirm="Ampel-Eskalation jetzt prüfen?">Jetzt prüfen</button>
        </form>

        <div class="form-text mt-3">
            Für echte automatische Ausführung per Cronjob diese URL verwenden:
            <code>action_escalation_run.php?token=DEIN_TOKEN</code>
        </div>
    </div>
</div>

<?php if ($result !== null): ?>
    <div class="card escalation-result-card">
        <div class="card-header">
            <h2 class="h5 mb-0">Ergebnis</h2>
        </div>
        <div class="card-body">
            <div class="escalation-result-grid mb-3">
                <div class="escalation-result-metric">
                    <div class="label">Geprüft</div>
                    <strong><?= (int)$result['checked_actions'] ?></strong>
                </div>
                <div class="escalation-result-metric">
                    <div class="label">Gelb</div>
                    <strong><?= (int)$result['yellow_actions'] ?></strong>
                </div>
                <div class="escalation-result-metric">
                    <div class="label">Rot</div>
                    <strong><?= (int)$result['red_actions'] ?></strong>
                </div>
                <div class="escalation-result-metric">
                    <div class="label">Gesendet</div>
                    <strong><?= (int)$result['emails_sent'] ?></strong>
                </div>
                <div class="escalation-result-metric">
                    <div class="label">Fehlgeschlagen</div>
                    <strong><?= (int)$result['emails_failed'] ?></strong>
                </div>
                <div class="escalation-result-metric">
                    <div class="label">Schon gesendet</div>
                    <strong><?= (int)$result['skipped_already_sent'] ?></strong>
                </div>
            </div>

            <?php if (!empty($result['details'])): ?>
                <pre class="escalation-details-pre"><?= e(implode("\n", $result['details'])) ?></pre>
            <?php else: ?>
                <div class="text-muted">Keine Details.</div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
