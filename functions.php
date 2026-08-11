<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';


function db_table_exists(string $table): bool
{
    static $cache = [];
    if (array_key_exists($table, $cache)) {
        return $cache[$table];
    }

    try {
        $stmt = pdo()->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?');
        $stmt->execute([$table]);
        return $cache[$table] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$table] = false;
    }
}

function db_column_exists(string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    try {
        $stmt = pdo()->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?');
        $stmt->execute([$table, $column]);
        return $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    } catch (Throwable $e) {
        return $cache[$key] = false;
    }
}

function locations_enabled(): bool
{
    return db_table_exists('standorte') && db_table_exists('user_standorte') && db_column_exists('claims', 'standort_id');
}

function get_locations(bool $activeOnly = true): array
{
    if (!db_table_exists('standorte')) {
        return [];
    }

    $sql = 'SELECT * FROM standorte';
    if ($activeOnly) {
        $sql .= ' WHERE aktiv = 1';
    }
    $sql .= ' ORDER BY name ASC';
    return pdo()->query($sql)->fetchAll();
}

function location_by_id(int $locationId): ?array
{
    if (!db_table_exists('standorte') || $locationId <= 0) {
        return null;
    }

    $stmt = pdo()->prepare('SELECT * FROM standorte WHERE id = ? LIMIT 1');
    $stmt->execute([$locationId]);
    return $stmt->fetch() ?: null;
}

