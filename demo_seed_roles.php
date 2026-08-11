<?php
require_once __DIR__ . '/auth.php';
require_admin();

$db = pdo();
$error = null;
$created = [];

function rbac_demo_date(int $daysOffset = 0): string
{
    return (new DateTimeImmutable('today'))->modify(($daysOffset >= 0 ? '+' : '') . $daysOffset . ' days')->format('Y-m-d');
}

function rbac_demo_datetime(int $daysOffset = 0, string $time = '09:00:00'): string
{
    return rbac_demo_date($daysOffset) . ' ' . $time;
}

function rbac_demo_add_minutes(string $dateTime, int $minutes): string
{
    return (new DateTimeImmutable($dateTime))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
}

function rbac_demo_location(string $kuerzel, string $name, string $address): int
{
    $stmt = pdo()->prepare('SELECT id FROM standorte WHERE kuerzel = ? LIMIT 1');
    $stmt->execute([$kuerzel]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $upd = pdo()->prepare('UPDATE standorte SET name = ?, adresse = ?, aktiv = 1 WHERE id = ?');
        $upd->execute([$name, $address, (int)$id]);
        return (int)$id;
    }

    $ins = pdo()->prepare('INSERT INTO standorte (name, kuerzel, adresse, aktiv) VALUES (?, ?, ?, 1)');
    $ins->execute([$name, $kuerzel, $address]);
    return (int)pdo()->lastInsertId();
}

function rbac_demo_user(string $email, string $name, string $role): int
{
    $stmt = pdo()->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $id = $stmt->fetchColumn();
    if ($id) {
        $upd = pdo()->prepare('UPDATE users SET name = ?, role = ?, active = 1 WHERE id = ?');
        $upd->execute([$name, $role, (int)$id]);
        return (int)$id;
    }

    $hash = password_hash('Demo12345!', PASSWORD_DEFAULT);
    $ins = pdo()->prepare('INSERT INTO users (name, email, password_hash, role, active) VALUES (?, ?, ?, ?, 1)');
    $ins->execute([$name, $email, $hash, $role]);
    return (int)pdo()->lastInsertId();
}

function rbac_demo_assign(int $userId, int $locationId, string $standortRole, bool $default = false): void
{
    if (!db_table_exists('user_standorte')) {
        throw new RuntimeException('Tabelle user_standorte fehlt. Bitte zuerst die Standort-Migration ausführen.');
    }

    if ($default) {
        $stmt = pdo()->prepare('UPDATE user_standorte SET is_default = 0 WHERE user_id = ?');
        $stmt->execute([$userId]);
    }

    if (db_column_exists('user_standorte', 'standort_role')) {
        $stmt = pdo()->prepare('INSERT INTO user_standorte (user_id, standort_id, standort_role, is_default)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE standort_role = VALUES(standort_role), is_default = VALUES(is_default)');
        $stmt->execute([$userId, $locationId, $standortRole, $default ? 1 : 0]);
        return;
    }

    $stmt = pdo()->prepare('INSERT INTO user_standorte (user_id, standort_id, is_default)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE is_default = VALUES(is_default)');
    $stmt->execute([$userId, $locationId, $default ? 1 : 0]);
}

function rbac_demo_excerpt(?string $text, int $max = 180): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s+/', ' ', $text) ?: $text;
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

function rbac_demo_svg(string $title, string $subtitle, string $color): string
{
    $title = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $subtitle = htmlspecialchars($subtitle, ENT_QUOTES, 'UTF-8');
    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" width="1200" height="750" viewBox="0 0 1200 750">
  <rect width="1200" height="750" fill="#f8f9fa"/>
  <rect x="70" y="70" width="1060" height="610" rx="28" fill="#ffffff" stroke="#dee2e6" stroke-width="8"/>
  <circle cx="190" cy="190" r="72" fill="{$color}" opacity="0.92"/>
  <rect x="300" y="145" width="640" height="52" rx="12" fill="#adb5bd" opacity="0.55"/>
  <rect x="300" y="235" width="790" height="38" rx="10" fill="#ced4da" opacity="0.82"/>
  <rect x="300" y="302" width="540" height="38" rx="10" fill="#ced4da" opacity="0.68"/>
  <rect x="130" y="430" width="940" height="150" rx="18" fill="#e9ecef" stroke="#ced4da" stroke-width="4"/>
  <text x="130" y="645" font-family="Arial, sans-serif" font-size="44" font-weight="700" fill="#212529">{$title}</text>
  <text x="130" y="700" font-family="Arial, sans-serif" font-size="28" fill="#495057">{$subtitle}</text>
</svg>
SVG;
}

