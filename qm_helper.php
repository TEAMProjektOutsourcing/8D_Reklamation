<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


/**
 * QM Helper - Wiederholfehler & Maßnahmenwirksamkeit Phase 1
 *
 * Ziel:
 * - Reklamationen strukturiert klassifizieren
 * - ähnliche / wiederkehrende Fehler erkennen
 * - Auswertung für Qualitätsmanagement vorbereiten
 * - KI-Auswertung später sauber anschließen
 */

function qm_error_category_options(): array
{
    return [
        '' => 'Bitte auswählen',
        'etikett_kennzeichnung' => 'Etikett / Kennzeichnung',
        'menge_fehlmenge' => 'Menge / Fehlmenge',
        'falscher_artikel' => 'Falscher Artikel',
        'verpackung_beschaedigt' => 'Verpackung beschädigt',
        'transport' => 'Transport / Handling',
        'dokumentation' => 'Dokumentation / Papiere',
        'materialqualitaet' => 'Materialqualität',
        'liefertermin' => 'Liefertermin / Verzug',
        'prozess_system' => 'Prozess / System',
        'sonstiges' => 'Sonstiges',
    ];
}

function qm_error_pattern_options(): array
{
    return [
        '' => 'Bitte auswählen',
        'falsches_etikett' => 'Falsches Etikett / Label',
        'etikett_fehlt' => 'Etikett / Label fehlt',
        'falsche_kennzeichnung' => 'Falsche Kennzeichnung',
        'falsche_menge' => 'Falsche Menge',
        'fehlmenge' => 'Fehlmenge',
        'falscher_artikel' => 'Falscher Artikel',
        'falsche_charge' => 'Falsche Charge / Version',
        'beschaedigte_verpackung' => 'Beschädigte Verpackung',
        'transportschaden' => 'Transportschaden',
        'dokument_fehlt' => 'Dokument fehlt',
        'falsche_dokumente' => 'Falsche Dokumente',
        'material_beschaedigt' => 'Material beschädigt',
        'massabweichung' => 'Maßabweichung / Spezifikation nicht erfüllt',
        'prozess_nicht_eingehalten' => 'Prozess nicht eingehalten',
        'systemfehler' => 'Systemfehler / IT-Fehler',
        'sonstiges' => 'Sonstiges',
    ];
}

function qm_process_area_options(): array
{
    return [
        '' => 'Bitte auswählen',
        'wareneingang' => 'Wareneingang',
        'lager' => 'Lager',
        'kommissionierung' => 'Kommissionierung',
        'warenausgang' => 'Warenausgang',
        'transport' => 'Transport',
        'dokumentation' => 'Dokumentation',
        'qualitaetspruefung' => 'Qualitätsprüfung',
        'lieferant' => 'Lieferant',
        'kunde' => 'Kunde',
        'intern' => 'Interner Prozess',
        'sonstiges' => 'Sonstiges',
    ];
}

function qm_root_cause_category_options(): array
{
    return [
        '' => 'Bitte auswählen',
        'mensch' => 'Mensch',
        'methode_prozess' => 'Methode / Prozess',
        'maschine_technik' => 'Maschine / Technik',
        'material' => 'Material',
        'messung_pruefung' => 'Messung / Prüfung',
        'lieferant' => 'Lieferant',
        'system_it' => 'System / IT',
        'umgebung' => 'Umgebung',
        'unklar' => 'Noch unklar',
        'sonstiges' => 'Sonstiges',
    ];
}

function qm_label(array $options, ?string $value): string
{
    $value = (string)($value ?? '');
    return $options[$value] ?? ($value !== '' ? $value : '-');
}

function qm_select_options(array $options, ?string $selected): string
{
    $html = '';
    $selected = (string)($selected ?? '');

    foreach ($options as $value => $label) {
        $html .= '<option value="' . e((string)$value) . '"' . ((string)$value === $selected ? ' selected' : '') . '>' . e((string)$label) . '</option>';
    }

    return $html;
}

function qm_claim_field_names(): array
{
    return [
        'error_category',
        'error_pattern',
        'process_area',
        'root_cause_category',
    ];
}