function user_allowed_locations(?int $userId = null): array
{
    if (!locations_enabled()) {
        return [];
    }

    $user = current_user();
    if ($userId === null) {
        if (!$user) {
            return [];
        }
        $userId = (int)$user['id'];
    }

    if ($user && (int)$user['id'] === $userId && $user['role'] === 'admin') {
        return get_locations(true);
    }

    $stmt = pdo()->prepare('SELECT s.*
        FROM standorte s
        JOIN user_standorte us ON us.standort_id = s.id
        WHERE us.user_id = ? AND s.aktiv = 1
        ORDER BY us.is_default DESC, s.name ASC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll();
}

function user_location_ids(int $userId): array
{
    if (!db_table_exists('user_standorte')) {
        return [];
    }
    $stmt = pdo()->prepare('SELECT standort_id FROM user_standorte WHERE user_id = ?');
    $stmt->execute([$userId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
}

function can_access_location_id(?int $locationId): bool
{
    if (!locations_enabled()) {
        return true;
    }

    $user = current_user();
    if (!$user) {
        return false;
    }

    if ($user['role'] === 'admin') {
        return true;
    }

    if ($locationId === null || $locationId <= 0) {
        return false;
    }

    return in_array((int)$locationId, user_location_ids((int)$user['id']), true);
}

function selected_location_id(): ?int
{
    if (!locations_enabled()) {
        return null;
    }

    $user = current_user();
    if (!$user) {
        return null;
    }

    if ($user['role'] === 'admin' && ($_SESSION['standort_id'] ?? '') === 'all') {
        return null;
    }

    $allowed = user_allowed_locations((int)$user['id']);
    if (!$allowed) {
        return null;
    }

    $allowedIds = array_map(static fn(array $row): int => (int)$row['id'], $allowed);
    $sessionLocation = $_SESSION['standort_id'] ?? null;
    if (is_numeric($sessionLocation) && in_array((int)$sessionLocation, $allowedIds, true)) {
        return (int)$sessionLocation;
    }

    if (db_table_exists('user_standorte')) {
        $stmt = pdo()->prepare('SELECT standort_id FROM user_standorte WHERE user_id = ? AND is_default = 1 LIMIT 1');
        $stmt->execute([(int)$user['id']]);
        $defaultId = (int)($stmt->fetchColumn() ?: 0);
        if ($defaultId > 0 && in_array($defaultId, $allowedIds, true)) {
            $_SESSION['standort_id'] = $defaultId;
            return $defaultId;
        }
    }

    foreach ($allowed as $row) {
        if ((string)($row['kuerzel'] ?? '') === 'WUN') {
            $_SESSION['standort_id'] = (int)$row['id'];
            return (int)$row['id'];
        }
    }

    $_SESSION['standort_id'] = (int)$allowed[0]['id'];
    return (int)$allowed[0]['id'];
}

function selected_location(): ?array
{
    $id = selected_location_id();
    return $id ? location_by_id($id) : null;
}

function location_scope_condition(string $claimAlias = 'c'): array
{
    if (!locations_enabled()) {
        return ['', []];
    }

    $user = current_user();
    if (!$user) {
        return [' AND 1=0', []];
    }

    $selected = selected_location_id();
    if ($user['role'] === 'admin' && $selected === null) {
        return ['', []];
    }

    if ($selected !== null) {
        return [' AND ' . $claimAlias . '.standort_id = ?', [$selected]];
    }

    $ids = user_location_ids((int)$user['id']);
    if (!$ids) {
        return [' AND 1=0', []];
    }
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    return [' AND ' . $claimAlias . '.standort_id IN (' . $placeholders . ')', $ids];
}

function location_badge(?int $locationId): string
{
    if (!locations_enabled() || !$locationId) {
        return '';
    }

    $location = location_by_id((int)$locationId);
    if (!$location) {
        return '<span class="badge bg-light text-dark">Standort unbekannt</span>';
    }

    return '<span class="badge bg-info-subtle text-info-emphasis border border-info-subtle">' . e($location['kuerzel']) . ' · ' . e($location['name']) . '</span>';
}

function require_claim_access(int $claimId): array
{
    if (locations_enabled()) {
        $stmt = pdo()->prepare('SELECT id, standort_id FROM claims WHERE id = ? LIMIT 1');
    } else {
        $stmt = pdo()->prepare('SELECT id FROM claims WHERE id = ? LIMIT 1');
    }
    $stmt->execute([$claimId]);
    $claim = $stmt->fetch();
    if (!$claim) {
        http_response_code(404);
        die('Reklamation nicht gefunden.');
    }

    if (locations_enabled() && !can_access_location_id(isset($claim['standort_id']) ? (int)$claim['standort_id'] : null)) {
        http_response_code(403);
        die('Keine Berechtigung für diesen Standort.');
    }

    return $claim;
}

function next_claim_number(int $claimId, ?int $locationId = null): string
{
    $prefix = 'R';
    if ($locationId && locations_enabled()) {
        $location = location_by_id($locationId);
        if ($location && !empty($location['kuerzel'])) {
            $prefix = preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$location['kuerzel'])) ?: 'R';
        }
    }
    return sprintf('%s-8D-%s-%04d', $prefix, date('Y'), $claimId);
}

function get_users_for_select(?int $locationId = null): array
{
    if (!locations_enabled()) {
        return pdo()->query('SELECT id, name, role FROM users WHERE active = 1 ORDER BY name')->fetchAll();
    }

    if ($locationId === null) {
        $locationId = selected_location_id();
    }

    if ($locationId === null) {
        return pdo()->query('SELECT id, name, role FROM users WHERE active = 1 ORDER BY name')->fetchAll();
    }

    $stmt = pdo()->prepare("SELECT DISTINCT u.id, u.name, u.role
        FROM users u
        LEFT JOIN user_standorte us ON us.user_id = u.id
        WHERE u.active = 1
          AND (u.role = 'admin' OR us.standort_id = ?)
        ORDER BY u.name ASC");
    $stmt->execute([$locationId]);
    return $stmt->fetchAll();
}

function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!is_string($token) || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        die('Ungültige Sitzung. Bitte zurückgehen und erneut versuchen.');
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    static $user = null;
    if ($user !== null) {
        return $user;
    }

    $stmt = pdo()->prepare('SELECT id, name, email, role, active FROM users WHERE id = ? AND active = 1');
    $stmt->execute([(int)$_SESSION['user_id']]);
    $user = $stmt->fetch() ?: null;
    return $user;
}


function safe_login_return_to(?string $returnTo = null): string
{
    $returnTo = trim((string)$returnTo);

    if ($returnTo === '') {
        return 'dashboard.php';
    }

    // Absolute externe URLs verhindern.
    if (preg_match('/^https?:\/\//i', $returnTo) || str_starts_with($returnTo, '//')) {
        return 'dashboard.php';
    }

    // Backslashes und Control-Zeichen verhindern.
    if (str_contains($returnTo, '\\') || preg_match('/[\x00-\x1F\x7F]/', $returnTo)) {
        return 'dashboard.php';
    }

    // Login darf nicht wieder Login als Ziel haben, sonst entsteht ein Loop.
    $pathOnly = parse_url($returnTo, PHP_URL_PATH);
    $pathOnly = is_string($pathOnly) ? basename($pathOnly) : '';
    if ($pathOnly === 'login.php') {
        return 'dashboard.php';
    }

    // Absolute interne Pfade erlauben, z. B. /claim_view.php?id=123
    if (str_starts_with($returnTo, '/')) {
        return $returnTo;
    }

    // Relative interne Pfade erlauben, aber keine Verzeichnis-Sprünge.
    if (str_contains($returnTo, '..')) {
        return 'dashboard.php';
    }

    return $returnTo;
}

function require_login(): void
{
    if (!current_user()) {
        $returnTo = safe_login_return_to($_SERVER['REQUEST_URI'] ?? 'dashboard.php');

        $_SESSION['login_return_to'] = $returnTo;

        redirect('login.php?return=' . urlencode($returnTo));
    }
}

function is_admin(): bool
{
    $user = current_user();
    return $user && $user['role'] === 'admin';
}

function require_admin(): void
{
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        die('Keine Berechtigung. Dieser Bereich ist nur für Admins freigegeben.');
    }
}

