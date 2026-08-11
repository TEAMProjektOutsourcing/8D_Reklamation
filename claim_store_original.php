<?php
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!can_edit()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$db = pdo();
$user = current_user();

$claimType = (string)($_POST['claim_type'] ?? 'customer');
$allowedTypes = ['customer','supplier','internal'];
if (!in_array($claimType, $allowedTypes, true)) {
    $claimType = 'customer';
}

$partnerName = trim((string)($_POST['partner_name'] ?? ''));
$shortDescription = trim((string)($_POST['short_description'] ?? ''));
$claimDate = (string)($_POST['claim_date'] ?? date('Y-m-d'));
$priority = (string)($_POST['priority'] ?? 'medium');
$responsibleUserId = $_POST['responsible_user_id'] !== '' ? (int)$_POST['responsible_user_id'] : null;
$quantity = $_POST['quantity_affected'] !== '' ? (float)$_POST['quantity_affected'] : null;
$deliveryDate = $_POST['delivery_date'] !== '' ? (string)$_POST['delivery_date'] : null;
$standortId = locations_enabled() ? (int)($_POST['standort_id'] ?? selected_location_id() ?? 0) : null;
$sourceModule = trim((string)($_POST['source_module'] ?? '')) ?: null;
$sourceNumber = trim((string)($_POST['source_number'] ?? '')) ?: null;
$sourceUrl = trim((string)($_POST['source_url'] ?? '')) ?: null;

if (locations_enabled() && !can_access_location_id($standortId)) {
    flash('danger', 'Du hast keine Berechtigung für diesen Standort.');
    redirect('claim_create.php');
}

if ($responsibleUserId !== null && locations_enabled()) {
    $availableUserIds = array_map(static fn(array $row): int => (int)$row['id'], get_users_for_select($standortId));
    if (!in_array($responsibleUserId, $availableUserIds, true)) {
        $responsibleUserId = null;
    }
}

if ($partnerName === '' || $shortDescription === '') {
    flash('danger', 'Bitte Partner und Kurzbeschreibung ausfüllen.');
    redirect('claim_create.php');
}

try {
    $db->beginTransaction();

    if (locations_enabled()) {
        $stmt = $db->prepare("INSERT INTO claims
            (claim_number, standort_id, claim_type, partner_name, article_number, article_name, quantity_affected, delivery_date, claim_date, priority, status, short_description, problem_description, responsible_user_id, source_module, source_number, source_url, created_by)
            VALUES ('TEMP', ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([
            $standortId,
            $claimType,
            $partnerName,
            trim((string)($_POST['article_number'] ?? '')) ?: null,
            trim((string)($_POST['article_name'] ?? '')) ?: null,
            $quantity,
            $deliveryDate,
            $claimDate,
            $priority,
            $shortDescription,
            trim((string)($_POST['problem_description'] ?? '')) ?: null,
            $responsibleUserId,
            $sourceModule,
            $sourceNumber,
            $sourceUrl,
            (int)$user['id'],
        ]);
    } else {
        $stmt = $db->prepare("INSERT INTO claims
            (claim_number, claim_type, partner_name, article_number, article_name, quantity_affected, delivery_date, claim_date, priority, status, short_description, problem_description, responsible_user_id, created_by)
            VALUES ('TEMP', ?, ?, ?, ?, ?, ?, ?, ?, 'new', ?, ?, ?, ?)");
        $stmt->execute([
            $claimType,
            $partnerName,
            trim((string)($_POST['article_number'] ?? '')) ?: null,
            trim((string)($_POST['article_name'] ?? '')) ?: null,
            $quantity,
            $deliveryDate,
            $claimDate,
            $priority,
            $shortDescription,
            trim((string)($_POST['problem_description'] ?? '')) ?: null,
            $responsibleUserId,
            (int)$user['id'],
        ]);
    }

    $claimId = (int)$db->lastInsertId();
    $claimNumber = next_claim_number($claimId, $standortId);

    $upd = $db->prepare('UPDATE claims SET claim_number = ? WHERE id = ?');
    $upd->execute([$claimNumber, $claimId]);

    $stepStmt = $db->prepare('INSERT INTO claim_steps (claim_id, step_key, title, description) VALUES (?, ?, ?, ?)');
    foreach (claim_step_definitions() as $key => $def) {
        $stepStmt->execute([$claimId, $key, $def['title'], $def['description']]);
    }

    log_history($claimId, 'Reklamation erstellt', $claimNumber . ' wurde angelegt.' . ($sourceNumber ? "\nQuelle: " . ($sourceModule ?: 'unbekannt') . ' · ' . $sourceNumber : ''));

    $db->commit();
    flash('success', 'Reklamation wurde erstellt.');
    redirect('claim_view.php?id=' . $claimId);
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    if (APP_DEBUG) {
        die('Fehler: ' . e($e->getMessage()));
    }
    flash('danger', 'Reklamation konnte nicht erstellt werden.');
    redirect('claim_create.php');
}
