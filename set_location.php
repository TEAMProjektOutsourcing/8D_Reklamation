<?php
require_once __DIR__ . '/auth.php';
require_login();
require_csrf();

$value = (string)($_POST['standort_id'] ?? '');
$user = current_user();

if (!locations_enabled()) {
    redirect('dashboard.php');
}

if ($value === 'all' && $user && $user['role'] === 'admin') {
    $_SESSION['standort_id'] = 'all';
    redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
}

$locationId = (int)$value;
if ($locationId > 0 && can_access_location_id($locationId)) {
    $_SESSION['standort_id'] = $locationId;
}

redirect($_SERVER['HTTP_REFERER'] ?? 'dashboard.php');
