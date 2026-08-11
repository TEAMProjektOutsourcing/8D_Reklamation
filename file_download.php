<?php
require_once __DIR__ . '/auth.php';
require_login();

$id = (int)($_GET['id'] ?? 0);
$stmt = pdo()->prepare('SELECT * FROM claim_files WHERE id = ?');
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) {
    http_response_code(404);
    die('Datei nicht gefunden.');
}

require_claim_access((int)$file['claim_id']);

$path = APP_UPLOAD_DIR . '/' . $file['file_path'];
if (!is_file($path)) {
    http_response_code(404);
    die('Datei liegt nicht mehr auf dem Server.');
}

header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . basename($file['original_name']) . '"');
readfile($path);
exit;
