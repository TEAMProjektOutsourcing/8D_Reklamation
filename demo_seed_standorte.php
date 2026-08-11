<?php
require_once __DIR__ . '/auth.php';
require_admin();

$db = pdo();
$error = null;
$createdClaims = [];

function loc_demo_date(int $daysOffset = 0): string
{
    return (new DateTimeImmutable('today'))->modify(($daysOffset >= 0 ? '+' : '') . $daysOffset . ' days')->format('Y-m-d');
}

function loc_demo_datetime(int $daysOffset = 0, string $time = '09:00:00'): string
{
    return loc_demo_date($daysOffset) . ' ' . $time;
}

function loc_demo_add_minutes(string $dateTime, int $minutes): string
{
    return (new DateTimeImmutable($dateTime))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
}

function loc_demo_excerpt(?string $text, int $max = 180): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s+/', ' ', $text) ?: $text;
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

function loc_demo_svg(string $title, string $subtitle, string $color = '#0d6efd'): string
{
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $subtitle = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="750" viewBox="0 0 1200 750">
  <rect width="1200" height="750" fill="#f8f9fa"/>
  <rect x="70" y="70" width="1060" height="610" rx="28" fill="#ffffff" stroke="#dee2e6" stroke-width="8"/>
  <circle cx="190" cy="190" r="70" fill="{$color}" opacity="0.9"/>
  <rect x="300" y="150" width="660" height="50" rx="12" fill="#adb5bd" opacity="0.55"/>
  <rect x="300" y="235" width="790" height="38" rx="10" fill="#ced4da" opacity="0.8"/>
  <rect x="300" y="300" width="540" height="38" rx="10" fill="#ced4da" opacity="0.65"/>
  <rect x="130" y="430" width="940" height="150" rx="18" fill="#e9ecef" stroke="#ced4da" stroke-width="4"/>
  <text x="130" y="645" font-family="Arial, sans-serif" font-size="44" font-weight="700" fill="#212529">{$title}</text>
  <text x="130" y="700" font-family="Arial, sans-serif" font-size="28" fill="#495057">{$subtitle}</text>
</svg>
SVG;
}

function loc_demo_status_text(string $status): string
{
    return match ($status) {
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

function loc_demo_step_action(string $stepKey, string $status): string
{
    $titles = claim_step_definitions();
    $title = $titles[$stepKey]['title'] ?? $stepKey;
    return match ($status) {
        'done' => $stepKey . ' ' . $title . ' abgeschlossen',
        'in_progress' => $stepKey . ' ' . $title . ' gestartet',
        default => $stepKey . ' ' . $title . ' geöffnet',
    };
}

function loc_demo_location(string $kuerzel, string $name, string $address): int
{
    $db = pdo();
    $stmt = $db->prepare('SELECT id FROM standorte WHERE kuerzel = ? LIMIT 1');
    $stmt->execute([$kuerzel]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $upd = $db->prepare('UPDATE standorte SET name = ?, adresse = ?, aktiv = 1 WHERE id = ?');
        $upd->execute([$name, $address, (int)$id]);
        return (int)$id;
    }

    $ins = $db->prepare('INSERT INTO standorte (name, kuerzel, adresse, aktiv) VALUES (?, ?, ?, 1)');
    $ins->execute([$name, $kuerzel, $address]);
    return (int)$db->lastInsertId();
}

function loc_demo_user(string $email, string $name, string $role): int
{
    $db = pdo();
    $stmt = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $upd = $db->prepare('UPDATE users SET name = ?, role = ?, active = 1 WHERE id = ?');
        $upd->execute([$name, $role, (int)$id]);
        return (int)$id;
    }

    $hash = password_hash('Demo12345!', PASSWORD_DEFAULT);
    $ins = $db->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)');
    $ins->execute([$name, $email, $hash, $role]);
    return (int)$db->lastInsertId();
}

