<?php
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

if (!can_edit()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$claimId = (int)($_POST['claim_id'] ?? 0);
if ($claimId > 0) { require_claim_access($claimId); }
if ($claimId <= 0 || empty($_FILES['file'])) {
    flash('danger', 'Keine Datei ausgewählt.');
    redirect('claim_view.php?id=' . $claimId);
}

$file = $_FILES['file'];
if ($file['error'] !== UPLOAD_ERR_OK) {
    flash('danger', 'Upload fehlgeschlagen. Fehlercode: ' . $file['error']);
    redirect('claim_view.php?id=' . $claimId);
}

$allowedExtensions = ['jpg','jpeg','png','webp','pdf','doc','docx','xls','xlsx','txt'];
$originalName = (string)$file['name'];
$ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

if (!in_array($ext, $allowedExtensions, true)) {
    flash('danger', 'Dateityp ist nicht erlaubt.');
    redirect('claim_view.php?id=' . $claimId);
}

$stepKey = strtoupper(trim((string)($_POST['step_key'] ?? '')));
if (!array_key_exists($stepKey, claim_step_definitions())) {
    $stepKey = '';
}

$category = trim((string)($_POST['category'] ?? 'other'));
if (!array_key_exists($category, file_category_options())) {
    $category = 'other';
}

$caption = trim((string)($_POST['caption'] ?? ''));
if (function_exists('mb_strlen') && mb_strlen($caption) > 2000) {
    $caption = mb_substr($caption, 0, 2000);
} elseif (!function_exists('mb_strlen') && strlen($caption) > 2000) {
    $caption = substr($caption, 0, 2000);
}

$targetDir = APP_UPLOAD_DIR . '/' . $claimId;
if (!is_dir($targetDir) && !mkdir($targetDir, 0775, true) && !is_dir($targetDir)) {
    flash('danger', 'Upload-Ordner konnte nicht erstellt werden.');
    redirect('claim_view.php?id=' . $claimId);
}

$storedName = date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
$targetPath = $targetDir . '/' . $storedName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    flash('danger', 'Datei konnte nicht gespeichert werden.');
    redirect('claim_view.php?id=' . $claimId);
}

$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($targetPath) ?: null;
$relativePath = $claimId . '/' . $storedName;
$user = current_user();

$hasPhotoColumns = db_column_exists('claim_files', 'step_key') && db_column_exists('claim_files', 'category') && db_column_exists('claim_files', 'caption');

if ($hasPhotoColumns) {
    $stmt = pdo()->prepare('INSERT INTO claim_files (claim_id, step_key, category, caption, original_name, stored_name, file_path, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$claimId, $stepKey !== '' ? $stepKey : null, $category, $caption !== '' ? $caption : null, $originalName, $storedName, $relativePath, $mime, (int)$file['size'], (int)$user['id']]);
} else {
    $stmt = pdo()->prepare('INSERT INTO claim_files (claim_id, original_name, stored_name, file_path, mime_type, size_bytes, uploaded_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute([$claimId, $originalName, $storedName, $relativePath, $mime, (int)$file['size'], (int)$user['id']]);
}

$details = $originalName;
if ($hasPhotoColumns) {
    $meta = [];
    if ($stepKey !== '') $meta[] = 'D-Schritt: ' . $stepKey;
    $meta[] = 'Kategorie: ' . file_category_label($category);
    if ($caption !== '') $meta[] = 'Beschreibung: ' . $caption;
    $details .= "\n" . implode("\n", $meta);
}

log_history($claimId, is_image_mime($mime) ? 'Foto hochgeladen' : 'Datei hochgeladen', $details);
flash('success', is_image_mime($mime) ? 'Foto wurde hochgeladen.' : 'Datei wurde hochgeladen.');
redirect('claim_view.php?id=' . $claimId . '#files');
