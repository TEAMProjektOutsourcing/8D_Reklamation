<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/qm_helper.php';

require_login();

if (!is_admin()) {
    http_response_code(403);
    die('Keine Berechtigung.');
}

$db = pdo();
$rows = qm_training_export_rows($db, 1000);

$filename = '8d_qm_training_export_' . date('Ymd_His') . '.jsonl';

header('Content-Type: application/x-ndjson; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

foreach ($rows as $row) {
    $input = [
        'claim_number' => (string)($row['claim_number'] ?? ''),
        'title' => (string)($row['short_description'] ?? ''),
        'problem_description' => (string)($row['problem_description'] ?? ''),
        'claim_date' => (string)($row['claim_date'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'priority' => (string)($row['priority'] ?? ''),
        'article_number' => (string)($row['article_number'] ?? ''),
        'article_name' => (string)($row['article_name'] ?? ''),
        'error_category' => (string)($row['error_category'] ?? ''),
        'error_pattern' => (string)($row['error_pattern'] ?? ''),
        'process_area' => (string)($row['process_area'] ?? ''),
        'root_cause_category' => (string)($row['root_cause_category'] ?? ''),
        'ki_light_summary' => (string)($row['ai_summary'] ?? ''),
        'ki_light_warning' => (string)($row['effectiveness_warning'] ?? ''),
        'ki_light_recommendation' => (string)($row['recommendation'] ?? ''),
    ];

    $output = [
        'feedback_type' => (string)($row['feedback_type'] ?? ''),
        'feedback_value' => (string)($row['feedback_value'] ?? ''),
        'feedback_note' => (string)($row['note'] ?? ''),
    ];

    echo json_encode([
        'input' => $input,
        'output' => $output,
        'created_at' => (string)($row['created_at'] ?? ''),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
}

exit;