function loc_demo_assign(int $userId, int $locationId, string $standortRole, bool $default = true): void
{
    $db = pdo();
    if ($default) {
        $stmt = $db->prepare('UPDATE user_standorte SET is_default = 0 WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    $stmt = $db->prepare('INSERT INTO user_standorte (user_id, standort_id, standort_role, is_default)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE standort_role = VALUES(standort_role), is_default = VALUES(is_default)');
    $stmt->execute([$userId, $locationId, $standortRole, $default ? 1 : 0]);
}

function loc_demo_file(string $relativePath, string $content): array
{
    $path = APP_UPLOAD_DIR . '/' . $relativePath;
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Demo-Uploadordner konnte nicht erstellt werden: ' . $dir);
    }
    file_put_contents($path, $content);
    return [$path, filesize($path) ?: strlen($content)];
}

function loc_demo_history(array $claim, array $stepStatuses, array $stepContents, array $actions, array $files): array
{
    $history = [];
    $createdAt = (string)$claim['created_at'];
    $creatorId = (int)$claim['created_by'];
    $responsibleId = (int)($claim['responsible_user_id'] ?: $creatorId);

    $history[] = [
        'user_id' => $creatorId,
        'action' => 'Reklamation erstellt',
        'details' => 'Standort-Demo: ' . ($claim['claim_number'] ?? '') . "\nQuelle: " . (($claim['source_module'] ?? '-') ?: '-') . ' ' . (($claim['source_number'] ?? '') ?: ''),
        'created_at' => $createdAt,
    ];

    $minute = 25;
    foreach (claim_step_definitions() as $key => $definition) {
        $status = $stepStatuses[$key] ?? 'open';
        if ($status === 'open') {
            continue;
        }
        $history[] = [
            'user_id' => $responsibleId,
            'action' => loc_demo_step_action($key, $status),
            'details' => loc_demo_excerpt($stepContents[$key] ?? ''),
            'created_at' => loc_demo_add_minutes($createdAt, $minute),
        ];
        $minute += 45;
    }

    foreach ($actions as $action) {
        $history[] = [
            'user_id' => (int)$action['created_by'],
            'action' => 'Maßnahme erstellt',
            'details' => 'D-Schritt: ' . $action['step_key'] . "\nMaßnahme: " . $action['title'] . "\nFrist: " . ($action['due_date'] ?? 'keine Frist') . "\nStatus: " . loc_demo_status_text((string)($action['status'] ?? 'open')),
            'created_at' => (string)$action['created_at'],
        ];
        if (($action['status'] ?? '') === 'done') {
            $history[] = [
                'user_id' => isset($action['responsible_user_id']) ? (int)$action['responsible_user_id'] : (int)$action['created_by'],
                'action' => 'Maßnahme erledigt',
                'details' => 'Maßnahme: ' . $action['title'],
                'created_at' => (string)($action['completed_at'] ?? $action['created_at']),
            ];
        }
    }

    foreach ($files as $file) {
        $history[] = [
            'user_id' => (int)$file['uploaded_by'],
            'action' => 'Datei hochgeladen',
            'details' => (string)$file['original_name'],
            'created_at' => (string)$file['created_at'],
        ];
    }

    if (($claim['status'] ?? '') !== 'new') {
        $history[] = [
            'user_id' => (int)($claim['closed_by'] ?? $responsibleId),
            'action' => (($claim['status'] ?? '') === 'closed') ? 'Fall abgeschlossen' : 'Fallstatus geändert',
            'details' => 'Fallstatus: ' . loc_demo_status_text((string)$claim['status']),
            'created_at' => (string)($claim['closed_at'] ?? loc_demo_add_minutes($createdAt, 380)),
        ];
    }

    usort($history, static fn(array $a, array $b): int => strcmp((string)$a['created_at'], (string)$b['created_at']));
    return $history;
}

function loc_demo_insert_claim(array $claim, array $stepStatuses, array $stepContents, array $actions, array $files): int
{
    $db = pdo();
    $stmt = $db->prepare('INSERT INTO claims (claim_number, standort_id, claim_type, partner_name, article_number, article_name, quantity_affected, delivery_date, claim_date, priority, status, short_description, problem_description, responsible_user_id, source_module, source_number, source_url, created_by, closed_by, closed_at, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([
        $claim['claim_number'],
        $claim['standort_id'],
        $claim['claim_type'],
        $claim['partner_name'],
        $claim['article_number'],
        $claim['article_name'],
        $claim['quantity_affected'],
        $claim['delivery_date'],
        $claim['claim_date'],
        $claim['priority'],
        $claim['status'],
        $claim['short_description'],
        $claim['problem_description'],
        $claim['responsible_user_id'],
        $claim['source_module'] ?? null,
        $claim['source_number'] ?? null,
        $claim['source_url'] ?? null,
        $claim['created_by'],
        $claim['closed_by'] ?? null,
        $claim['closed_at'] ?? null,
        $claim['created_at'],
    ]);
    $claimId = (int)$db->lastInsertId();

    foreach (claim_step_definitions() as $key => $definition) {
        $status = $stepStatuses[$key] ?? 'open';
        $content = $stepContents[$key] ?? null;
        $completedBy = $status === 'done' ? ($claim['responsible_user_id'] ?: $claim['created_by']) : null;
        $completedAt = $status === 'done' ? loc_demo_add_minutes((string)$claim['created_at'], 180) : null;
        $stmt = $db->prepare('INSERT INTO claim_steps (claim_id, step_key, title, description, content, status, completed_by, completed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$claimId, $key, $definition['title'], $definition['description'], $content, $status, $completedBy, $completedAt, $claim['created_at']]);
    }

    foreach ($actions as $action) {
        $stmt = $db->prepare('INSERT INTO claim_actions (claim_id, step_key, title, description, responsible_user_id, due_date, status, completed_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $claimId,
            $action['step_key'],
            $action['title'],
            $action['description'] ?? null,
            $action['responsible_user_id'] ?? null,
            $action['due_date'] ?? null,
            $action['status'] ?? 'open',
            $action['completed_at'] ?? null,
            $action['created_by'],
            $action['created_at'],
        ]);
    }

    foreach ($files as $file) {
        [$path, $size] = loc_demo_file((string)$file['file_path'], (string)$file['content']);
        $stmt = $db->prepare('INSERT INTO claim_files (claim_id, original_name, stored_name, file_path, mime_type, size_bytes, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $claimId,
            $file['original_name'],
            basename((string)$file['file_path']),
            $file['file_path'],
            $file['mime_type'] ?? 'text/plain',
            $size,
            $file['uploaded_by'],
            $file['created_at'],
        ]);
    }

    $history = loc_demo_history($claim, $stepStatuses, $stepContents, $actions, $files);
    foreach ($history as $event) {
        $stmt = $db->prepare('INSERT INTO claim_history (claim_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$claimId, $event['user_id'], $event['action'], $event['details'], $event['created_at']]);
    }

    return $claimId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_check($_POST['csrf_token'] ?? '')) {
        flash('danger', 'Ungültiges CSRF-Token.');
        redirect('demo_seed_standorte.php');
    }

    if (!locations_enabled()) {
        flash('danger', 'Mehrstandort-Funktion ist noch nicht aktiv. Bitte erst run_location_migration.php ausführen.');
        redirect('demo_seed_standorte.php');
    }

    try {
        $admin = current_user();
        $adminId = (int)($admin['id'] ?? 1);

        $wunId = loc_demo_location('WUN', 'Wunstorf', 'Wunstorf');
        $hanId = loc_demo_location('HAN', 'Hannover', 'Hannover');
        $fraId = loc_demo_location('FRA', 'Frankfurt', 'Am Prime-Parc 17, 65479 Raunheim');

        loc_demo_assign($adminId, $wunId, 'admin', true);
        loc_demo_assign($adminId, $hanId, 'admin', false);
        loc_demo_assign($adminId, $fraId, 'admin', false);

        $wunQuality = loc_demo_user('demo.wun.qualitaet@example.com', 'Wunstorf Qualität Demo', 'quality');
        $wunLager = loc_demo_user('demo.wun.lager@example.com', 'Wunstorf Lager Demo', 'employee');
        $hanQuality = loc_demo_user('demo.han.qualitaet@example.com', 'Hannover Qualität Demo', 'quality');
        $hanDispo = loc_demo_user('demo.han.dispo@example.com', 'Hannover Dispo Demo', 'employee');
        $fraQuality = loc_demo_user('demo.fra.qualitaet@example.com', 'Frankfurt Qualität Demo', 'quality');
        $fraLager = loc_demo_user('demo.fra.lager@example.com', 'Frankfurt Lager Demo', 'employee');
        $fraViewer = loc_demo_user('demo.fra.leser@example.com', 'Frankfurt Leser Demo', 'viewer');

        loc_demo_assign($wunQuality, $wunId, 'quality', true);
        loc_demo_assign($wunLager, $wunId, 'employee', true);
        loc_demo_assign($hanQuality, $hanId, 'quality', true);
        loc_demo_assign($hanDispo, $hanId, 'employee', true);
        loc_demo_assign($fraQuality, $fraId, 'quality', true);
        loc_demo_assign($fraLager, $fraId, 'employee', true);
        loc_demo_assign($fraViewer, $fraId, 'viewer', true);

        $db->beginTransaction();
        $delete = $db->prepare("DELETE FROM claims WHERE claim_number LIKE 'WUN-DEMO-%' OR claim_number LIKE 'HAN-DEMO-%' OR claim_number LIKE 'FRA-DEMO-%'");
        $delete->execute();

        $cases = [
            [
                'claim' => [
                    'claim_number' => 'WUN-DEMO-0001', 'standort_id' => $wunId, 'claim_type' => 'customer', 'partner_name' => 'VW Werk Hannover',
                    'article_number' => '5Q0-807-217-A', 'article_name' => 'Stoßfänger vorne', 'quantity_affected' => 4,
                    'delivery_date' => loc_demo_date(-2), 'claim_date' => loc_demo_date(-1), 'priority' => 'high', 'status' => 'new',
                    'short_description' => 'Kratzer am Stoßfänger nach Warenausgang',
                    'problem_description' => 'Frischer Wunstorf-Fall mit D1 aktiv. Gut zum Prüfen von grünem Maßnahmenstatus, Standort-Badge und Workbench-Quelle Warenausgang.',
                    'responsible_user_id' => $wunQuality, 'source_module' => 'warenausgang', 'source_number' => 'WA-WUN-DEMO-2828', 'source_url' => '/workbench/warenausgang.php?ausgang=2828', 'created_by' => $adminId, 'created_at' => loc_demo_datetime(-1, '08:10:00'),
                ],
                'steps' => ['D1' => 'in_progress'],
                'contents' => ['D1' => 'Team wird gebildet: Qualität Wunstorf, Lager Wunstorf und Versand.'],
                'actions' => [
                    ['step_key' => 'D1', 'title' => 'Team für Wunstorf-Fall festlegen', 'description' => 'Qualität, Lager und Versand ergänzen.', 'responsible_user_id' => $wunQuality, 'due_date' => loc_demo_date(4), 'status' => 'open', 'created_by' => $adminId, 'created_at' => loc_demo_datetime(-1, '08:25:00')],
                    ['step_key' => 'D3', 'title' => 'Restbestand in Wunstorf prüfen', 'description' => 'Restbestand am Standort Wunstorf prüfen.', 'responsible_user_id' => $wunLager, 'due_date' => loc_demo_date(5), 'status' => 'open', 'created_by' => $adminId, 'created_at' => loc_demo_datetime(-1, '09:15:00')],
                ],
                'files' => [['original_name' => 'wun_demo_stossfaenger.svg', 'file_path' => 'demo_locations/wun-demo-0001.svg', 'mime_type' => 'image/svg+xml', 'uploaded_by' => $adminId, 'created_at' => loc_demo_datetime(-1, '08:45:00'), 'content' => loc_demo_svg('Wunstorf Demo', 'Stoßfänger · grüner Fall', '#0d6efd')]],
            ],
            [
                'claim' => [
                    'claim_number' => 'WUN-DEMO-0002', 'standort_id' => $wunId, 'claim_type' => 'internal', 'partner_name' => 'Interne Qualität Wunstorf',
                    'article_number' => 'GT14488', 'article_name' => 'Kartonverpackung Set links', 'quantity_affected' => 72,
                    'delivery_date' => loc_demo_date(-14), 'claim_date' => loc_demo_date(-13), 'priority' => 'critical', 'status' => 'overdue',
                    'short_description' => 'Falsche Etiketten auf Kartons',
                    'problem_description' => 'Roter Wunstorf-Fall mit D5 aktiv und überfälliger Maßnahme. Gut zum Prüfen der roten Badge-Anzeige.',
                    'responsible_user_id' => $wunQuality, 'source_module' => 'warenausgang', 'source_number' => 'WA-WUN-DEMO-3301', 'source_url' => '/workbench/warenausgang.php?ausgang=3301', 'created_by' => $adminId, 'created_at' => loc_demo_datetime(-13, '07:50:00'),
                ],
                'steps' => ['D1' => 'done', 'D2' => 'done', 'D3' => 'done', 'D4' => 'done', 'D5' => 'in_progress'],
                'contents' => [
                    'D1' => 'Team: Qualität Wunstorf, Lager Wunstorf, Etikettierung und Schichtleitung.',
                    'D2' => '72 Kartons mit falscher Sachnummer GT14488 statt GT14491 etikettiert.',
                    'D3' => 'Betroffene Kartons gesperrt und Versandfreigabe gestoppt.',
                    'D4' => 'Hauptursache: alte Etikettenvorlagen waren nicht gesperrt.',
                    'D5' => 'Korrekturmaßnahmen: Vorlagen sperren, Vier-Augen-Prüfung einführen.',
                ],
                'actions' => [
                    ['step_key' => 'D5', 'title' => 'Alte Etikettenvorlagen sperren', 'description' => 'Nicht mehr gültige Vorlagen aus dem Drucksystem entfernen.', 'responsible_user_id' => $wunQuality, 'due_date' => loc_demo_date(-1), 'status' => 'open', 'created_by' => $adminId, 'created_at' => loc_demo_datetime(-12, '09:10:00')],
                    ['step_key' => 'D3', 'title' => 'Kartons sperren', 'description' => 'Kartons in Sperrfläche bringen.', 'responsible_user_id' => $wunLager, 'due_date' => loc_demo_date(-12), 'status' => 'done', 'completed_at' => loc_demo_datetime(-12, '12:00:00'), 'created_by' => $adminId, 'created_at' => loc_demo_datetime(-13, '08:00:00')],
                ],
                'files' => [['original_name' => 'wun_demo_etikett.svg', 'file_path' => 'demo_locations/wun-demo-0002.svg', 'mime_type' => 'image/svg+xml', 'uploaded_by' => $wunQuality, 'created_at' => loc_demo_datetime(-12, '09:00:00'), 'content' => loc_demo_svg('Wunstorf Demo', 'Falsches Etikett · roter Fall', '#dc3545')]],
            ],
            [
                'claim' => [
                    'claim_number' => 'WUN-DEMO-0003', 'standort_id' => $wunId, 'claim_type' => 'supplier', 'partner_name' => 'Schmidt Metalltechnik KG',
                    'article_number' => '111925/2', 'article_name' => 'Metallrahmen links', 'quantity_affected' => 6,
                    'delivery_date' => loc_demo_date(-30), 'claim_date' => loc_demo_date(-29), 'priority' => 'low', 'status' => 'closed',
                    'short_description' => 'Leichte Verformung an Metallrahmen',
                    'problem_description' => 'Abgeschlossener Wunstorf-Fall mit 100% 8D-Fortschritt. Gut für Bericht/PDF und erledigte Accordions.',
                    'responsible_user_id' => $wunQuality, 'source_module' => 'wareneingang', 'source_number' => 'WE-WUN-DEMO-2099', 'source_url' => '/workbench/wareneingang.php?eingang=2099', 'created_by' => $adminId, 'closed_by' => $wunQuality, 'closed_at' => loc_demo_datetime(-3, '14:00:00'), 'created_at' => loc_demo_datetime(-29, '08:00:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'done','D4'=>'done','D5'=>'done','D6'=>'done','D7'=>'done','D8'=>'done'],
                'contents' => ['D1'=>'Team gebildet.','D2'=>'6 Metallrahmen mit leichter Verformung.','D3'=>'Ware gesperrt, Lieferant informiert.','D4'=>'Ursache: Zwischenlage im Transportgestell fehlte.','D5'=>'Zusätzliche Zwischenlage verpflichtend.','D6'=>'Lieferant hat Verpackung angepasst.','D7'=>'Verpackungsvorschrift hinterlegt.','D8'=>'Wirksamkeit geprüft, Fall abgeschlossen.'],
                'actions' => [
                    ['step_key'=>'D8','title'=>'Fall abschließen','description'=>'Wirksamkeit bestätigen und Bericht erzeugen.','responsible_user_id'=>$wunQuality,'due_date'=>loc_demo_date(-3),'status'=>'done','completed_at'=>loc_demo_datetime(-3,'14:00:00'),'created_by'=>$adminId,'created_at'=>loc_demo_datetime(-4,'11:00:00')],
                ],
                'files' => [['original_name'=>'wun_demo_abschluss.txt','file_path'=>'demo_locations/wun-demo-0003.txt','mime_type'=>'text/plain','uploaded_by'=>$wunQuality,'created_at'=>loc_demo_datetime(-3,'14:05:00'),'content'=>"Abschlussnotiz Wunstorf Demo\nWirksamkeit geprüft.\n"]],
            ],
            [
                'claim' => [
                    'claim_number' => 'HAN-DEMO-0001', 'standort_id' => $hanId, 'claim_type' => 'customer', 'partner_name' => 'Audi Ersatzteillogistik',
                    'article_number' => 'DB0011', 'article_name' => 'Kleinteile-Behälter', 'quantity_affected' => 12,
                    'delivery_date' => loc_demo_date(-8), 'claim_date' => loc_demo_date(-7), 'priority' => 'medium', 'status' => 'in_progress',
                    'short_description' => 'Mengenabweichung im Warenausgang Hannover',
                    'problem_description' => 'Hannover-Fall mit D3 aktiv und gelben Maßnahmen. Gut zum Prüfen des Standortfilters Hannover.',
                    'responsible_user_id' => $hanQuality, 'source_module' => 'kommi', 'source_number' => 'KOM-HAN-DEMO-014', 'source_url' => '/workbench/kommi/order.php?id=14', 'created_by' => $hanDispo, 'created_at' => loc_demo_datetime(-7, '12:00:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'in_progress'],
                'contents' => ['D1'=>'Team: Hannover Qualität, Hannover Dispo, Kunde.','D2'=>'Sollmenge 120 Stück, angekommen 108 Stück.','D3'=>'Nachlieferung vorbereitet, Bestand wird geprüft.'],
                'actions' => [
                    ['step_key'=>'D3','title'=>'Bestand Hannover prüfen','description'=>'Bestand gegen Ausgang KOM-HAN-DEMO-014 prüfen.','responsible_user_id'=>$hanDispo,'due_date'=>loc_demo_date(2),'status'=>'in_progress','created_by'=>$hanQuality,'created_at'=>loc_demo_datetime(-7,'13:00:00')],
                    ['step_key'=>'D3','title'=>'Kunde informieren','description'=>'Zwischenstand an Audi melden.','responsible_user_id'=>$hanQuality,'due_date'=>loc_demo_date(0),'status'=>'open','created_by'=>$hanDispo,'created_at'=>loc_demo_datetime(-6,'09:30:00')],
                ],
                'files' => [['original_name'=>'han_demo_menge.txt','file_path'=>'demo_locations/han-demo-0001.txt','mime_type'=>'text/plain','uploaded_by'=>$hanDispo,'created_at'=>loc_demo_datetime(-6,'10:00:00'),'content'=>"Hannover Demo\nMengenabweichung dokumentiert.\n"]],
            ],
            [
                'claim' => [
                    'claim_number' => 'HAN-DEMO-0002', 'standort_id' => $hanId, 'claim_type' => 'supplier', 'partner_name' => 'Müller Verpackungen GmbH',
                    'article_number' => 'VW0012', 'article_name' => 'Mehrwegbehälter 1200x1000', 'quantity_affected' => 18,
                    'delivery_date' => loc_demo_date(-15), 'claim_date' => loc_demo_date(-14), 'priority' => 'high', 'status' => 'overdue',
                    'short_description' => 'Beschädigte Deckel im Wareneingang Hannover',
                    'problem_description' => 'Roter Hannover-Fall mit überschrittener Frist und D4 aktiv.',
                    'responsible_user_id' => $hanQuality, 'source_module' => 'wareneingang', 'source_number' => 'WE-HAN-DEMO-1055', 'source_url' => '/workbench/wareneingang.php?eingang=1055', 'created_by' => $hanQuality, 'created_at' => loc_demo_datetime(-14, '10:10:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'done','D4'=>'in_progress'],
                'contents' => ['D1'=>'Team Hannover festgelegt.','D2'=>'18 Behälter mit beschädigten Deckeln im Wareneingang.','D3'=>'Behälter separiert und Lieferant informiert.','D4'=>'Ursachenanalyse läuft: Verpackung und Transportweg werden geprüft.'],
                'actions' => [
                    ['step_key'=>'D4','title'=>'Transportweg beim Lieferanten prüfen','description'=>'Ursache für beschädigte Deckel klären.','responsible_user_id'=>$hanQuality,'due_date'=>loc_demo_date(-2),'status'=>'open','created_by'=>$hanQuality,'created_at'=>loc_demo_datetime(-12,'14:30:00')],
                ],
                'files' => [['original_name'=>'han_demo_deckel.svg','file_path'=>'demo_locations/han-demo-0002.svg','mime_type'=>'image/svg+xml','uploaded_by'=>$hanQuality,'created_at'=>loc_demo_datetime(-12,'15:00:00'),'content'=>loc_demo_svg('Hannover Demo','Beschädigte Deckel · rot','#dc3545')]],
            ],
            [
                'claim' => [
                    'claim_number' => 'FRA-DEMO-0001', 'standort_id' => $fraId, 'claim_type' => 'customer', 'partner_name' => 'Porsche Teilezentrum',
                    'article_number' => 'FRA-7001', 'article_name' => 'Kabelsatz Verpackungseinheit', 'quantity_affected' => 9,
                    'delivery_date' => loc_demo_date(-1), 'claim_date' => loc_demo_date(0), 'priority' => 'medium', 'status' => 'new',
                    'short_description' => 'Frankfurt: Verpackungseinheit unvollständig',
                    'problem_description' => 'Frischer Frankfurt-Fall. Gut zum Testen, ob Frankfurt im Standortfilter eigene Reklamationen bekommt.',
                    'responsible_user_id' => $fraQuality, 'source_module' => 'warenausgang', 'source_number' => 'WA-FRA-DEMO-7710', 'source_url' => '/workbench/warenausgang.php?ausgang=7710', 'created_by' => $adminId, 'created_at' => loc_demo_datetime(0, '08:40:00'),
                ],
                'steps' => ['D1'=>'in_progress'],
                'contents' => ['D1'=>'Team Frankfurt wird gebildet: Qualität, Lager und Dispo.'],
                'actions' => [
                    ['step_key'=>'D1','title'=>'Frankfurt-Team bestimmen','description'=>'Beteiligte am Standort Frankfurt eintragen.','responsible_user_id'=>$fraQuality,'due_date'=>loc_demo_date(5),'status'=>'open','created_by'=>$adminId,'created_at'=>loc_demo_datetime(0,'09:00:00')],
                    ['step_key'=>'D3','title'=>'Kabelsatz-Bestand prüfen','description'=>'Restbestand Frankfurt prüfen.','responsible_user_id'=>$fraLager,'due_date'=>loc_demo_date(4),'status'=>'open','created_by'=>$adminId,'created_at'=>loc_demo_datetime(0,'09:15:00')],
                ],
                'files' => [['original_name'=>'fra_demo_verpackung.svg','file_path'=>'demo_locations/fra-demo-0001.svg','mime_type'=>'image/svg+xml','uploaded_by'=>$adminId,'created_at'=>loc_demo_datetime(0,'09:30:00'),'content'=>loc_demo_svg('Frankfurt Demo','Neue Reklamation · grün','#198754')]],
            ],
            [
                'claim' => [
                    'claim_number' => 'FRA-DEMO-0002', 'standort_id' => $fraId, 'claim_type' => 'internal', 'partner_name' => 'Interne Qualität Frankfurt',
                    'article_number' => 'FRA-9150', 'article_name' => 'Kartonage Export', 'quantity_affected' => 44,
                    'delivery_date' => loc_demo_date(-10), 'claim_date' => loc_demo_date(-9), 'priority' => 'high', 'status' => 'in_progress',
                    'short_description' => 'Frankfurt: falsche Export-Kartonage',
                    'problem_description' => 'Gelber Frankfurt-Fall mit D6 aktiv. Gut zum Prüfen von Accordion-Status und Maßnahmen-Badge.',
                    'responsible_user_id' => $fraQuality, 'source_module' => 'warenausgang', 'source_number' => 'WA-FRA-DEMO-8820', 'source_url' => '/workbench/warenausgang.php?ausgang=8820', 'created_by' => $fraQuality, 'created_at' => loc_demo_datetime(-9, '07:30:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'done','D4'=>'done','D5'=>'done','D6'=>'in_progress'],
                'contents' => ['D1'=>'Team Frankfurt Export gebildet.','D2'=>'44 Packstücke mit falscher Export-Kartonage.','D3'=>'Versand gestoppt, Ware separiert.','D4'=>'Ursache: falsche Verpackungsgruppe im Stammdatensatz.','D5'=>'Stammdaten korrigieren und Prüfpunkt ergänzen.','D6'=>'Umsetzung läuft: Stammdatenänderung wird geprüft.'],
                'actions' => [
                    ['step_key'=>'D6','title'=>'Stammdaten Frankfurt korrigieren','description'=>'Verpackungsgruppe im Artikelstamm korrigieren.','responsible_user_id'=>$fraQuality,'due_date'=>loc_demo_date(1),'status'=>'in_progress','created_by'=>$fraQuality,'created_at'=>loc_demo_datetime(-7,'11:10:00')],
                    ['step_key'=>'D7','title'=>'Prüfpunkt Export ergänzen','description'=>'Checkliste für Exportkartonage ergänzen.','responsible_user_id'=>$fraLager,'due_date'=>loc_demo_date(2),'status'=>'open','created_by'=>$fraQuality,'created_at'=>loc_demo_datetime(-6,'13:00:00')],
                ],
                'files' => [['original_name'=>'fra_demo_export.txt','file_path'=>'demo_locations/fra-demo-0002.txt','mime_type'=>'text/plain','uploaded_by'=>$fraQuality,'created_at'=>loc_demo_datetime(-6,'13:20:00'),'content'=>"Frankfurt Demo\nExport-Kartonage geprüft.\n"]],
            ],
            [
                'claim' => [
                    'claim_number' => 'FRA-DEMO-0003', 'standort_id' => $fraId, 'claim_type' => 'supplier', 'partner_name' => 'Rhein-Main Verpackung GmbH',
                    'article_number' => 'FRA-1200', 'article_name' => 'KLT Einsatz', 'quantity_affected' => 25,
                    'delivery_date' => loc_demo_date(-20), 'claim_date' => loc_demo_date(-19), 'priority' => 'critical', 'status' => 'overdue',
                    'short_description' => 'Frankfurt: KLT-Einsätze beschädigt',
                    'problem_description' => 'Roter Frankfurt-Fall mit überfälliger Maßnahme. Gut zum Prüfen, ob rote Maßnahmen in der Nav nur beim passenden Benutzer zählen.',
                    'responsible_user_id' => $fraQuality, 'source_module' => 'wareneingang', 'source_number' => 'WE-FRA-DEMO-1440', 'source_url' => '/workbench/wareneingang.php?eingang=1440', 'created_by' => $fraLager, 'created_at' => loc_demo_datetime(-19, '06:55:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'done','D4'=>'in_progress'],
                'contents' => ['D1'=>'Team Frankfurt und Lieferant gebildet.','D2'=>'25 KLT-Einsätze beschädigt angeliefert.','D3'=>'Ware gesperrt und Ersatzlieferung angefragt.','D4'=>'Ursachenanalyse mit Lieferant läuft.'],
                'actions' => [
                    ['step_key'=>'D4','title'=>'Lieferant Frankfurt zur Ursache befragen','description'=>'Stellungnahme vom Lieferanten einholen.','responsible_user_id'=>$fraQuality,'due_date'=>loc_demo_date(-4),'status'=>'open','created_by'=>$fraLager,'created_at'=>loc_demo_datetime(-18,'08:30:00')],
                    ['step_key'=>'D3','title'=>'Beschädigte KLT separieren','description'=>'KLT-Einsätze in Sperrfläche Frankfurt abstellen.','responsible_user_id'=>$fraLager,'due_date'=>loc_demo_date(-18),'status'=>'done','completed_at'=>loc_demo_datetime(-18,'12:00:00'),'created_by'=>$fraLager,'created_at'=>loc_demo_datetime(-19,'07:20:00')],
                ],
                'files' => [['original_name'=>'fra_demo_klt.svg','file_path'=>'demo_locations/fra-demo-0003.svg','mime_type'=>'image/svg+xml','uploaded_by'=>$fraLager,'created_at'=>loc_demo_datetime(-18,'08:00:00'),'content'=>loc_demo_svg('Frankfurt Demo','Beschädigte KLT · rot','#dc3545')]],
            ],
        ];

        foreach ($cases as $case) {
            $createdClaims[] = loc_demo_insert_claim($case['claim'], $case['steps'], $case['contents'], $case['actions'], $case['files']);
        }

        $db->commit();
        flash('success', '8 Standort-Demo-Reklamationen wurden angelegt: WUN, HAN und FRA. Vorhandene Standort-Demos wurden vorher ersetzt.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
        flash('danger', 'Standort-Demo-Daten konnten nicht angelegt werden: ' . $error);
    }
}

require __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <div class="text-muted small">Admin</div>
        <h1 class="h3 fw-bold mb-1">Standort-Demo-Daten anlegen</h1>
        <div class="text-muted">Legt Demo-Reklamationen für Wunstorf, Hannover und Frankfurt an.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="locations.php" class="btn btn-outline-secondary">Standorte</a>
        <a href="claims.php" class="btn btn-outline-secondary">Reklamationen</a>
    </div>
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card h-100 border-primary-subtle">
            <div class="card-body">
                <h2 class="h5 fw-bold">Wunstorf</h2>
                <p class="text-muted mb-2">3 Fälle: frisch/grün, rot/überfällig, abgeschlossen.</p>
                <span class="badge bg-primary-subtle text-primary-emphasis">WUN-DEMO</span>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100 border-info-subtle">
            <div class="card-body">
                <h2 class="h5 fw-bold">Hannover</h2>
                <p class="text-muted mb-2">2 Fälle: gelbe Maßnahmen und roter Wareneingang.</p>
                <span class="badge bg-info-subtle text-info-emphasis">HAN-DEMO</span>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100 border-success-subtle">
            <div class="card-body">
                <h2 class="h5 fw-bold">Frankfurt</h2>
                <p class="text-muted mb-2">3 Fälle: neu, D6 aktiv, rot/überfällig.</p>
                <span class="badge bg-success-subtle text-success-emphasis">FRA-DEMO</span>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-warning">
    Beim Import werden nur Standort-Demo-Fälle mit <strong>WUN-DEMO-</strong>, <strong>HAN-DEMO-</strong> und <strong>FRA-DEMO-</strong> ersetzt. Deine echten Reklamationen bleiben unangetastet.
</div>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error): ?>
    <div class="card">
        <div class="card-body">
            <h2 class="h5 fw-bold">Standort-Demos wurden angelegt</h2>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a class="btn btn-primary" href="claims.php?q=DEMO">Alle Demo-Reklamationen anzeigen</a>
                <a class="btn btn-outline-primary" href="claims.php?q=WUN-DEMO">Wunstorf-Demos</a>
                <a class="btn btn-outline-primary" href="claims.php?q=HAN-DEMO">Hannover-Demos</a>
                <a class="btn btn-outline-primary" href="claims.php?q=FRA-DEMO">Frankfurt-Demos</a>
                <a class="btn btn-outline-primary" href="my_actions.php">Meine Maßnahmen prüfen</a>
            </div>
            <div class="alert alert-info mt-4 mb-0">
                Demo-Benutzer wurden angelegt. Passwort: <strong>Demo12345!</strong><br>
                Beispiele: demo.wun.qualitaet@example.com, demo.han.qualitaet@example.com, demo.fra.qualitaet@example.com
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="post" class="card">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="fw-bold">Standort-Demo-Daten jetzt anlegen</div>
                <div class="text-muted small">Nur Admins können diese Aktion ausführen.</div>
            </div>
            <div>
                <?= csrf_field() ?>
                <button class="btn btn-success btn-lg" data-confirm="Standort-Demo-Daten anlegen? Vorhandene WUN/HAN/FRA-DEMO-Fälle werden ersetzt.">Standort-Demos anlegen</button>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
