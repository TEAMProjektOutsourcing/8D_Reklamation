<?php
require_once __DIR__ . '/auth.php';
require_admin();

$db = pdo();

function demo_date(int $daysOffset = 0): string
{
    return (new DateTimeImmutable('today'))->modify(($daysOffset >= 0 ? '+' : '') . $daysOffset . ' days')->format('Y-m-d');
}

function demo_datetime(int $daysOffset = 0, string $time = '09:00:00'): string
{
    return demo_date($daysOffset) . ' ' . $time;
}

function demo_user_id(string $email, string $name, string $role): int
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



function demo_location_id(string $kuerzel, string $name): ?int
{
    if (!locations_enabled()) {
        return null;
    }
    $db = pdo();
    $stmt = $db->prepare('SELECT id FROM standorte WHERE kuerzel = ? LIMIT 1');
    $stmt->execute([$kuerzel]);
    $id = $stmt->fetchColumn();
    if ($id) {
        return (int)$id;
    }
    $ins = $db->prepare('INSERT INTO standorte (name, kuerzel, aktiv) VALUES (?, ?, 1)');
    $ins->execute([$name, $kuerzel]);
    return (int)$db->lastInsertId();
}

function demo_assign_user_location(int $userId, ?int $locationId, string $role, bool $default = true): void
{
    if (!locations_enabled() || !$locationId) {
        return;
    }
    $stmt = pdo()->prepare('INSERT IGNORE INTO user_standorte (user_id, standort_id, standort_role, is_default) VALUES (?, ?, ?, ?)');
    $stmt->execute([$userId, $locationId, $role, $default ? 1 : 0]);
}

function demo_write_file(string $relativePath, string $content): array
{
    $path = APP_UPLOAD_DIR . '/' . $relativePath;
    $dir = dirname($path);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        throw new RuntimeException('Demo-Uploadordner konnte nicht erstellt werden: ' . $dir);
    }
    file_put_contents($path, $content);
    return [$path, filesize($path) ?: strlen($content)];
}

function demo_svg(string $title, string $subtitle, string $color = '#0d6efd'): string
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


function demo_add_minutes(string $dateTime, int $minutes): string
{
    return (new DateTimeImmutable($dateTime))->modify('+' . $minutes . ' minutes')->format('Y-m-d H:i:s');
}

function demo_excerpt(?string $text, int $max = 180): string
{
    $text = trim((string)$text);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\s+/', ' ', $text) ?: $text;
    return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
}