function qm_claim_fields_enabled(): bool
{
    foreach (qm_claim_field_names() as $column) {
        if (!db_column_exists('claims', $column)) {
            return false;
        }
    }

    return true;
}

function qm_clean_value(array $source, string $key): ?string
{
    $value = trim((string)($source[$key] ?? ''));
    return $value === '' ? null : mb_substr($value, 0, 120, 'UTF-8');
}

function qm_post_data(array $source): array
{
    return [
        'error_category' => qm_clean_value($source, 'error_category'),
        'error_pattern' => qm_clean_value($source, 'error_pattern'),
        'process_area' => qm_clean_value($source, 'process_area'),
        'root_cause_category' => qm_clean_value($source, 'root_cause_category'),
    ];
}

function qm_save_claim_fields(PDO $db, int $claimId, array $source): void
{
    if ($claimId <= 0) {
        return;
    }

    $data = qm_post_data($source);
    $sets = [];
    $params = [];

    foreach ($data as $column => $value) {
        if (!db_column_exists('claims', $column)) {
            continue;
        }

        $sets[] = $column . ' = ?';
        $params[] = $value;
    }

    if (!$sets) {
        return;
    }

    if (db_column_exists('claims', 'updated_at')) {
        $sets[] = 'updated_at = NOW()';
    }

    $params[] = $claimId;
    $stmt = $db->prepare('UPDATE claims SET ' . implode(', ', $sets) . ' WHERE id = ?');
    $stmt->execute($params);
}

function qm_claim_labels(array $claim): array
{
    return [
        'error_category' => qm_label(qm_error_category_options(), $claim['error_category'] ?? null),
        'error_pattern' => qm_label(qm_error_pattern_options(), $claim['error_pattern'] ?? null),
        'process_area' => qm_label(qm_process_area_options(), $claim['process_area'] ?? null),
        'root_cause_category' => qm_label(qm_root_cause_category_options(), $claim['root_cause_category'] ?? null),
    ];
}

function qm_claim_has_classification(array $claim): bool
{
    foreach (qm_claim_field_names() as $field) {
        if (trim((string)($claim[$field] ?? '')) !== '') {
            return true;
        }
    }

    return false;
}

function qm_find_similar_claims(PDO $db, array $claim, int $days = 90, int $limit = 8): array
{
    if (!qm_claim_fields_enabled()) {
        return [];
    }

    $claimId = (int)($claim['id'] ?? 0);
    if ($claimId <= 0) {
        return [];
    }

    $errorCategory = trim((string)($claim['error_category'] ?? ''));
    $errorPattern = trim((string)($claim['error_pattern'] ?? ''));
    $processArea = trim((string)($claim['process_area'] ?? ''));
    $articleNumber = trim((string)($claim['article_number'] ?? ''));
    $partnerName = trim((string)($claim['partner_name'] ?? ''));

    if ($errorCategory === '' && $errorPattern === '' && $articleNumber === '' && $partnerName === '') {
        return [];
    }

    $minDate = date('Y-m-d', strtotime('-' . max(1, $days) . ' days'));

    $conditions = [];
    $whereParams = [$claimId, $minDate];

    if ($errorCategory !== '') {
        $conditions[] = 'c.error_category = ?';
        $whereParams[] = $errorCategory;
    }

    if ($errorPattern !== '') {
        $conditions[] = 'c.error_pattern = ?';
        $whereParams[] = $errorPattern;
    }

    if ($processArea !== '' && $errorCategory !== '') {
        $conditions[] = '(c.process_area = ? AND c.error_category = ?)';
        $whereParams[] = $processArea;
        $whereParams[] = $errorCategory;
    }

    if ($articleNumber !== '') {
        $conditions[] = 'c.article_number = ?';
        $whereParams[] = $articleNumber;
    }

    if ($partnerName !== '' && $errorCategory !== '') {
        $conditions[] = '(c.partner_name = ? AND c.error_category = ?)';
        $whereParams[] = $partnerName;
        $whereParams[] = $errorCategory;
    }

    if (!$conditions) {
        return [];
    }

    $limit = max(1, min(50, (int)$limit));

    $sql = "SELECT c.id, c.claim_number, c.short_description, c.partner_name, c.article_number,
                   c.claim_date, c.status, c.priority, c.error_category, c.error_pattern,
                   c.process_area, c.root_cause_category,
                   (
                       CASE WHEN c.error_category = ? AND ? <> '' THEN 25 ELSE 0 END +
                       CASE WHEN c.error_pattern = ? AND ? <> '' THEN 35 ELSE 0 END +
                       CASE WHEN c.process_area = ? AND ? <> '' THEN 15 ELSE 0 END +
                       CASE WHEN c.article_number = ? AND ? <> '' THEN 15 ELSE 0 END +
                       CASE WHEN c.partner_name = ? AND ? <> '' THEN 10 ELSE 0 END
                   ) AS similarity_score
            FROM claims c
            WHERE c.id <> ?
              AND c.claim_date >= ?
              AND (" . implode(' OR ', $conditions) . ")
            HAVING similarity_score >= 25
            ORDER BY similarity_score DESC, c.claim_date DESC, c.id DESC
            LIMIT " . $limit;

    $scoreParams = [
        $errorCategory, $errorCategory,
        $errorPattern, $errorPattern,
        $processArea, $processArea,
        $articleNumber, $articleNumber,
        $partnerName, $partnerName,
    ];

    try {
        $stmt = $db->prepare($sql);
        $stmt->execute(array_merge($scoreParams, $whereParams));
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('QM Wiederholfehler Suche fehlgeschlagen: ' . $e->getMessage());
        return [];
    }
}

