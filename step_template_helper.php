<?php

declare(strict_types=1);

/**
 * Helper für 8D-Vorlagen.
 *
 * Diese Datei wird von claim_store.php genutzt.
 * Wenn die Tabelle step_templates noch nicht existiert, fällt das System sauber
 * auf die bisherigen festen D1-D8 Definitionen zurück.
 */

function eightd_default_step_templates(): array
{
    return [
        'D1' => [
            'title' => 'Team bilden',
            'description' => 'Beteiligte Personen, Rollen und Verantwortlichkeiten festlegen.',
            'help_text' => 'Lege fest, wer die Reklamation bearbeitet, wer fachlich beteiligt ist und wer Entscheidungen freigibt.',
            'required_fields' => 'Verantwortlicher, beteiligte Personen, Zuständigkeiten',
            'sort_order' => 1,
        ],
        'D2' => [
            'title' => 'Problem beschreiben',
            'description' => 'Problem eindeutig mit Fakten, Mengen, Datum, Ort und betroffenen Artikeln beschreiben.',
            'help_text' => 'Beschreibe das Problem sachlich und nachvollziehbar: Was ist passiert? Wann? Wo? Welche Menge/Teile sind betroffen?',
            'required_fields' => 'Fehlerbild, Datum, Ort, betroffener Artikel, Menge, Nachweise/Fotos',
            'sort_order' => 2,
        ],
        'D3' => [
            'title' => 'Sofortmaßnahmen',
            'description' => 'Akute Maßnahmen zur Schadensbegrenzung definieren.',
            'help_text' => 'Dokumentiere, welche kurzfristigen Maßnahmen getroffen wurden, um weitere Schäden oder Wiederholungen sofort zu verhindern.',
            'required_fields' => 'Maßnahme, Verantwortlicher, Termin, Status',
            'sort_order' => 3,
        ],
        'D4' => [
            'title' => 'Ursachenanalyse',
            'description' => 'Hauptursache ermitteln, z. B. mit 5-Why oder Ishikawa.',
            'help_text' => 'Nutze z. B. 5-Why, Ishikawa oder eine andere passende Methode. Ziel ist die echte Ursache, nicht nur ein Symptom.',
            'required_fields' => 'Methode, Ursache, Nachweis/Begründung',
            'sort_order' => 4,
        ],
        'D5' => [
            'title' => 'Korrekturmaßnahmen planen',
            'description' => 'Dauerhafte Maßnahmen gegen die ermittelte Ursache festlegen.',
            'help_text' => 'Plane Maßnahmen, die die ermittelte Ursache dauerhaft abstellen.',
            'required_fields' => 'Maßnahme, Verantwortlicher, Termin, erwartete Wirkung',
            'sort_order' => 5,
        ],
        'D6' => [
            'title' => 'Maßnahmen umsetzen',
            'description' => 'Umsetzung dokumentieren und Nachweise sichern.',
            'help_text' => 'Dokumentiere, wann welche Maßnahme umgesetzt wurde und füge Nachweise hinzu.',
            'required_fields' => 'Umsetzungsdatum, Nachweis, Ergebnis',
            'sort_order' => 6,
        ],
        'D7' => [
            'title' => 'Wiederholfehler verhindern',
            'description' => 'Vorbeugende Maßnahmen, Standards, Schulungen oder Prüfungen festlegen.',
            'help_text' => 'Prüfe, ob Prozesse, Arbeitsanweisungen, Schulungen oder Prüfungen angepasst werden müssen.',
            'required_fields' => 'Standardänderung, Schulung/Unterweisung, Prozessanpassung',
            'sort_order' => 7,
        ],
        'D8' => [
            'title' => 'Abschluss',
            'description' => 'Wirksamkeit bestätigen, Kunden/Lieferanten informieren und Fall abschließen.',
            'help_text' => 'Prüfe, ob die Maßnahmen wirksam waren. Danach kann der Fall abgeschlossen werden.',
            'required_fields' => 'Wirksamkeitsprüfung, Abschlussbewertung, Freigabe',
            'sort_order' => 8,
        ],
    ];
}

function eightd_claim_steps_has_template_version(PDO $db): bool
{
    if (function_exists('db_column_exists')) {
        return db_column_exists('claim_steps', 'template_version');
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'claim_steps' AND COLUMN_NAME = 'template_version'");
    $stmt->execute();
    return (int)$stmt->fetchColumn() > 0;
}

function eightd_step_templates_available(PDO $db): bool
{
    if (function_exists('db_table_exists')) {
        return db_table_exists('step_templates');
    }

    $stmt = $db->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'step_templates'");
    $stmt->execute();
    return (int)$stmt->fetchColumn() > 0;
}

function eightd_get_active_step_templates(PDO $db): array
{
    if (!eightd_step_templates_available($db)) {
        return [];
    }

    $version = (int)$db->query("SELECT COALESCE(MAX(version_no), 0) FROM step_templates WHERE is_active = 1")->fetchColumn();
    if ($version <= 0) {
        return [];
    }

    $stmt = $db->prepare("SELECT * FROM step_templates WHERE is_active = 1 AND version_no = ? ORDER BY sort_order ASC, step_key ASC");
    $stmt->execute([$version]);

    $templates = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $templates[(string)$row['step_key']] = $row;
    }

    return $templates;
}

function create_claim_steps_from_templates(PDO $db, int $claimId, ?int $userId = null, string $d2Content = ''): void
{
    $defaults = eightd_default_step_templates();
    $templates = eightd_get_active_step_templates($db);
    $hasTemplateVersion = eightd_claim_steps_has_template_version($db);

    foreach ($defaults as $stepKey => $default) {
        $tpl = $templates[$stepKey] ?? [];

        $title = trim((string)($tpl['title'] ?? $default['title']));
        $description = trim((string)($tpl['description'] ?? $default['description']));
        $templateVersion = isset($tpl['version_no']) ? (int)$tpl['version_no'] : null;

        $content = '';
        $status = 'open';
        $completedBy = null;
        $completedAt = null;

        if ($stepKey === 'D2' && trim($d2Content) !== '') {
            $content = trim($d2Content);
            $status = 'in_progress';
        }

        if ($hasTemplateVersion) {
            $stmt = $db->prepare("INSERT INTO claim_steps
                (claim_id, step_key, template_version, title, description, content, status, completed_by, completed_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    template_version = VALUES(template_version),
                    title = VALUES(title),
                    description = VALUES(description),
                    updated_at = NOW()");
            $stmt->execute([
                $claimId,
                $stepKey,
                $templateVersion,
                $title,
                $description,
                $content,
                $status,
                $completedBy,
                $completedAt,
            ]);
        } else {
            $stmt = $db->prepare("INSERT INTO claim_steps
                (claim_id, step_key, title, description, content, status, completed_by, completed_at, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    title = VALUES(title),
                    description = VALUES(description),
                    updated_at = NOW()");
            $stmt->execute([
                $claimId,
                $stepKey,
                $title,
                $description,
                $content,
                $status,
                $completedBy,
                $completedAt,
            ]);
        }
    }
}