function demo_generated_history(array $claim, array $stepStatuses, array $stepContents, array $actions, array $files): array
{
    $history = [];
    $createdAt = (string)$claim['created_at'];
    $creatorId = (int)$claim['created_by'];
    $responsibleId = isset($claim['responsible_user_id']) ? (int)$claim['responsible_user_id'] : $creatorId;

    $history[] = [
        'user_id' => $creatorId,
        'action' => 'Reklamation erstellt',
        'details' => 'Fall wurde als ' . status_label((string)$claim['claim_type']) . ' mit Priorität ' . priority_label((string)$claim['priority']) . ' angelegt.',
        'created_at' => $createdAt,
    ];

    $stepMinute = 25;
    foreach (claim_step_definitions() as $key => $definition) {
        $status = $stepStatuses[$key] ?? 'open';
        if ($status === 'open') {
            continue;
        }

        $content = demo_excerpt($stepContents[$key] ?? '');
        $history[] = [
            'user_id' => $responsibleId ?: $creatorId,
            'action' => step_audit_title($key, $status),
            'details' => $content !== '' ? $content : ('Status: ' . status_label($status)),
            'created_at' => demo_add_minutes($createdAt, $stepMinute),
        ];
        $stepMinute += 55;
    }

    foreach ($actions as $action) {
        $history[] = [
            'user_id' => (int)$action['created_by'],
            'action' => 'Maßnahme erstellt',
            'details' => 'D-Schritt: ' . $action['step_key'] . "\n" . 'Maßnahme: ' . $action['title'] . "\n" . 'Frist: ' . ($action['due_date'] ?? 'keine Frist') . "\n" . 'Status: ' . status_label((string)($action['status'] ?? 'open')),
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
        $statusTime = $claim['closed_at'] ?? demo_add_minutes($createdAt, 420);
        $history[] = [
            'user_id' => isset($claim['closed_by']) && $claim['closed_by'] ? (int)$claim['closed_by'] : $responsibleId,
            'action' => ($claim['status'] ?? '') === 'closed' ? 'Fall abgeschlossen' : 'Fallstatus geändert',
            'details' => 'Fallstatus: ' . status_label((string)$claim['status']),
            'created_at' => (string)$statusTime,
        ];
    }

    usort($history, static function (array $a, array $b): int {
        return strcmp((string)$a['created_at'], (string)$b['created_at']);
    });

    return $history;
}

function demo_insert_claim(array $claim, array $stepStatuses, array $stepContents, array $actions, array $files, array $history): int
{
    $db = pdo();
    if (locations_enabled()) {
        $stmt = $db->prepare('INSERT INTO claims (claim_number, standort_id, claim_type, partner_name, article_number, article_name, quantity_affected, delivery_date, claim_date, priority, status, short_description, problem_description, responsible_user_id, source_module, source_number, source_url, created_by, closed_by, closed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $claim['claim_number'],
            $claim['standort_id'] ?? null,
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
    } else {
        $stmt = $db->prepare('INSERT INTO claims (claim_number, claim_type, partner_name, article_number, article_name, quantity_affected, delivery_date, claim_date, priority, status, short_description, problem_description, responsible_user_id, created_by, closed_by, closed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stmt->execute([
            $claim['claim_number'],
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
            $claim['created_by'],
            $claim['closed_by'] ?? null,
            $claim['closed_at'] ?? null,
            $claim['created_at'],
        ]);
    }
    $claimId = (int)$db->lastInsertId();

    foreach (claim_step_definitions() as $key => $definition) {
        $status = $stepStatuses[$key] ?? 'open';
        $content = $stepContents[$key] ?? null;
        $completedBy = $status === 'done' ? ($claim['responsible_user_id'] ?: $claim['created_by']) : null;
        $completedAt = $status === 'done' ? demo_datetime(-1, '15:30:00') : null;
        $stepStmt = $db->prepare('INSERT INTO claim_steps (claim_id, step_key, title, description, content, status, completed_by, completed_at, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $stepStmt->execute([$claimId, $key, $definition['title'], $definition['description'], $content, $status, $completedBy, $completedAt, $claim['created_at']]);
    }

    foreach ($actions as $action) {
        $actionStmt = $db->prepare('INSERT INTO claim_actions (claim_id, step_key, title, description, responsible_user_id, due_date, status, completed_at, created_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
        $actionStmt->execute([
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
        [$path, $size] = demo_write_file($file['file_path'], $file['content']);
        $fileStmt = $db->prepare('INSERT INTO claim_files (claim_id, original_name, stored_name, file_path, mime_type, size_bytes, uploaded_by, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $fileStmt->execute([
            $claimId,
            $file['original_name'],
            basename($file['file_path']),
            $file['file_path'],
            $file['mime_type'],
            $size,
            $file['uploaded_by'],
            $file['created_at'],
        ]);
    }

    $generatedHistory = demo_generated_history($claim, $stepStatuses, $stepContents, $actions, $files);
    foreach ($generatedHistory as $item) {
        $histStmt = $db->prepare('INSERT INTO claim_history (claim_id, user_id, action, details, created_at) VALUES (?, ?, ?, ?, ?)');
        $histStmt->execute([$claimId, $item['user_id'] ?? null, $item['action'], $item['details'] ?? null, $item['created_at']]);
    }

    return $claimId;
}

$createdClaims = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    try {
        $current = current_user();
        $adminId = (int)$current['id'];
        $qualityId = demo_user_id('demo.qualitaet@example.com', 'Daniel Qualität Demo', 'quality');
        $lagerId = demo_user_id('demo.lager@example.com', 'Markus Lager Demo', 'employee');
        $dispoId = demo_user_id('demo.dispo@example.com', 'Anna Dispo Demo', 'employee');
        $viewerId = demo_user_id('demo.leser@example.com', 'Lisa Leser Demo', 'viewer');

        $wunId = demo_location_id('WUN', 'Wunstorf');
        $hanId = demo_location_id('HAN', 'Hannover');
        foreach ([[$adminId, 'admin'], [$qualityId, 'quality'], [$lagerId, 'employee'], [$dispoId, 'employee'], [$viewerId, 'viewer']] as $pair) {
            demo_assign_user_location((int)$pair[0], $wunId, (string)$pair[1], true);
        }
        demo_assign_user_location($adminId, $hanId, 'admin', false);
        demo_assign_user_location($qualityId, $hanId, 'quality', false);
        demo_assign_user_location($dispoId, $hanId, 'employee', false);

        $demoNumbers = ['R-DEMO-0001', 'R-DEMO-0002', 'R-DEMO-0003', 'R-DEMO-0004', 'R-DEMO-0005'];

        $db->beginTransaction();
        $placeholders = implode(',', array_fill(0, count($demoNumbers), '?'));
        $delete = $db->prepare("DELETE FROM claims WHERE claim_number IN ($placeholders)");
        $delete->execute($demoNumbers);

        $createdClaims[] = demo_insert_claim([
            'claim_number' => 'R-DEMO-0001',
            'standort_id' => $wunId,
            'source_module' => 'warenausgang',
            'source_number' => 'WA-DEMO-2828',
            'claim_type' => 'customer',
            'partner_name' => 'VW Werk Hannover',
            'article_number' => '5Q0-807-217-A',
            'article_name' => 'Stoßfänger vorne grundiert',
            'quantity_affected' => 4,
            'delivery_date' => demo_date(-2),
            'claim_date' => demo_date(-1),
            'priority' => 'high',
            'status' => 'new',
            'short_description' => 'Kratzer an Stoßfänger nach Warenausgang',
            'problem_description' => "Beim Kunden wurden 4 Stoßfänger mit sichtbaren Kratzern an der rechten Außenseite gemeldet. Fotos liegen als Demo-Anhang bei. Der Fall ist absichtlich noch am Anfang, damit D1 als aktueller Schritt sichtbar ist.",
            'responsible_user_id' => $qualityId,
            'created_by' => $adminId,
            'created_at' => demo_datetime(-1, '08:15:00'),
        ], [
            'D1' => 'in_progress',
        ], [
            'D1' => "Team wird gerade zusammengestellt. Qualität, Lager und Versand sollen beteiligt werden.",
        ], [
            ['step_key' => 'D1', 'title' => 'Team festlegen', 'description' => 'Qualität, Lager und Versand als Bearbeitungsteam eintragen.', 'responsible_user_id' => $qualityId, 'due_date' => demo_date(2), 'status' => 'open', 'created_by' => $adminId, 'created_at' => demo_datetime(-1, '08:30:00')],
            ['step_key' => 'D3', 'title' => 'Restbestand prüfen', 'description' => 'Restliche Stoßfänger auf Kratzer prüfen und ggf. sperren.', 'responsible_user_id' => $lagerId, 'due_date' => demo_date(3), 'status' => 'open', 'created_by' => $adminId, 'created_at' => demo_datetime(-2, '11:00:00')],
        ], [
            ['original_name' => 'demo_foto_stossfaenger.svg', 'file_path' => 'demo/r-demo-0001-foto.svg', 'mime_type' => 'image/svg+xml', 'uploaded_by' => $adminId, 'created_at' => demo_datetime(-1, '08:45:00'), 'content' => demo_svg('Demo-Foto Stoßfänger', 'R-DEMO-0001 · Kratzer dokumentiert', '#0d6efd')],
        ], [
            ['user_id' => $adminId, 'action' => 'Reklamation erstellt', 'details' => 'Demo-Fall: frische Kundenreklamation mit grünen Maßnahmen.', 'created_at' => demo_datetime(-1, '08:15:00')],
            ['user_id' => $qualityId, 'action' => '8D-Schritt vorbereitet', 'details' => 'D1 wurde auf In Bearbeitung gesetzt.', 'created_at' => demo_datetime(-1, '09:00:00')],
        ]);

        $createdClaims[] = demo_insert_claim([
            'claim_number' => 'R-DEMO-0002',
            'standort_id' => $wunId,
            'source_module' => 'wareneingang',
            'source_number' => 'WE-DEMO-1055',
            'claim_type' => 'supplier',
            'partner_name' => 'Müller Verpackungen GmbH',
            'article_number' => 'VW0012',
            'article_name' => 'Mehrwegbehälter 1200x1000',
            'quantity_affected' => 18,
            'delivery_date' => demo_date(-8),
            'claim_date' => demo_date(-7),
            'priority' => 'medium',
            'status' => 'in_progress',
            'short_description' => 'Behälter mit beschädigtem Deckel angeliefert',
            'problem_description' => "Bei der Wareneingangsprüfung wurden mehrere Behälter mit beschädigten Deckeln festgestellt. Dieser Fall zeigt gelbe Maßnahmen im Alter von 6–10 Tagen und einen laufenden D3-Schritt.",
            'responsible_user_id' => $adminId,
            'created_by' => $qualityId,
            'created_at' => demo_datetime(-7, '10:10:00'),
        ], [
            'D1' => 'done', 'D2' => 'done', 'D3' => 'in_progress',
        ], [
            'D1' => "Team: Qualität Daniel, Lager Markus, Einkauf Anna, Lieferant Müller Verpackungen.",
            'D2' => "18 Behälter mit beschädigten Deckeln im Wareneingang. Festgestellt bei Sichtprüfung. Betroffen ist Lieferung vom " . demo_date(-8) . ".",
            'D3' => "Sofortmaßnahmen laufen: Bestand getrennt lagern, Lieferant informieren, Ersatzlieferung anfragen.",
        ], [
            ['step_key' => 'D3', 'title' => 'Lieferant informieren', 'description' => 'Lieferant mit Fotos und Menge anschreiben.', 'responsible_user_id' => $dispoId, 'due_date' => demo_date(1), 'status' => 'in_progress', 'created_by' => $qualityId, 'created_at' => demo_datetime(-7, '10:45:00')],
            ['step_key' => 'D3', 'title' => 'Beschädigte Behälter separieren', 'description' => '18 Behälter in Sperrfläche abstellen und kennzeichnen.', 'responsible_user_id' => $lagerId, 'due_date' => demo_date(0), 'status' => 'open', 'created_by' => $qualityId, 'created_at' => demo_datetime(-6, '14:30:00')],
        ], [
            ['original_name' => 'demo_pruefvermerk.txt', 'file_path' => 'demo/r-demo-0002-pruefvermerk.txt', 'mime_type' => 'text/plain', 'uploaded_by' => $lagerId, 'created_at' => demo_datetime(-6, '15:00:00'), 'content' => "Prüfvermerk Demo\nR-DEMO-0002\n18 Behälter mit beschädigtem Deckel separiert.\n"],
        ], [
            ['user_id' => $qualityId, 'action' => 'Reklamation erstellt', 'details' => 'Demo-Fall: Lieferantenreklamation mit gelben Maßnahmen.', 'created_at' => demo_datetime(-7, '10:10:00')],
            ['user_id' => $lagerId, 'action' => 'Maßnahme gestartet', 'details' => 'Sperrfläche wurde vorbereitet.', 'created_at' => demo_datetime(-6, '15:10:00')],
        ]);

        $createdClaims[] = demo_insert_claim([
            'claim_number' => 'R-DEMO-0003',
            'standort_id' => $wunId,
            'source_module' => 'warenausgang',
            'source_number' => 'WA-DEMO-3301',
            'claim_type' => 'internal',
            'partner_name' => 'Interne Qualitätssicherung',
            'article_number' => 'GT14488',
            'article_name' => 'Kartonverpackung Set links',
            'quantity_affected' => 72,
            'delivery_date' => demo_date(-14),
            'claim_date' => demo_date(-13),
            'priority' => 'critical',
            'status' => 'overdue',
            'short_description' => 'Falsche Etiketten auf Kartons',
            'problem_description' => "Interner Serienfehler: 72 Kartons wurden mit falscher Sachnummer etikettiert. Dieser Fall zeigt rote Maßnahmen ab Tag 11 und zusätzlich eine überschrittene Frist.",
            'responsible_user_id' => $qualityId,
            'created_by' => $adminId,
            'created_at' => demo_datetime(-13, '07:50:00'),
        ], [
            'D1' => 'done', 'D2' => 'done', 'D3' => 'done', 'D4' => 'done', 'D5' => 'in_progress',
        ], [
            'D1' => "Team: Qualität, Lager, Etikettierung und Schichtleitung.",
            'D2' => "72 Kartons mit falscher Sachnummer GT14488 statt GT14491 etikettiert. Fehler wurde vor Versand entdeckt.",
            'D3' => "Alle betroffenen Kartons wurden gesperrt. Versandfreigabe wurde gestoppt.",
            'D4' => "5-Why: Warum falsch etikettiert? Alte Vorlage im Drucker ausgewählt. Warum? Vorlagenname war nicht eindeutig. Hauptursache: fehlende Sperrung alter Etikettenvorlagen.",
            'D5' => "Korrekturmaßnahmen werden geplant: Vorlagen eindeutig benennen, alte Vorlagen sperren, Vier-Augen-Prüfung einführen.",
        ], [
            ['step_key' => 'D5', 'title' => 'Alte Etikettenvorlagen sperren', 'description' => 'Nicht mehr gültige Vorlagen aus dem Drucksystem entfernen.', 'responsible_user_id' => $adminId, 'due_date' => demo_date(-1), 'status' => 'open', 'created_by' => $qualityId, 'created_at' => demo_datetime(-12, '09:10:00')],
            ['step_key' => 'D5', 'title' => 'Vier-Augen-Prüfung definieren', 'description' => 'Vor Versand muss Etikett gegen Sachnummer geprüft werden.', 'responsible_user_id' => $lagerId, 'due_date' => demo_date(4), 'status' => 'open', 'created_by' => $qualityId, 'created_at' => demo_datetime(-11, '16:20:00')],
            ['step_key' => 'D3', 'title' => 'Kartons sperren', 'description' => 'Betroffene Kartons in Sperrfläche bringen.', 'responsible_user_id' => $lagerId, 'due_date' => demo_date(-12), 'status' => 'done', 'completed_at' => demo_datetime(-12, '12:00:00'), 'created_by' => $adminId, 'created_at' => demo_datetime(-13, '08:00:00')],
        ], [
            ['original_name' => 'demo_foto_etikett.svg', 'file_path' => 'demo/r-demo-0003-etikett.svg', 'mime_type' => 'image/svg+xml', 'uploaded_by' => $qualityId, 'created_at' => demo_datetime(-12, '09:00:00'), 'content' => demo_svg('Demo-Foto Etikett', 'R-DEMO-0003 · falsche Sachnummer', '#dc3545')],
        ], [
            ['user_id' => $adminId, 'action' => 'Reklamation erstellt', 'details' => 'Demo-Fall: rote Maßnahmen und überfälliger Fallstatus.', 'created_at' => demo_datetime(-13, '07:50:00')],
            ['user_id' => $qualityId, 'action' => 'Ursachenanalyse abgeschlossen', 'details' => 'Hauptursache: alte Etikettenvorlagen nicht gesperrt.', 'created_at' => demo_datetime(-9, '13:40:00')],
        ]);

        $createdClaims[] = demo_insert_claim([
            'claim_number' => 'R-DEMO-0004',
            'standort_id' => $hanId,
            'source_module' => 'kommi',
            'source_number' => 'KOM-DEMO-HAN-014',
            'claim_type' => 'customer',
            'partner_name' => 'Audi Ersatzteillogistik',
            'article_number' => 'DB0011',
            'article_name' => 'Kleinteile-Behälter',
            'quantity_affected' => 12,
            'delivery_date' => demo_date(-18),
            'claim_date' => demo_date(-17),
            'priority' => 'high',
            'status' => 'waiting',
            'short_description' => 'Mengenabweichung im Warenausgang',
            'problem_description' => "Kunde meldet 12 Stück zu wenig. Der Fall ist fast fertig und wartet auf Rückmeldung zur Wirksamkeitsprüfung. Er zeigt D1–D6 erledigt und D7 aktiv.",
            'responsible_user_id' => $adminId,
            'created_by' => $dispoId,
            'created_at' => demo_datetime(-17, '12:00:00'),
        ], [
            'D1' => 'done', 'D2' => 'done', 'D3' => 'done', 'D4' => 'done', 'D5' => 'done', 'D6' => 'done', 'D7' => 'in_progress',
        ], [
            'D1' => "Team: Disposition, Lager, Qualität, Kunde.",
            'D2' => "Sollmenge 120 Stück, gemeldet angekommen 108 Stück. Differenz: 12 Stück.",
            'D3' => "Kunde wurde informiert, Bestand intern gegengeprüft, Nachlieferung vorbereitet.",
            'D4' => "Ursache: Beim Kommissionieren wurde eine Teilmenge nicht auf den Ausgang gebucht.",
            'D5' => "Korrekturmaßnahme: Ausgangskontrolle mit Scanpflicht vor Verladung.",
            'D6' => "Scanpflicht wurde testweise im Prozess umgesetzt und mit zwei Ausgängen erfolgreich geprüft.",
            'D7' => "Vorbeugung läuft: Checkliste und Schulung für Spätschicht werden vorbereitet.",
        ], [
            ['step_key' => 'D7', 'title' => 'Checkliste Warenausgang aktualisieren', 'description' => 'Kontrollpunkt „Menge gegen Ausgang prüfen“ ergänzen.', 'responsible_user_id' => $adminId, 'due_date' => demo_date(2), 'status' => 'in_progress', 'created_by' => $dispoId, 'created_at' => demo_datetime(-8, '09:20:00')],
            ['step_key' => 'D7', 'title' => 'Spätschicht unterweisen', 'description' => 'Kurze Prozessunterweisung mit Teilnehmerliste.', 'responsible_user_id' => $qualityId, 'due_date' => demo_date(-2), 'status' => 'open', 'created_by' => $dispoId, 'created_at' => demo_datetime(-4, '10:00:00')],
            ['step_key' => 'D6', 'title' => 'Test-Ausgang prüfen', 'description' => 'Zwei Ausgänge mit neuer Scanpflicht begleiten.', 'responsible_user_id' => $lagerId, 'due_date' => demo_date(-5), 'status' => 'done', 'completed_at' => demo_datetime(-5, '15:30:00'), 'created_by' => $dispoId, 'created_at' => demo_datetime(-7, '07:30:00')],
        ], [
            ['original_name' => 'demo_checkliste_warenausgang.txt', 'file_path' => 'demo/r-demo-0004-checkliste.txt', 'mime_type' => 'text/plain', 'uploaded_by' => $adminId, 'created_at' => demo_datetime(-3, '16:30:00'), 'content' => "Demo-Checkliste Warenausgang\n- Menge gegen Ausgang prüfen\n- Scanpflicht erfüllt\n- Verladung freigegeben\n"],
        ], [
            ['user_id' => $dispoId, 'action' => 'Reklamation erstellt', 'details' => 'Demo-Fall: fast abgeschlossen, wartet auf Rückmeldung.', 'created_at' => demo_datetime(-17, '12:00:00')],
            ['user_id' => $adminId, 'action' => 'Maßnahme umgesetzt', 'details' => 'Scanpflicht wurde testweise angewendet.', 'created_at' => demo_datetime(-5, '15:45:00')],
        ]);

        $createdClaims[] = demo_insert_claim([
            'claim_number' => 'R-DEMO-0005',
            'standort_id' => $wunId,
            'source_module' => 'wareneingang',
            'source_number' => 'WE-DEMO-2099',
            'claim_type' => 'supplier',
            'partner_name' => 'Schmidt Metalltechnik KG',
            'article_number' => '111925/2',
            'article_name' => 'Metallrahmen links',
            'quantity_affected' => 6,
            'delivery_date' => demo_date(-30),
            'claim_date' => demo_date(-29),
            'priority' => 'low',
            'status' => 'closed',
            'short_description' => 'Leichte Verformung an Metallrahmen',
            'problem_description' => "Abgeschlossener Demo-Fall mit 100% 8D-Fortschritt. Gut zum Testen des Berichts/PDF und erledigter Maßnahmen.",
            'responsible_user_id' => $qualityId,
            'created_by' => $adminId,
            'closed_by' => $qualityId,
            'closed_at' => demo_datetime(-3, '14:00:00'),
            'created_at' => demo_datetime(-29, '08:00:00'),
        ], [
            'D1' => 'done', 'D2' => 'done', 'D3' => 'done', 'D4' => 'done', 'D5' => 'done', 'D6' => 'done', 'D7' => 'done', 'D8' => 'done',
        ], [
            'D1' => "Team: Qualität, Einkauf, Lieferant Schmidt Metalltechnik.",
            'D2' => "6 Metallrahmen mit leichter Verformung an der linken Ecke. Keine Sicherheitsrelevanz, aber Nacharbeit erforderlich.",
            'D3' => "Ware gesperrt, Lieferant informiert, Ersatzlieferung freigegeben.",
            'D4' => "Ursache: unzureichende Zwischenlage im Transportgestell.",
            'D5' => "Korrekturmaßnahme: zusätzliche Zwischenlage und Sichtprüfung beim Lieferanten.",
            'D6' => "Lieferant hat Verpackung angepasst und Nachweis übermittelt.",
            'D7' => "Verpackungsvorschrift wurde beim Lieferanten als Standard hinterlegt.",
            'D8' => "Wirksamkeit geprüft. Ersatzlieferung ohne Beanstandung eingetroffen. Fall abgeschlossen.",
        ], [
            ['step_key' => 'D3', 'title' => 'Ware sperren', 'description' => 'Betroffene Rahmen bis zur Klärung sperren.', 'responsible_user_id' => $lagerId, 'due_date' => demo_date(-28), 'status' => 'done', 'completed_at' => demo_datetime(-28, '10:30:00'), 'created_by' => $adminId, 'created_at' => demo_datetime(-29, '08:30:00')],
            ['step_key' => 'D6', 'title' => 'Lieferantennachweis prüfen', 'description' => 'Foto der neuen Zwischenlage prüfen und dokumentieren.', 'responsible_user_id' => $qualityId, 'due_date' => demo_date(-8), 'status' => 'done', 'completed_at' => demo_datetime(-8, '13:10:00'), 'created_by' => $adminId, 'created_at' => demo_datetime(-12, '09:15:00')],
            ['step_key' => 'D8', 'title' => 'Fall abschließen', 'description' => 'Wirksamkeit bestätigen und Bericht erzeugen.', 'responsible_user_id' => $qualityId, 'due_date' => demo_date(-3), 'status' => 'done', 'completed_at' => demo_datetime(-3, '14:00:00'), 'created_by' => $adminId, 'created_at' => demo_datetime(-4, '11:00:00')],
        ], [
            ['original_name' => 'demo_foto_metallrahmen.svg', 'file_path' => 'demo/r-demo-0005-metallrahmen.svg', 'mime_type' => 'image/svg+xml', 'uploaded_by' => $qualityId, 'created_at' => demo_datetime(-25, '11:00:00'), 'content' => demo_svg('Demo-Foto Metallrahmen', 'R-DEMO-0005 · abgeschlossener Fall', '#198754')],
            ['original_name' => 'demo_abschlussnotiz.txt', 'file_path' => 'demo/r-demo-0005-abschluss.txt', 'mime_type' => 'text/plain', 'uploaded_by' => $qualityId, 'created_at' => demo_datetime(-3, '14:05:00'), 'content' => "Abschlussnotiz Demo\nWirksamkeit geprüft. Ersatzlieferung ohne Beanstandung.\n"],
        ], [
            ['user_id' => $adminId, 'action' => 'Reklamation erstellt', 'details' => 'Demo-Fall: komplett abgeschlossener 8D-Bericht.', 'created_at' => demo_datetime(-29, '08:00:00')],
            ['user_id' => $qualityId, 'action' => 'Fall abgeschlossen', 'details' => 'D8 abgeschlossen und Bericht freigegeben.', 'created_at' => demo_datetime(-3, '14:00:00')],
        ]);

        $db->commit();
        flash('success', '5 Demo-Reklamationen wurden angelegt. Vorhandene R-DEMO-Fälle wurden vorher ersetzt.');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $error = $e->getMessage();
        flash('danger', 'Demo-Daten konnten nicht angelegt werden: ' . $error);
    }
}

require __DIR__ . '/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-4">
    <div>
        <div class="text-muted small">Admin</div>
        <h1 class="h3 fw-bold mb-1">Demo-Reklamationen anlegen</h1>
        <div class="text-muted">Legt 5 Beispiel-Reklamationen mit D1–D8, Maßnahmen, Ampel, Dateien und Historie an.</div>
    </div>
    <a href="claims.php" class="btn btn-outline-secondary">Zurück zu Reklamationen</a>
</div>

<div class="card mb-4">
    <div class="card-body">
        <h2 class="h5 fw-bold">Was wird angelegt?</h2>
        <div class="row g-3 mt-1">
            <div class="col-md-6 col-xl-4"><div class="border rounded p-3 h-100"><strong>R-DEMO-0001</strong><br><span class="text-muted">Neu, D1 aktiv, grüne Maßnahmen</span></div></div>
            <div class="col-md-6 col-xl-4"><div class="border rounded p-3 h-100"><strong>R-DEMO-0002</strong><br><span class="text-muted">In Bearbeitung, D3 aktiv, gelbe Maßnahmen</span></div></div>
            <div class="col-md-6 col-xl-4"><div class="border rounded p-3 h-100"><strong>R-DEMO-0003</strong><br><span class="text-muted">Überfällig, D5 aktiv, rote Maßnahmen</span></div></div>
            <div class="col-md-6 col-xl-4"><div class="border rounded p-3 h-100"><strong>R-DEMO-0004</strong><br><span class="text-muted">Wartet, D7 aktiv, Erinnerungsbutton sichtbar</span></div></div>
            <div class="col-md-6 col-xl-4"><div class="border rounded p-3 h-100"><strong>R-DEMO-0005</strong><br><span class="text-muted">Abgeschlossen, 100% Fortschritt, PDF-Bericht testbar</span></div></div>
        </div>

        <div class="alert alert-warning mt-4 mb-0">
            Beim Import werden bestehende Demo-Fälle mit den Nummern <strong>R-DEMO-0001 bis R-DEMO-0005</strong> vorher gelöscht und neu erstellt. Deine echten Reklamationen bleiben unangetastet.
        </div>
    </div>
</div>

<?php if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$error): ?>
    <div class="card">
        <div class="card-body">
            <h2 class="h5 fw-bold">Demo-Daten wurden angelegt</h2>
            <div class="d-flex flex-wrap gap-2 mt-3">
                <a class="btn btn-primary" href="claims.php?q=R-DEMO">Demo-Reklamationen anzeigen</a>
                <a class="btn btn-outline-primary" href="dashboard.php">Dashboard prüfen</a>
                <a class="btn btn-outline-primary" href="my_actions.php">Meine Maßnahmen prüfen</a>
            </div>
            <div class="alert alert-info mt-4 mb-0">
                Zusätzlich wurden Demo-Benutzer angelegt. Passwort für Demo-Benutzer: <strong>Demo12345!</strong><br>
                Beispiel: demo.qualitaet@example.com, demo.lager@example.com, demo.dispo@example.com
            </div>
        </div>
    </div>
<?php else: ?>
    <form method="post" class="card">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-3">
            <div>
                <div class="fw-bold">Demo-Daten jetzt anlegen</div>
                <div class="text-muted small">Nur Admins können diese Aktion ausführen.</div>
            </div>
            <div>
                <?= csrf_field() ?>
                <button class="btn btn-success btn-lg" data-confirm="5 Demo-Reklamationen anlegen? Vorhandene R-DEMO-Fälle werden ersetzt.">Demo-Daten anlegen</button>
            </div>
        </div>
    </form>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