function qm_repeat_level(array $similarClaims): string
{
    $count = count($similarClaims);

    if ($count >= 3) {
        return 'red';
    }

    if ($count >= 1) {
        return 'yellow';
    }

    return 'green';
}

function qm_repeat_badge(string $level): string
{
    return match ($level) {
        'red' => '<span class="badge bg-danger">Rot · Maßnahme prüfen</span>',
        'yellow' => '<span class="badge bg-warning text-dark">Gelb · Wiederholfehler beobachten</span>',
        default => '<span class="badge bg-success">Grün · Kein Muster erkannt</span>',
    };
}

function qm_effectiveness_hint(array $similarClaims): string
{
    $level = qm_repeat_level($similarClaims);

    return match ($level) {
        'red' => 'Mehrere ähnliche Reklamationen wurden gefunden. Die Ursache oder Maßnahme sollte durch das Qualitätsmanagement überprüft werden.',
        'yellow' => 'Es gibt ähnliche Fälle. Bitte prüfen, ob es sich um einen Wiederholfehler handelt.',
        default => 'Aktuell wurde kein auffälliger Wiederholfehler erkannt.',
    };
}


/**
 * KI-Light / intelligente Wiederholfehler-Analyse
 * ------------------------------------------------
 * Diese Funktionen arbeiten bewusst lokal ohne externe API.
 * Vorteil: keine Datenschutzfreigabe nötig, kein API-Key nötig, sofort nutzbar.
 * Später kann hier eine echte KI-API ergänzt werden.
 */

function qm_ai_tables_enabled(): bool
{
    return db_table_exists('claim_ai_analysis') && db_table_exists('claim_ai_similarity');
}

function qm_normalize_text(?string $text): string
{
    $text = strtolower((string)$text);
    $text = str_replace(['ä', 'ö', 'ü', 'ß'], ['ae', 'oe', 'ue', 'ss'], $text);
    $text = preg_replace('/[^a-z0-9\s\-]/', ' ', $text) ?? $text;
    $text = preg_replace('/\s+/', ' ', $text) ?? $text;
    return trim($text);
}

