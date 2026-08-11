<?php
require_once __DIR__ . '/auth.php';
$user = current_user();
$currentPage = basename($_SERVER['SCRIPT_NAME'] ?? '');
$myOpenActionsCount = $user ? my_open_action_count((int)$user['id']) : 0;
$myCriticalActionsCount = $user ? my_critical_action_count((int)$user['id']) : 0;
$locationsEnabled = $user ? locations_enabled() : false;
$allowedLocations = $locationsEnabled ? user_allowed_locations((int)$user['id']) : [];
$selectedLocationId = $locationsEnabled ? selected_location_id() : null;
$selectedLocation = $locationsEnabled ? selected_location() : null;
function nav_active(string $page, string $currentPage): string { return $page === $currentPage ? ' active' : ''; }
function nav_active_any(array $pages, string $currentPage): string { return in_array($currentPage, $pages, true) ? ' active' : ''; }
$adminPages = [
    'users.php',
    'user_form.php',
    'locations.php',
    'location_save.php',
    'step_templates.php',
    'step_template_compare.php',
];
?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e(APP_NAME) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="assets/style.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark sticky-top">
    <div class="container-fluid">
        <a class="navbar-brand fw-bold" href="dashboard.php">8D Tool</a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav">
            <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
            <?php if ($user): ?>
                <ul class="navbar-nav me-auto mb-2 mb-lg-0">
                    <li class="nav-item"><a class="nav-link<?= nav_active('dashboard.php', $currentPage) ?>" href="dashboard.php">Dashboard</a></li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('claims.php', $currentPage) ?>" href="claims.php">Reklamationen</a></li>
                    <li class="nav-item"><a class="nav-link<?= nav_active('auswertungen.php', $currentPage) ?>" href="auswertungen.php">Auswertung</a></li>
                    <li class="nav-item">
                        <a class="nav-link position-relative<?= nav_active('my_actions.php', $currentPage) ?>" href="my_actions.php">
                            Meine Maßnahmen
                            <?php if ($myOpenActionsCount > 0): ?>
                                <span class="nav-action-badge badge rounded-pill <?= $myCriticalActionsCount > 0 ? 'bg-danger' : 'bg-primary' ?>" title="<?= (int)$myOpenActionsCount ?> offene Maßnahme<?= $myOpenActionsCount === 1 ? '' : 'n' ?>">
                                    <?= e(nav_count_label((int)$myOpenActionsCount)) ?>
                                </span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php if (can_edit()): ?>
                        <li class="nav-item"><a class="nav-link<?= nav_active('claim_create.php', $currentPage) ?>" href="claim_create.php">Neue Reklamation</a></li>
                    <?php endif; ?>
                    <?php if (is_admin()): ?>
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle<?= nav_active_any($adminPages, $currentPage) ?>" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                                Administration
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item<?= nav_active('users.php', $currentPage) ?><?= nav_active('user_form.php', $currentPage) ?>" href="users.php">Benutzer</a></li>
                                <li><a class="dropdown-item<?= nav_active('locations.php', $currentPage) ?>" href="locations.php">Standorte</a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item<?= nav_active_any(['step_templates.php', 'step_template_compare.php'], $currentPage) ?>" href="step_templates.php">8D-Vorlagen</a></li>
                                <li><a class="dropdown-item<?= nav_active('step_template_compare.php', $currentPage) ?>" href="step_template_compare.php">Versionen vergleichen</a></li>
                            </ul>
                        </li>
                    <?php endif; ?>
                </ul>
                <?php if (is_admin() && $locationsEnabled && $allowedLocations): ?>
                    <form method="post" action="set_location.php" class="d-flex align-items-center me-lg-3 mb-2 mb-lg-0 location-switcher-form">
                        <?= csrf_field() ?>
                        <select name="standort_id" class="form-select form-select-sm location-switcher" onchange="this.form.submit()" aria-label="Standort wählen">
                            <option value="all" <?= $selectedLocationId === null ? 'selected' : '' ?>>Alle Standorte</option>
                            <?php foreach ($allowedLocations as $loc): ?>
                                <option value="<?= (int)$loc['id'] ?>" <?= $selectedLocationId === (int)$loc['id'] ? 'selected' : '' ?>><?= e($loc['kuerzel']) ?> · <?= e($loc['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </form>
                <?php elseif (is_admin() && $locationsEnabled): ?>
                    <a href="run_location_migration.php" class="btn btn-sm btn-warning me-lg-3 mb-2 mb-lg-0">Standorte zuweisen</a>
                <?php endif; ?>

                <div class="dropdown">
                    <button class="btn btn-outline-light btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown">
                        <?= e($user['name']) ?> · <?= e(role_label($user['role'])) ?>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="profile.php">Mein Profil</a></li>
                        <?php if (is_admin()): ?>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="users.php">Benutzer verwalten</a></li>
                            <li><a class="dropdown-item" href="locations.php">Standorte verwalten</a></li>
                            <li><a class="dropdown-item" href="step_templates.php">8D-Vorlagen</a></li>
                            <li><a class="dropdown-item" href="step_template_compare.php">Versionen vergleichen</a></li>
                        <?php endif; ?>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item" href="logout.php">Logout</a></li>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
    </div>
</nav>
<main class="container-fluid py-4">
    <?php render_flash(); ?>