function can_edit(): bool
{
    $user = current_user();
    return $user && in_array($user['role'], ['admin', 'quality', 'employee'], true);
}

function can_close_claim(): bool
{
    $user = current_user();
    return $user && in_array($user['role'], ['admin', 'quality'], true);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function render_flash(): void
{
    $items = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    foreach ($items as $item) {
        $type = $item['type'] === 'error' ? 'danger' : $item['type'];
        echo '<div class="alert alert-' . e($type) . ' alert-dismissible fade show js-auto-dismiss-alert" role="alert" data-auto-dismiss-ms="3000">';
        echo e($item['message']);
        echo '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Schließen"></button>';
        echo '</div>';
    }

    if ($items) {
        echo '<style>
.js-auto-dismiss-alert {
    overflow: hidden;
    transition:
        opacity .85s ease,
        transform .85s ease,
        max-height .85s ease,
        margin .85s ease,
        padding-top .85s ease,
        padding-bottom .85s ease,
        border-width .85s ease;
    max-height: 160px;
}

.js-auto-dismiss-alert.is-soft-hiding {
    opacity: 0;
    transform: translateY(-8px);
    max-height: 0;
    margin-top: 0 !important;
    margin-bottom: 0 !important;
    padding-top: 0 !important;
    padding-bottom: 0 !important;
    border-width: 0 !important;
}
</style>
<script>
document.addEventListener("DOMContentLoaded", function () {
    document.querySelectorAll(".js-auto-dismiss-alert").forEach(function (alertEl) {
        var timeout = parseInt(alertEl.getAttribute("data-auto-dismiss-ms") || "3000", 10);
        var fadeTime = 900;

        window.setTimeout(function () {
            if (!alertEl || !alertEl.parentNode) {
                return;
            }

            alertEl.classList.add("is-soft-hiding");

            window.setTimeout(function () {
                if (alertEl && alertEl.parentNode) {
                    alertEl.remove();
                }
            }, fadeTime);
        }, timeout);
    });
});
</script>';
    }
}

function claim_step_definitions(): array
{
    return [
        'D1' => ['title' => 'Team bilden', 'description' => 'Beteiligte Personen, Rollen und Verantwortlichkeiten festlegen.'],
        'D2' => ['title' => 'Problem beschreiben', 'description' => 'Problem eindeutig mit Fakten, Mengen, Datum, Ort und betroffenen Artikeln beschreiben.'],
        'D3' => ['title' => 'Sofortmaßnahmen', 'description' => 'Akute Maßnahmen zur Schadensbegrenzung definieren.'],
        'D4' => ['title' => 'Ursachenanalyse', 'description' => 'Hauptursache ermitteln, z. B. mit 5-Why oder Ishikawa.'],
        'D5' => ['title' => 'Korrekturmaßnahmen planen', 'description' => 'Dauerhafte Maßnahmen gegen die ermittelte Ursache festlegen.'],
        'D6' => ['title' => 'Maßnahmen umsetzen', 'description' => 'Umsetzung dokumentieren und Nachweise sichern.'],
        'D7' => ['title' => 'Wiederholfehler verhindern', 'description' => 'Vorbeugende Maßnahmen, Standards, Schulungen oder Prüfungen festlegen.'],
        'D8' => ['title' => 'Abschluss', 'description' => 'Wirksamkeit bestätigen, Kunden/Lieferanten informieren und Fall abschließen.'],
    ];
}

function role_options(): array
{
    return [
        'admin' => 'Admin',
        'quality' => 'Qualität',
        'employee' => 'Mitarbeiter',
        'viewer' => 'Leser',
    ];
}

function role_label(string $role): string
{
    return role_options()[$role] ?? $role;
}

function role_badge(string $role): string
{
    $class = match ($role) {
        'admin' => 'danger',
        'quality' => 'primary',
        'employee' => 'success',
        'viewer' => 'secondary',
        default => 'light text-dark',
    };
    return '<span class="badge bg-' . $class . '">' . e(role_label($role)) . '</span>';
}

function priority_label(string $priority): string
{
    return match ($priority) {
        'low' => 'Niedrig',
        'medium' => 'Mittel',
        'high' => 'Hoch',
        'critical' => 'Kritisch',
        default => $priority,
    };
}

function status_label(string $status): string
{
    return match ($status) {
        'customer' => 'Kundenreklamation',
        'supplier' => 'Lieferantenreklamation',
        'internal' => 'Interne Reklamation',
        'new' => 'Neu',
        'in_progress' => 'In Bearbeitung',
        'waiting' => 'Wartet',
        'overdue' => 'Überfällig',
        'closed' => 'Abgeschlossen',
        'rejected' => 'Abgelehnt',
        'archived' => 'Archiviert',
        'open' => 'Offen',
        'done' => 'Erledigt',
        'cancelled' => 'Abgebrochen',
        default => $status,
    };
}

function status_badge(string $status): string
{
    $class = match ($status) {
        'closed', 'done' => 'success',
        'overdue', 'critical' => 'danger',
        'waiting' => 'warning text-dark',
        'in_progress' => 'primary',
        'archived' => 'secondary',
        default => 'light text-dark',
    };
    return '<span class="badge bg-' . $class . '">' . e(status_label($status)) . '</span>';
}

function log_history(int $claimId, string $action, ?string $details = null): void
{
    $user = current_user();
    $stmt = pdo()->prepare('INSERT INTO claim_history (claim_id, user_id, action, details) VALUES (?, ?, ?, ?)');
    $stmt->execute([$claimId, $user['id'] ?? null, $action, $details]);
}

function log_history_for_user(int $claimId, ?int $userId, string $action, ?string $details = null): void
{
    $stmt = pdo()->prepare('INSERT INTO claim_history (claim_id, user_id, action, details) VALUES (?, ?, ?, ?)');
    $stmt->execute([$claimId, $userId, $action, $details]);
}

function step_audit_title(string $stepKey, string $status): string
{
    $definition = claim_step_definitions()[$stepKey] ?? ['title' => $stepKey];
    $title = $definition['title'] ?? $stepKey;

    return match ($status) {
        'done' => $stepKey . ' ' . $title . ' abgeschlossen',
        'in_progress' => $stepKey . ' ' . $title . ' gestartet',
        'open' => $stepKey . ' ' . $title . ' wieder geöffnet',
        default => $stepKey . ' ' . $title . ' aktualisiert',
    };
}

function build_change_details(array $changes): string
{
    $lines = [];
    foreach ($changes as $label => $values) {
        $old = (string)($values[0] ?? '');
        $new = (string)($values[1] ?? '');
        if ($old === $new) {
            continue;
        }
        $lines[] = $label . ': ' . ($old !== '' ? $old : '-') . ' → ' . ($new !== '' ? $new : '-');
    }
    return implode("
", $lines);
}

function history_icon_class(string $action): string
{
    if (str_contains($action, 'Reklamation erstellt')) return 'timeline-icon-create';
    if (str_contains($action, 'abgeschlossen') || str_contains($action, 'erledigt') || str_contains($action, 'Fall abgeschlossen')) return 'timeline-icon-done';
    if (str_contains($action, 'gelöscht') || str_contains($action, 'fehlgeschlagen')) return 'timeline-icon-danger';
    if (str_contains($action, 'Maßnahme')) return 'timeline-icon-action';
    if (str_starts_with($action, 'D')) return 'timeline-icon-step';
    if (str_contains($action, 'Status') || str_contains($action, 'Fallstatus')) return 'timeline-icon-status';
    if (str_contains($action, 'Datei')) return 'timeline-icon-file';
    if (str_contains($action, 'Erinnerung')) return 'timeline-icon-mail';
    return 'timeline-icon-default';
}

function is_image_mime(?string $mime): bool
{
    return is_string($mime) && str_starts_with($mime, 'image/');
}


function file_step_options(): array
{
    $options = ['' => 'Allgemein / ohne D-Schritt'];
    foreach (claim_step_definitions() as $key => $definition) {
        $options[$key] = $key . ' · ' . ($definition['title'] ?? $key);
    }
    return $options;
}

function file_category_options(): array
{
    return [
        'problem' => 'Problemfoto',
        'containment' => 'Sofortmaßnahme',
        'cause' => 'Ursachenanalyse',
        'corrective' => 'Korrekturmaßnahme',
        'proof' => 'Nachweis / Umsetzung',
        'other' => 'Sonstiges',
    ];
}

function file_category_label(?string $category): string
{
    $category = $category ?: 'other';
    return file_category_options()[$category] ?? 'Sonstiges';
}

function file_step_badge(?string $stepKey): string
{
    $stepKey = trim((string)$stepKey);
    if ($stepKey === '') {
        return '<span class="badge bg-light text-dark">Allgemein</span>';
    }
    return '<span class="badge bg-secondary">' . e($stepKey) . '</span>';
}

function file_category_badge(?string $category): string
{
    $class = match ($category ?: 'other') {
        'problem' => 'danger',
        'containment' => 'warning text-dark',
        'cause' => 'primary',
        'corrective' => 'info text-dark',
        'proof' => 'success',
        default => 'secondary',
    };
    return '<span class="badge bg-' . $class . '">' . e(file_category_label($category)) . '</span>';
}

function claim_file_public_url(array $file): string
{
    return rtrim(APP_UPLOAD_URL, '/') . '/' . ltrim((string)$file['file_path'], '/');
}


function active_admin_count(?int $excludeUserId = null): int
{
    if ($excludeUserId === null) {
        return (int)pdo()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")->fetchColumn();
    }

    $stmt = pdo()->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1 AND id <> ?");
    $stmt->execute([$excludeUserId]);
    return (int)$stmt->fetchColumn();
}

function password_rules_hint(): string
{
    return 'Mindestens 8 Zeichen. Besser: Groß-/Kleinbuchstaben, Zahl und Sonderzeichen.';
}


function action_is_closed(array $action): bool
{
    return in_array((string)($action['status'] ?? ''), ['done', 'cancelled'], true);
}

function action_age_days(array $action): int
{
    $createdAt = (string)($action['created_at'] ?? '');
    if ($createdAt === '') {
        return 0;
    }

    try {
        $created = new DateTimeImmutable(substr($createdAt, 0, 10));
        $today = new DateTimeImmutable(date('Y-m-d'));
        return max(0, (int)$created->diff($today)->format('%a'));
    } catch (Throwable $e) {
        return 0;
    }
}

function action_is_overdue(array $action): bool
{
    if (action_is_closed($action)) {
        return false;
    }

    $dueDate = (string)($action['due_date'] ?? '');
    return $dueDate !== '' && $dueDate < date('Y-m-d');
}

function action_is_due_today(array $action): bool
{
    if (action_is_closed($action)) {
        return false;
    }

    $dueDate = (string)($action['due_date'] ?? '');
    return $dueDate !== '' && $dueDate === date('Y-m-d');
}

function action_traffic_level(array $action): string
{
    if (action_is_closed($action)) {
        return 'done';
    }

    if (action_is_overdue($action)) {
        return 'red';
    }

    $days = action_age_days($action);
    if ($days <= 5) {
        return 'green';
    }
    if ($days <= 10) {
        return 'yellow';
    }
    return 'red';
}

function action_traffic_text(array $action): string
{
    $days = action_age_days($action);
    if (action_is_closed($action)) {
        return 'Erledigt';
    }
    if (action_is_overdue($action)) {
        return 'Rot · Frist überschritten';
    }

    return match (action_traffic_level($action)) {
        'green' => 'Grün · ' . $days . ' Tage',
        'yellow' => 'Gelb · ' . $days . ' Tage',
        'red' => 'Rot · ' . $days . ' Tage',
        default => 'Unbekannt',
    };
}

function action_traffic_badge(array $action): string
{
    $class = match (action_traffic_level($action)) {
        'green' => 'success',
        'yellow' => 'warning text-dark',
        'red' => 'danger',
        'done' => 'secondary',
        default => 'light text-dark',
    };

    return '<span class="badge bg-' . $class . '">' . e(action_traffic_text($action)) . '</span>';
}

function action_row_class(array $action): string
{
    return match (action_traffic_level($action)) {
        'green' => 'table-success',
        'yellow' => 'table-warning',
        'red' => 'table-danger',
        default => '',
    };
}

function action_due_hint(array $action): string
{
    if (empty($action['due_date'])) {
        return '<span class="text-muted">keine Frist</span>';
    }

    $html = '<strong>' . e((string)$action['due_date']) . '</strong>';
    if (action_is_overdue($action)) {
        $html .= '<div class="small fw-bold text-danger">überfällig</div>';
    } elseif (action_is_due_today($action)) {
        $html .= '<div class="small fw-bold text-warning-emphasis">heute fällig</div>';
    }

    return $html;
}



function my_open_action_count(?int $userId = null): int
{
    if ($userId === null) {
        $user = current_user();
        if (!$user) {
            return 0;
        }
        $userId = (int)$user['id'];
    }

    [$locationSql, $locationParams] = location_scope_condition('c');
    $stmt = pdo()->prepare("SELECT COUNT(*)
        FROM claim_actions a
        JOIN claims c ON c.id = a.claim_id
        WHERE a.responsible_user_id = ?
          AND a.status IN ('open','in_progress')
          AND c.status NOT IN ('closed','rejected','archived')" . $locationSql);
    $stmt->execute(array_merge([$userId], $locationParams));
    return (int)$stmt->fetchColumn();
}

function my_critical_action_count(?int $userId = null): int
{
    if ($userId === null) {
        $user = current_user();
        if (!$user) {
            return 0;
        }
        $userId = (int)$user['id'];
    }

    [$locationSql, $locationParams] = location_scope_condition('c');
    $stmt = pdo()->prepare("SELECT COUNT(*)
        FROM claim_actions a
        JOIN claims c ON c.id = a.claim_id
        WHERE a.responsible_user_id = ?
          AND a.status IN ('open','in_progress')
          AND c.status NOT IN ('closed','rejected','archived')
          AND (
                (a.due_date IS NOT NULL AND a.due_date < CURDATE())
                OR DATEDIFF(CURDATE(), DATE(a.created_at)) >= 11
          )" . $locationSql);
    $stmt->execute(array_merge([$userId], $locationParams));
    return (int)$stmt->fetchColumn();
}

function nav_count_label(int $count): string
{
    return $count > 99 ? '99+' : (string)$count;
}

function safe_action_return_to(string $returnTo): string
{
    $returnTo = trim($returnTo);
    if ($returnTo !== '' && preg_match('/^my_actions\.php(\?.*)?$/', $returnTo)) {
        return $returnTo;
    }
    return '';
}