function qm_keyword_groups(): array
{
    return [
        'Etikett / Kennzeichnung' => ['etikett', 'label', 'kennzeichnung', 'beklebt', 'gelabelt', 'barcode', 'qr', 'schild'],
        'Menge / Fehlmenge' => ['fehlmenge', 'menge', 'unterlieferung', 'zu wenig', 'stueckzahl', 'anzahl', 'differenz'],
        'Falscher Artikel' => ['falscher artikel', 'falsche ware', 'artikel falsch', 'verwechslung', 'falsch kommissioniert'],
        'Verpackung beschädigt' => ['verpackung', 'beschaedigt', 'karton', 'palette', 'bruch', 'riss', 'deformiert'],
        'Transport / Handling' => ['transport', 'handling', 'stapler', 'ladung', 'verladung', 'schaden beim transport'],
        'Dokumentation / Papiere' => ['dokument', 'papiere', 'lieferschein', 'cmr', 'rechnung', 'zertifikat', 'beleg'],
        'Materialqualität' => ['material', 'qualitaet', 'spezifikation', 'massabweichung', 'defekt', 'fehlerhaft'],
        'Liefertermin / Verzug' => ['verzug', 'verspaetet', 'termin', 'liefertermin', 'zu spaet', 'delay'],
        'System / IT / Prozess' => ['system', 'schnittstelle', 'prozess', 'workflow', 'it', 'buchung', 'scanner'],
    ];
}

function qm_detect_keyword_groups(string $text): array
{
    $text = qm_normalize_text($text);
    $hits = [];

    foreach (qm_keyword_groups() as $group => $words) {
        foreach ($words as $word) {
            if ($word !== '' && str_contains($text, qm_normalize_text($word))) {
                $hits[] = $group;
                break;
            }
        }
    }

    return array_values(array_unique($hits));
}

function qm_claim_analysis_text(array $claim): string
{
    return implode(' ', [
        (string)($claim['claim_number'] ?? ''),
        (string)($claim['short_description'] ?? ''),
        (string)($claim['problem_description'] ?? ''),
        (string)($claim['article_number'] ?? ''),
        (string)($claim['article_name'] ?? ''),
        (string)($claim['partner_name'] ?? ''),
        (string)($claim['error_category'] ?? ''),
        (string)($claim['error_pattern'] ?? ''),
        (string)($claim['process_area'] ?? ''),
        (string)($claim['root_cause_category'] ?? ''),
    ]);
}

function qm_text_overlap_score(string $a, string $b): int
{
    $a = qm_normalize_text($a);
    $b = qm_normalize_text($b);

    if ($a === '' || $b === '') {
        return 0;
    }

    $ignore = ['und','oder','der','die','das','ein','eine','mit','ohne','von','vom','bei','ist','sind','wurde','wurden','nicht','auf','im','in','am','an','zu','zur','zum','den','dem'];
    $tokensA = array_values(array_filter(array_unique(explode(' ', $a)), fn($w) => strlen($w) >= 4 && !in_array($w, $ignore, true)));
    $tokensB = array_values(array_filter(array_unique(explode(' ', $b)), fn($w) => strlen($w) >= 4 && !in_array($w, $ignore, true)));

    if (!$tokensA || !$tokensB) {
        return 0;
    }

    $intersect = array_intersect($tokensA, $tokensB);
    $ratio = count($intersect) / max(1, min(count($tokensA), count($tokensB)));

    return (int)min(20, round($ratio * 20));
}