function rbac_demo_file(string $relativePath, string $content): array
{
    $path = APP_UPLOAD_DIR . '/' . $relativePath;
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Demo-Uploadordner konnte nicht erstellt werden: ' . $dir);
    }
    file_put_contents($path, $content);
    return [$path, filesize($path) ?: strlen($content)];
}

function rbac_demo_step_action(string $stepKey, string $status): string
{
    $definition = claim_step_definitions()[$stepKey] ?? ['title' => $stepKey];
    $title = $definition['title'] ?? $stepKey;
    return match ($status) {
        'done' => $stepKey . ' ' . $title . ' abgeschlossen',
        'in_progress' => $stepKey . ' ' . $title . ' gestartet',
        default => $stepKey . ' ' . $title . ' geöffnet',
    };
}

function rbac_demo_status_text(string $status): string
{
    return status_label($status);
}

function rbac_demo_history(array $claim, array $stepStatuses, array $stepContents, array $actions, array $files): array
{
    $history = [];
    $createdAt = (string)$claim['created_at'];
    $creatorId = (int)$claim['created_by'];
    $responsibleId = (int)($claim['responsible_user_id'] ?: $creatorId);

    $history[] = [
        'user_id' => $creatorId,
        'action' => 'Reklamation erstellt',
        'details' => 'Rollen-/Berechtigungs-Demo: ' . $claim['claim_number'] . "\nQuelle: " . (($claim['source_module'] ?? '-') ?: '-') . ' ' . (($claim['source_number'] ?? '') ?: ''),
        'created_at' => $createdAt,
    ];

    $minute = 20;
    foreach (claim_step_definitions() as $key => $definition) {
        $status = $stepStatuses[$key] ?? 'open';
        if ($status === 'open') {
            continue;
        }
        $history[] = [
            'user_id' => $responsibleId,
            'action' => rbac_demo_step_action($key, $status),
            'details' => rbac_demo_excerpt($stepContents[$key] ?? ''),
            'created_at' => rbac_demo_add_minutes($createdAt, $minute),
        ];
        $minute += 35;
    }

    foreach ($actions as $action) {
        $history[] = [
            'user_id' => (int)$action['created_by'],
            'action' => 'Maßnahme erstellt',
            'details' => 'D-Schritt: ' . $action['step_key'] . "\nMaßnahme: " . $action['title'] . "\nFrist: " . ($action['due_date'] ?? 'keine Frist') . "\nStatus: " . rbac_demo_status_text((string)($action['status'] ?? 'open')),
            'created_at' => (string)$action['created_at'],
        ];
        if (($action['status'] ?? '') === 'done') {
            $history[] = [
                'user_id' => (int)($action['responsible_user_id'] ?? $action['created_by']),
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
            'details' => 'Fallstatus: ' . rbac_demo_status_text((string)$claim['status']),
            'created_at' => (string)($claim['closed_at'] ?? rbac_demo_add_minutes($createdAt, 420)),
        ];
    }

    usort($history, static fn(array $a, array $b): int => strcmp((string)$a['created_at'], (string)$b['created_at']));
    return $history;
}

function rbac_demo_insert_claim(array $claim, array $stepStatuses, array $stepContents, array $actions, array $files): int
{
    $stmt = pdo()->prepare('INSERT INTO claims (claim_number, standort_id, claim_type, partner_name, article_number, article_name, quantity_affected, delivery_date, claim_date, priority, status, short_description, problem_description, responsible_user_id, source_module, source_number, source_url, created_by, closed_by, closed_at, created_at)
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
    $claimId = (int)pdo()->lastInsertId();

    foreach (claim_step_definitions() as $key => $definition) {
        $status = $stepStatuses[$key] ?? 'open';
        $content = $stepContents[$key] ?? null;
        $completedBy = $status === 'done' ? ($claim['responsible_user_id'] ?: $claim['created_by']) : null;
        $completedAt = $status === 'done' ? rbac_demo_add_minutes((string)$claim['created_at'], 170) : null;
        $stmt = pdo()->prepare('INSERT INTO claim_steps (claim_id, step_key, title, description, content, status, completed_by, completed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([$claimId, $key, $definition['title'], $definition['description'], $content, $status, $completedBy, $completedAt, $claim['created_at']]);
    }

    foreach ($actions as $action) {
        $stmt = pdo()->prepare('INSERT INTO claim_actions (claim_id, step_key, title, description, responsible_user_id, due_date, status, completed_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
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

    $fileMeta = db_column_exists('claim_files', 'step_key') && db_column_exists('claim_files', 'category') && db_column_exists('claim_files', 'caption');
    foreach ($files as $file) {
        [$path, $size] = rbac_demo_file((string)$file['file_path'], (string)$file['content']);
        if ($fileMeta) {
            $stmt = pdo()->prepare('INSERT INTO claim_files (claim_id, step_key, category, caption, original_name, stored_name, file_path, mime_type, size_bytes, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([
                $claimId,
                $file['step_key'] ?? null,
                $file['category'] ?? 'other',
                $file['caption'] ?? null,
                $file['original_name'],
                basename((string)$file['file_path']),
                $file['file_path'],
                $file['mime_type'] ?? 'text/plain',
                $size,
                $file['uploaded_by'],
                $file['created_at'],
            ]);
        } else {
            $stmt = pdo()->prepare('INSERT INTO claim_files (claim_id, original_name, stored_name, file_path, mime_type, size_bytes, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
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
    }

    foreach (rbac_demo_history($claim, $stepStatuses, $stepContents, $actions, $files) as $event) {
        $stmt = pdo()->prepare('INSERT INTO claim_history (claim_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$claimId, $event['user_id'], $event['action'], $event['details'], $event['created_at']]);
    }

    return $claimId;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    if (!locations_enabled()) {
        flash('danger', 'Mehrstandort-Funktion ist noch nicht aktiv. Bitte erst run_location_migration.php ausführen.');
        redirect('demo_seed_roles.php');
    }

    try {
        $db->beginTransaction();

        $wunId = rbac_demo_location('WUN', 'Wunstorf', 'Wunstorf');
        $hanId = rbac_demo_location('HAN', 'Hannover', 'Hannover');
        $fraId = rbac_demo_location('FRA', 'Frankfurt', 'Am Prime-Parc 17, 65479 Raunheim');

        $adminAll = rbac_demo_user('demo.rbac.admin@example.com', 'Demo Admin Alle Standorte', 'admin');
        $wunLead = rbac_demo_user('demo.rbac.wun.leitung@example.com', 'Demo Wunstorf Standortleitung', 'quality');
        $wunLager = rbac_demo_user('demo.rbac.wun.lager@example.com', 'Demo Wunstorf Lager', 'employee');
        $hanQuality = rbac_demo_user('demo.rbac.han.qualitaet@example.com', 'Demo Hannover Qualität', 'quality');
        $hanDispo = rbac_demo_user('demo.rbac.han.dispo@example.com', 'Demo Hannover Dispo', 'employee');
        $fraQuality = rbac_demo_user('demo.rbac.fra.qualitaet@example.com', 'Demo Frankfurt Qualität', 'quality');
        $fraViewer = rbac_demo_user('demo.rbac.fra.leser@example.com', 'Demo Frankfurt Leser', 'viewer');

        foreach ([$wunId, $hanId, $fraId] as $idx => $locId) {
            rbac_demo_assign($adminAll, $locId, 'admin', $idx === 0);
        }
        rbac_demo_assign($wunLead, $wunId, 'standortleiter', true);
        rbac_demo_assign($wunLager, $wunId, 'employee', true);
        rbac_demo_assign($hanQuality, $hanId, 'quality', true);
        rbac_demo_assign($hanQuality, $fraId, 'quality', false);
        rbac_demo_assign($hanDispo, $hanId, 'employee', true);
        rbac_demo_assign($fraQuality, $fraId, 'quality', true);
        rbac_demo_assign($fraViewer, $fraId, 'viewer', true);

        $deleteStmt = $db->prepare("DELETE FROM claims WHERE claim_number LIKE 'WUN-RBAC-%' OR claim_number LIKE 'HAN-RBAC-%' OR claim_number LIKE 'FRA-RBAC-%'");
        $deleteStmt->execute();

        $cases = [
            [
                'claim' => [
                    'claim_number' => 'WUN-RBAC-0001', 'standort_id' => $wunId, 'claim_type' => 'customer', 'partner_name' => 'VW Nutzfahrzeuge',
                    'article_number' => 'WUN-4451', 'article_name' => 'Stoßfänger links', 'quantity_affected' => 18,
                    'delivery_date' => rbac_demo_date(-4), 'claim_date' => rbac_demo_date(-3), 'priority' => 'high', 'status' => 'in_progress',
                    'short_description' => 'Wunstorf: Kratzer an Stoßfängern',
                    'problem_description' => 'Kundenreklamation aus Wunstorf. D4 ist aktiv, eine offene Maßnahme liegt beim Lager. Gut zum Testen der Standortleitung und Mitarbeiterrolle.',
                    'responsible_user_id' => $wunLead, 'source_module' => 'warenausgang', 'source_number' => 'WA-WUN-RBAC-2828', 'source_url' => '/workbench/warenausgang.php?ausgang=2828', 'created_by' => $wunLead, 'created_at' => rbac_demo_datetime(-3, '08:15:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'done','D4'=>'in_progress'],
                'contents' => ['D1'=>'Team: Standortleitung Wunstorf, Lager Wunstorf, Qualität.','D2'=>'18 Stoßfänger mit sichtbaren Kratzern in zwei Behältern.','D3'=>'Bestand gesperrt, Kunde informiert, Fotos gesichert.','D4'=>'Ursache wird geprüft: mögliche Beschädigung beim Umlagern.'],
                'actions' => [
                    ['step_key'=>'D3','title'=>'Sperrfläche Wunstorf prüfen','description'=>'Betroffene Behälter auf Sperrfläche WUN markieren.','responsible_user_id'=>$wunLager,'due_date'=>rbac_demo_date(2),'status'=>'open','created_by'=>$wunLead,'created_at'=>rbac_demo_datetime(-3,'10:00:00')],
                    ['step_key'=>'D4','title'=>'Umlagerprozess prüfen','description'=>'Prüfen, ob Kratzer beim Umlagern entstanden sind.','responsible_user_id'=>$wunLead,'due_date'=>rbac_demo_date(4),'status'=>'in_progress','created_by'=>$wunLead,'created_at'=>rbac_demo_datetime(-2,'09:30:00')],
                ],
                'files' => [['original_name'=>'wun_rbac_kratzer.svg','file_path'=>'demo_roles/wun-rbac-0001.svg','mime_type'=>'image/svg+xml','uploaded_by'=>$wunLager,'created_at'=>rbac_demo_datetime(-3,'10:25:00'),'step_key'=>'D2','category'=>'problem','caption'=>'Problemfoto: Kratzer am Bauteil','content'=>rbac_demo_svg('WUN RBAC 0001','Problemfoto · D2','#0d6efd')]],
            ],
            [
                'claim' => [
                    'claim_number' => 'WUN-RBAC-0002', 'standort_id' => $wunId, 'claim_type' => 'supplier', 'partner_name' => 'Lieferant Nord GmbH',
                    'article_number' => 'WUN-7700', 'article_name' => 'Kartonverpackung', 'quantity_affected' => 64,
                    'delivery_date' => rbac_demo_date(-18), 'claim_date' => rbac_demo_date(-17), 'priority' => 'critical', 'status' => 'overdue',
                    'short_description' => 'Wunstorf: Verpackung nass angeliefert',
                    'problem_description' => 'Kritischer Lieferantenfall. Rote Maßnahme ist überfällig und wird im Badge des zuständigen Benutzers sichtbar.',
                    'responsible_user_id' => $wunLead, 'source_module' => 'wareneingang', 'source_number' => 'WE-WUN-RBAC-5011', 'source_url' => '/workbench/wareneingang.php?eingang=5011', 'created_by' => $wunLager, 'created_at' => rbac_demo_datetime(-17, '07:40:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'done','D4'=>'done','D5'=>'in_progress'],
                'contents' => ['D1'=>'Team Wunstorf + Lieferant gebildet.','D2'=>'64 Verpackungen nass angeliefert.','D3'=>'Ware gesperrt und Ersatz angefragt.','D4'=>'Ursache: fehlende Folierung beim Lieferanten.','D5'=>'Korrekturmaßnahme: Verpackungsvorschrift aktualisieren.'],
                'actions' => [
                    ['step_key'=>'D5','title'=>'Lieferant zur Verpackungsvorschrift verpflichten','description'=>'Neue Verpackungsvorgabe schriftlich bestätigen lassen.','responsible_user_id'=>$wunLead,'due_date'=>rbac_demo_date(-5),'status'=>'open','created_by'=>$wunLead,'created_at'=>rbac_demo_datetime(-12,'11:00:00')],
                    ['step_key'=>'D3','title'=>'Nasse Kartons separieren','description'=>'Nasse Verpackungen separieren und dokumentieren.','responsible_user_id'=>$wunLager,'due_date'=>rbac_demo_date(-16),'status'=>'done','completed_at'=>rbac_demo_datetime(-16,'12:30:00'),'created_by'=>$wunLager,'created_at'=>rbac_demo_datetime(-17,'08:10:00')],
                ],
                'files' => [['original_name'=>'wun_rbac_nass.svg','file_path'=>'demo_roles/wun-rbac-0002.svg','mime_type'=>'image/svg+xml','uploaded_by'=>$wunLager,'created_at'=>rbac_demo_datetime(-17,'08:25:00'),'step_key'=>'D2','category'=>'problem','caption'=>'Nasse Verpackung im Wareneingang','content'=>rbac_demo_svg('WUN RBAC 0002','Rot/überfällig','#dc3545')]],
            ],
            [
                'claim' => [
                    'claim_number' => 'HAN-RBAC-0001', 'standort_id' => $hanId, 'claim_type' => 'internal', 'partner_name' => 'Interne Prüfung Hannover',
                    'article_number' => 'HAN-1033', 'article_name' => 'Labelsatz A', 'quantity_affected' => 12,
                    'delivery_date' => rbac_demo_date(-1), 'claim_date' => rbac_demo_date(0), 'priority' => 'medium', 'status' => 'new',
                    'short_description' => 'Hannover: Labelsatz unvollständig',
                    'problem_description' => 'Frischer Hannover-Fall mit grünen Maßnahmen. Qualität Hannover ist verantwortlich.',
                    'responsible_user_id' => $hanQuality, 'source_module' => 'interne_pruefung', 'source_number' => 'QP-HAN-RBAC-1001', 'source_url' => '/workbench/qualitaet.php?id=1001', 'created_by' => $hanDispo, 'created_at' => rbac_demo_datetime(0, '09:00:00'),
                ],
                'steps' => ['D1'=>'in_progress'],
                'contents' => ['D1'=>'Team wird durch Qualität Hannover zusammengestellt.'],
                'actions' => [
                    ['step_key'=>'D1','title'=>'Team Hannover festlegen','description'=>'Qualität, Dispo und Lager für den Fall eintragen.','responsible_user_id'=>$hanQuality,'due_date'=>rbac_demo_date(3),'status'=>'open','created_by'=>$hanDispo,'created_at'=>rbac_demo_datetime(0,'09:20:00')],
                ],
                'files' => [['original_name'=>'han_rbac_label.svg','file_path'=>'demo_roles/han-rbac-0001.svg','mime_type'=>'image/svg+xml','uploaded_by'=>$hanDispo,'created_at'=>rbac_demo_datetime(0,'09:40:00'),'step_key'=>'D2','category'=>'problem','caption'=>'Unvollständiger Labelsatz','content'=>rbac_demo_svg('HAN RBAC 0001','Neu/grün','#198754')]],
            ],
            [
                'claim' => [
                    'claim_number' => 'HAN-RBAC-0002', 'standort_id' => $hanId, 'claim_type' => 'customer', 'partner_name' => 'Kunde Hannover GmbH',
                    'article_number' => 'HAN-8842', 'article_name' => 'Versandbehälter', 'quantity_affected' => 30,
                    'delivery_date' => rbac_demo_date(-9), 'claim_date' => rbac_demo_date(-8), 'priority' => 'high', 'status' => 'waiting',
                    'short_description' => 'Hannover: Behälter falsch kommissioniert',
                    'problem_description' => 'Wartet-Fall. Disponent Hannover hat eine gelbe Maßnahme und soll seine Nav-Zahl sehen.',
                    'responsible_user_id' => $hanQuality, 'source_module' => 'kommi', 'source_number' => 'KOM-HAN-RBAC-3320', 'source_url' => '/workbench/kommi/order.php?id=3320', 'created_by' => $hanQuality, 'created_at' => rbac_demo_datetime(-8, '08:10:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'done','D4'=>'done','D5'=>'done','D6'=>'in_progress'],
                'contents' => ['D1'=>'Team Hannover gebildet.','D2'=>'30 Behälter wurden falsch kommissioniert.','D3'=>'Nachlieferung vorbereitet.','D4'=>'Ursache: falscher Kommi-Auftrag ausgewählt.','D5'=>'Korrektur: Plausibilitätscheck im Kommi-Prozess.','D6'=>'Umsetzung wartet auf Rückmeldung der Dispo.'],
                'actions' => [
                    ['step_key'=>'D6','title'=>'Kommi-Auftrag Hannover prüfen','description'=>'Prüfen, welcher Auftrag als Vorlage genutzt wurde.','responsible_user_id'=>$hanDispo,'due_date'=>rbac_demo_date(1),'status'=>'in_progress','created_by'=>$hanQuality,'created_at'=>rbac_demo_datetime(-6,'10:15:00')],
                    ['step_key'=>'D5','title'=>'Plausibilitätscheck definieren','description'=>'Regel für Quellauftrag definieren.','responsible_user_id'=>$hanQuality,'due_date'=>rbac_demo_date(-1),'status'=>'done','completed_at'=>rbac_demo_datetime(-1,'15:30:00'),'created_by'=>$hanQuality,'created_at'=>rbac_demo_datetime(-5,'12:00:00')],
                ],
                'files' => [['original_name'=>'han_rbac_kommi.txt','file_path'=>'demo_roles/han-rbac-0002.txt','mime_type'=>'text/plain','uploaded_by'=>$hanQuality,'created_at'=>rbac_demo_datetime(-5,'12:20:00'),'step_key'=>'D4','category'=>'cause','caption'=>'Notiz zur Ursachenanalyse','content'=>"HAN-RBAC-0002\nFalscher Kommi-Auftrag als Vorlage verwendet.\n"]],
            ],
            [
                'claim' => [
                    'claim_number' => 'FRA-RBAC-0001', 'standort_id' => $fraId, 'claim_type' => 'supplier', 'partner_name' => 'Rhein-Main Verpackung GmbH',
                    'article_number' => 'FRA-2400', 'article_name' => 'Schaumeinsatz', 'quantity_affected' => 25,
                    'delivery_date' => rbac_demo_date(-15), 'claim_date' => rbac_demo_date(-14), 'priority' => 'medium', 'status' => 'closed',
                    'short_description' => 'Frankfurt: Schaumeinsätze falsche Stärke',
                    'problem_description' => 'Abgeschlossener Frankfurt-Fall. Leser kann ihn sehen, aber nicht bearbeiten.',
                    'responsible_user_id' => $fraQuality, 'source_module' => 'wareneingang', 'source_number' => 'WE-FRA-RBAC-7070', 'source_url' => '/workbench/wareneingang.php?eingang=7070', 'created_by' => $fraQuality, 'closed_by' => $fraQuality, 'closed_at' => rbac_demo_datetime(-2, '16:00:00'), 'created_at' => rbac_demo_datetime(-14, '07:55:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'done','D4'=>'done','D5'=>'done','D6'=>'done','D7'=>'done','D8'=>'done'],
                'contents' => ['D1'=>'Team Frankfurt + Lieferant gebildet.','D2'=>'Schaumeinsätze mit falscher Materialstärke.','D3'=>'Bestand gesperrt, Ersatzlieferung bestellt.','D4'=>'Ursache: falsche Werkzeugfreigabe beim Lieferanten.','D5'=>'Freigabeprozess angepasst.','D6'=>'Lieferant hat Korrektur umgesetzt.','D7'=>'Wareneingangsprüfung ergänzt.','D8'=>'Wirksamkeit bestätigt und Fall abgeschlossen.'],
                'actions' => [
                    ['step_key'=>'D6','title'=>'Ersatzlieferung prüfen','description'=>'Ersatzlieferung prüfen und freigeben.','responsible_user_id'=>$fraQuality,'due_date'=>rbac_demo_date(-3),'status'=>'done','completed_at'=>rbac_demo_datetime(-3,'13:00:00'),'created_by'=>$fraQuality,'created_at'=>rbac_demo_datetime(-10,'09:30:00')],
                ],
                'files' => [['original_name'=>'fra_rbac_abschluss.svg','file_path'=>'demo_roles/fra-rbac-0001.svg','mime_type'=>'image/svg+xml','uploaded_by'=>$fraQuality,'created_at'=>rbac_demo_datetime(-2,'15:20:00'),'step_key'=>'D8','category'=>'proof','caption'=>'Nachweis Abschlussprüfung','content'=>rbac_demo_svg('FRA RBAC 0001','Abgeschlossen','#198754')]],
            ],
            [
                'claim' => [
                    'claim_number' => 'FRA-RBAC-0002', 'standort_id' => $fraId, 'claim_type' => 'customer', 'partner_name' => 'Kunde Rhein-Main AG',
                    'article_number' => 'FRA-9120', 'article_name' => 'Kabelsatz', 'quantity_affected' => 7,
                    'delivery_date' => rbac_demo_date(-11), 'claim_date' => rbac_demo_date(-10), 'priority' => 'critical', 'status' => 'overdue',
                    'short_description' => 'Frankfurt: Kabelsatz falsch verpackt',
                    'problem_description' => 'Kritischer Frankfurt-Fall. Eine Maßnahme ist absichtlich dem Leser zugewiesen, damit der Badge sichtbar ist, der Leser aber nicht bearbeiten darf.',
                    'responsible_user_id' => $fraQuality, 'source_module' => 'warenausgang', 'source_number' => 'WA-FRA-RBAC-8812', 'source_url' => '/workbench/warenausgang.php?ausgang=8812', 'created_by' => $fraQuality, 'created_at' => rbac_demo_datetime(-10, '07:10:00'),
                ],
                'steps' => ['D1'=>'done','D2'=>'done','D3'=>'in_progress'],
                'contents' => ['D1'=>'Team Frankfurt gebildet.','D2'=>'7 Kabelsätze falsch verpackt.','D3'=>'Sofortmaßnahme läuft: Bestand prüfen und Kunde informieren.'],
                'actions' => [
                    ['step_key'=>'D3','title'=>'Kunde über Ersatztermin informieren','description'=>'Leser-Test: Maßnahme sichtbar, aber ohne Bearbeitungsrechte.','responsible_user_id'=>$fraViewer,'due_date'=>rbac_demo_date(-2),'status'=>'open','created_by'=>$fraQuality,'created_at'=>rbac_demo_datetime(-9,'09:00:00')],
                    ['step_key'=>'D3','title'=>'Bestand Frankfurt prüfen','description'=>'Bestand auf falsch verpackte Kabelsätze prüfen.','responsible_user_id'=>$fraQuality,'due_date'=>rbac_demo_date(1),'status'=>'in_progress','created_by'=>$fraQuality,'created_at'=>rbac_demo_datetime(-9,'09:15:00')],
                ],
                'files' => [['original_name'=>'fra_rbac_kabel.svg','file_path'=>'demo_roles/fra-rbac-0002.svg','mime_type'=>'image/svg+xml','uploaded_by'=>$fraQuality,'created_at'=>rbac_demo_datetime(-9,'09:35:00'),'step_key'=>'D2','category'=>'problem','caption'=>'Falsch verpackter Kabelsatz','content'=>rbac_demo_svg('FRA RBAC 0002','Leser + rote Maßnahme','#dc3545')]],
            ],
        ];

        foreach ($cases as $case) {
            $created[] = rbac_demo_insert_claim($case['claim'], $case['steps'], $case['contents'], $case['actions'], $case['files']);
        }

        $db->commit();
        flash('success', '6 Rollen-/Berechtigungs-Demo-Reklamationen wurden angelegt. Passwort für Demo-Benutzer: Demo12345!');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
        flash('danger', 'Rollen-Demo konnte nicht angelegt werden: ' . $error);
    }
}

require __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <div class="text-muted small">Admin</div>
        <h1 class="h3 fw-bold mb-1">Rollen- und Berechtigungs-Demo</h1>
        <div class="text-muted">Legt 6 Reklamationen mit unterschiedlichen Standorten, Rollen, Aufgaben, Ampeln und Zugriffen an.</div>
    </div>
    <div class="d-flex gap-2">
        <a href="users.php" class="btn btn-outline-secondary">Benutzer</a>
        <a href="claims.php?q=RBAC" class="btn btn-outline-primary">RBAC-Demos anzeigen</a>
    </div>
</div>

<div class="alert alert-info">
    Diese Demo ist für deine Mehrstandort-Berechtigungen gedacht. Angelegt werden Benutzer mit Admin, Qualität, Mitarbeiter und Leser sowie Standortrollen wie Admin, Standortleiter, Qualität, Mitarbeiter und Leser.
</div>

<div class="row g-3 mb-4">
    <div class="col-lg-4">
        <div class="card h-100 border-primary-subtle">
            <div class="card-body">
                <h2 class="h5 fw-bold">Wunstorf</h2>
                <p class="text-muted mb-2">2 Reklamationen: eine aktive D4-Reklamation und ein kritischer roter Lieferantenfall.</p>
                <span class="badge bg-primary-subtle text-primary-emphasis">WUN-RBAC</span>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100 border-info-subtle">
            <div class="card-body">
                <h2 class="h5 fw-bold">Hannover</h2>
                <p class="text-muted mb-2">2 Reklamationen: frischer grüner Fall und ein Wartet-Fall mit Dispo-Maßnahme.</p>
                <span class="badge bg-info-subtle text-info-emphasis">HAN-RBAC</span>
            </div>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="card h-100 border-success-subtle">
            <div class="card-body">
                <h2 class="h5 fw-bold">Frankfurt</h2>
                <p class="text-muted mb-2">2 Reklamationen: abgeschlossener 8D-Fall und roter Fall mit Leser-Test.</p>
                <span class="badge bg-success-subtle text-success-emphasis">FRA-RBAC</span>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header bg-white fw-bold">Demo-Benutzer</div>
    <div class="table-responsive">
        <table class="table table-sm align-middle mb-0">
            <thead class="table-light"><tr><th>Login</th><th>Globale Rolle</th><th>Standort-Zugriff</th><th>Was testen?</th></tr></thead>
            <tbody>
                <tr><td>demo.rbac.admin@example.com</td><td>Admin</td><td>WUN, HAN, FRA</td><td>Sieht alles, Standort-Switcher, Benutzer/Standorte.</td></tr>
                <tr><td>demo.rbac.wun.leitung@example.com</td><td>Qualität</td><td>Wunstorf · Standortleiter</td><td>Sieht nur Wunstorf, darf bearbeiten und abschließen.</td></tr>
                <tr><td>demo.rbac.wun.lager@example.com</td><td>Mitarbeiter</td><td>Wunstorf</td><td>Sieht nur Wunstorf und eigene Maßnahmen.</td></tr>
                <tr><td>demo.rbac.han.qualitaet@example.com</td><td>Qualität</td><td>Hannover + Frankfurt</td><td>Mehrfach-Standort ohne Admin.</td></tr>
                <tr><td>demo.rbac.han.dispo@example.com</td><td>Mitarbeiter</td><td>Hannover</td><td>Hannover-Maßnahmen-Badge testen.</td></tr>
                <tr><td>demo.rbac.fra.leser@example.com</td><td>Leser</td><td>Frankfurt</td><td>Kann sehen, aber nicht bearbeiten.</td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="alert alert-warning">
    Beim Import werden nur Reklamationen mit <strong>WUN-RBAC-</strong>, <strong>HAN-RBAC-</strong> und <strong>FRA-RBAC-</strong> ersetzt. Deine echten Reklamationen bleiben unangetastet.
</div>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error): ?>
    <div class="card">
        <div class="card-body">
            <h2 class="h5 fw-bold">Demo wurde angelegt</h2>
            <p class="text-muted mb-3">Passwort für alle Demo-Benutzer: <strong>Demo12345!</strong></p>
            <div class="d-flex flex-wrap gap-2">
                <a href="claims.php?q=RBAC" class="btn btn-primary">Alle 6 Reklamationen anzeigen</a>
                <a href="users.php" class="btn btn-outline-primary">Benutzer-Kacheln prüfen</a>
                <a href="my_actions.php" class="btn btn-outline-primary">Meine Maßnahmen prüfen</a>
                <a href="auswertungen.php" class="btn btn-outline-primary">Auswertung prüfen</a>
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="post" class="card">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="fw-bold">6 Rollen-/Berechtigungs-Reklamationen anlegen</div>
                <div class="text-muted small">Nur Admins können diese Aktion ausführen.</div>
            </div>
            <div>
                <?= csrf_field() ?>
                <button class="btn btn-success btn-lg" data-confirm="Rollen-Demo anlegen? Vorhandene RBAC-Demo-Fälle werden ersetzt.">Rollen-Demo anlegen</button>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