function qm_similarity_score_for_claims(array $claim, array $other): array
{
    $score = 0;
    $reasons = [];

    if (($claim['error_pattern'] ?? '') !== '' && ($claim['error_pattern'] ?? '') === ($other['error_pattern'] ?? '')) {
        $score += 35;
        $reasons[] = 'gleiches Fehlerbild';
    }

    if (($claim['error_category'] ?? '') !== '' && ($claim['error_category'] ?? '') === ($other['error_category'] ?? '')) {
        $score += 25;
        $reasons[] = 'gleiche Fehlerkategorie';
    }

    if (($claim['process_area'] ?? '') !== '' && ($claim['process_area'] ?? '') === ($other['process_area'] ?? '')) {
        $score += 15;
        $reasons[] = 'gleicher Prozessbereich';
    }

    if (($claim['article_number'] ?? '') !== '' && ($claim['article_number'] ?? '') === ($other['article_number'] ?? '')) {
        $score += 15;
        $reasons[] = 'gleiche Artikelnummer';
    }

    if (($claim['partner_name'] ?? '') !== '' && ($claim['partner_name'] ?? '') === ($other['partner_name'] ?? '')) {
        $score += 10;
        $reasons[] = 'gleicher Partner';
    }

    $textA = qm_claim_analysis_text($claim);
    $textB = qm_claim_analysis_text($other);

    $groupsA = qm_detect_keyword_groups($textA);
    $groupsB = qm_detect_keyword_groups($textB);
    $commonGroups = array_values(array_intersect($groupsA, $groupsB));

    if ($commonGroups) {
        $score += min(20, 10 + (count($commonGroups) * 5));
        $reasons[] = 'ähnliche Text-/Keyword-Gruppe: ' . implode(', ', array_slice($commonGroups, 0, 2));
    }

    $overlap = qm_text_overlap_score($textA, $textB);
    if ($overlap > 0) {
        $score += $overlap;
        $reasons[] = 'ähnlicher Freitext';
    }

    $score = min(100, $score);

    return [
        'score' => $score,
        'reason' => implode('; ', array_unique($reasons)),
    ];
}

function qm_fetch_claim_for_ai(PDO $db, int $claimId): ?array
{
    $stmt = $db->prepare('SELECT * FROM claims WHERE id = ? LIMIT 1');
    $stmt->execute([$claimId]);
    $claim = $stmt->fetch();

    return $claim ?: null;
}

function qm_run_local_ai_analysis(PDO $db, int $claimId, int $days = 180): array
{
    if (!qm_ai_tables_enabled()) {
        return [
            'ok' => false,
            'message' => 'KI-Tabellen sind nicht vorhanden. Bitte SQL-Migration prüfen.',
        ];
    }

    $claim = qm_fetch_claim_for_ai($db, $claimId);
    if (!$claim) {
        return [
            'ok' => false,
            'message' => 'Reklamation wurde nicht gefunden.',
        ];
    }

    $minDate = date('Y-m-d', strtotime('-' . max(1, $days) . ' days'));

    $stmt = $db->prepare("SELECT *
        FROM claims
        WHERE id <> ?
          AND claim_date >= ?
        ORDER BY claim_date DESC, id DESC
        LIMIT 250");
    $stmt->execute([$claimId, $minDate]);
    $others = $stmt->fetchAll();

    $similar = [];
    foreach ($others as $other) {
        $result = qm_similarity_score_for_claims($claim, $other);
        if ((int)$result['score'] >= 35) {
            $similar[] = [
                'claim' => $other,
                'score' => (int)$result['score'],
                'reason' => $result['reason'] ?: 'Ähnlichkeit in strukturierten Feldern oder Freitext',
            ];
        }
    }

    usort($similar, fn($a, $b) => $b['score'] <=> $a['score']);
    $similar = array_slice($similar, 0, 10);

    $level = count($similar) >= 3 ? 'red' : (count($similar) >= 1 ? 'yellow' : 'green');

    $detectedGroups = qm_detect_keyword_groups(qm_claim_analysis_text($claim));
    $detectedPattern = $detectedGroups[0] ?? qm_label(qm_error_pattern_options(), $claim['error_pattern'] ?? '');

    $summary = 'KI-Light Analyse: ';
    if ($detectedPattern && $detectedPattern !== '-') {
        $summary .= 'Erkanntes Fehlerbild: ' . $detectedPattern . '. ';
    }
    $summary .= 'Es wurden ' . count($similar) . ' ähnliche Reklamation(en) im Betrachtungszeitraum gefunden.';

    $recommendation = match ($level) {
        'red' => 'Mehrere ähnliche Fälle wurden erkannt. Das Qualitätsmanagement sollte prüfen, ob Ursache und getroffene Maßnahmen ausreichend wirksam sind. Falls bereits Maßnahmen abgeschlossen wurden, sollten diese überarbeitet oder durch zusätzliche Prüf-/Prozessmaßnahmen ergänzt werden.',
        'yellow' => 'Mindestens ein ähnlicher Fall wurde erkannt. Bitte fachlich prüfen, ob es sich um einen Wiederholfehler handelt und ob bestehende Maßnahmen ausreichend sind.',
        default => 'Aktuell wurde kein auffälliges Wiederholfehler-Muster erkannt. Die Reklamation kann normal weiterbearbeitet werden.',
    };

    $db->beginTransaction();

    try {
        $delete = $db->prepare('DELETE FROM claim_ai_similarity WHERE claim_id = ?');
        $delete->execute([$claimId]);

        $insertSim = $db->prepare('INSERT INTO claim_ai_similarity
            (claim_id, similar_claim_id, similarity_score, reason, created_at)
            VALUES (?, ?, ?, ?, NOW())');

        foreach ($similar as $row) {
            $insertSim->execute([
                $claimId,
                (int)$row['claim']['id'],
                (float)$row['score'],
                $row['reason'],
            ]);
        }

        $insertAnalysis = $db->prepare('INSERT INTO claim_ai_analysis
            (claim_id, ai_summary, detected_error_pattern, effectiveness_warning, recommendation, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())');

        $insertAnalysis->execute([
            $claimId,
            $summary,
            $detectedPattern,
            $level,
            $recommendation,
        ]);

        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }

    return [
        'ok' => true,
        'level' => $level,
        'similar_count' => count($similar),
        'message' => 'KI-Light Analyse wurde erstellt.',
    ];
}

function qm_latest_ai_analysis(PDO $db, int $claimId): ?array
{
    if (!qm_ai_tables_enabled()) {
        return null;
    }

    try {
        $stmt = $db->prepare('SELECT * FROM claim_ai_analysis WHERE claim_id = ? ORDER BY created_at DESC, id DESC LIMIT 1');
        $stmt->execute([$claimId]);
        $row = $stmt->fetch();
        return $row ?: null;
    } catch (Throwable $e) {
        error_log('QM KI Analyse laden fehlgeschlagen: ' . $e->getMessage());
        return null;
    }
}

function qm_latest_ai_similarities(PDO $db, int $claimId): array
{
    if (!qm_ai_tables_enabled()) {
        return [];
    }

    try {
        $stmt = $db->prepare("SELECT s.*, c.claim_number, c.short_description, c.claim_date, c.status
            FROM claim_ai_similarity s
            JOIN claims c ON c.id = s.similar_claim_id
            WHERE s.claim_id = ?
            ORDER BY s.similarity_score DESC, s.created_at DESC
            LIMIT 10");
        $stmt->execute([$claimId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('QM KI Ähnlichkeiten laden fehlgeschlagen: ' . $e->getMessage());
        return [];
    }
}

function qm_ai_warning_badge(?string $level): string
{
    return match ((string)$level) {
        'red' => '<span class="badge bg-danger">Rot · Maßnahme prüfen</span>',
        'yellow' => '<span class="badge bg-warning text-dark">Gelb · Wiederholfehler prüfen</span>',
        'green' => '<span class="badge bg-success">Grün · unauffällig</span>',
        default => '<span class="badge bg-secondary">Noch nicht analysiert</span>',
    };
}


/**
 * KI-Light Feedback / Trainingsdatenbasis
 * ---------------------------------------
 * QM bewertet, ob die lokale Analyse fachlich stimmt.
 * Diese Bewertungen sind später die Grundlage für eigene KI-Regeln,
 * Fine-Tuning oder lokale Modelle.
 */

function qm_feedback_enabled(): bool
{
    return db_table_exists('claim_ai_feedback');
}

function qm_feedback_label(string $value): string
{
    return match ($value) {
        'analysis_correct' => 'KI-Bewertung stimmt',
        'analysis_wrong' => 'KI-Bewertung falsch',
        'repeat_confirmed' => 'Wiederholfehler bestätigt',
        'repeat_rejected' => 'Kein Wiederholfehler',
        'measure_effective' => 'Maßnahme wirksam',
        'measure_not_effective' => 'Maßnahme muss geprüft werden',
        default => $value,
    };
}

function qm_feedback_badge(string $value): string
{
    return match ($value) {
        'analysis_correct', 'repeat_rejected', 'measure_effective' => '<span class="badge bg-success">' . e(qm_feedback_label($value)) . '</span>',
        'analysis_wrong', 'repeat_confirmed', 'measure_not_effective' => '<span class="badge bg-danger">' . e(qm_feedback_label($value)) . '</span>',
        default => '<span class="badge bg-secondary">' . e(qm_feedback_label($value)) . '</span>',
    };
}

function qm_latest_ai_feedback(PDO $db, int $claimId): array
{
    if (!qm_feedback_enabled()) {
        return [];
    }

    try {
        $stmt = $db->prepare('SELECT *
            FROM claim_ai_feedback
            WHERE claim_id = ?
            ORDER BY created_at DESC, id DESC
            LIMIT 10');
        $stmt->execute([$claimId]);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        error_log('QM Feedback laden fehlgeschlagen: ' . $e->getMessage());
        return [];
    }
}

function qm_save_ai_feedback(PDO $db, int $claimId, ?int $analysisId, int $userId, string $feedbackValue, ?string $note = null): void
{
    if (!qm_feedback_enabled()) {
        throw new RuntimeException('Feedback-Tabelle fehlt. Bitte SQL-Migration ausführen.');
    }

    $allowed = [
        'analysis_correct',
        'analysis_wrong',
        'repeat_confirmed',
        'repeat_rejected',
        'measure_effective',
        'measure_not_effective',
    ];

    if (!in_array($feedbackValue, $allowed, true)) {
        throw new InvalidArgumentException('Ungültiger Feedback-Wert.');
    }

    $feedbackType = match ($feedbackValue) {
        'analysis_correct', 'analysis_wrong' => 'analysis_quality',
        'repeat_confirmed', 'repeat_rejected' => 'repeat_error',
        'measure_effective', 'measure_not_effective' => 'measure_effectiveness',
        default => 'general',
    };

    $note = trim((string)$note);
    $note = $note !== '' ? mb_substr($note, 0, 2000, 'UTF-8') : null;

    $stmt = $db->prepare('INSERT INTO claim_ai_feedback
        (claim_id, analysis_id, user_id, feedback_type, feedback_value, note, created_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([
        $claimId,
        $analysisId ?: null,
        $userId ?: null,
        $feedbackType,
        $feedbackValue,
        $note,
    ]);

    if ($analysisId && db_column_exists('claim_ai_analysis', 'feedback_status')) {
        $sets = ['feedback_status = ?', 'feedback_at = NOW()'];
        $params = [$feedbackValue];

        if (db_column_exists('claim_ai_analysis', 'feedback_note')) {
            $sets[] = 'feedback_note = ?';
            $params[] = $note;
        }

        if (db_column_exists('claim_ai_analysis', 'feedback_user_id')) {
            $sets[] = 'feedback_user_id = ?';
            $params[] = $userId ?: null;
        }

        $params[] = $analysisId;
        $update = $db->prepare('UPDATE claim_ai_analysis SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $update->execute($params);
    }
}

function qm_training_export_rows(PDO $db, int $limit = 500): array
{
    if (!qm_feedback_enabled()) {
        return [];
    }

    $limit = max(1, min(2000, (int)$limit));

    $sql = "SELECT f.*, a.ai_summary, a.detected_error_pattern, a.effectiveness_warning, a.recommendation,
                   c.claim_number, c.short_description, c.problem_description, c.claim_date, c.status,
                   c.priority, c.partner_name, c.article_number, c.article_name,
                   c.error_category, c.error_pattern, c.process_area, c.root_cause_category
            FROM claim_ai_feedback f
            JOIN claims c ON c.id = f.claim_id
            LEFT JOIN claim_ai_analysis a ON a.id = f.analysis_id
            ORDER BY f.created_at DESC, f.id DESC
            LIMIT " . $limit;

    try {
        return $db->query($sql)->fetchAll();
    } catch (Throwable $e) {
        error_log('QM Trainingsdaten Export fehlgeschlagen: ' . $e->getMessage());
        return [];
    }
}
