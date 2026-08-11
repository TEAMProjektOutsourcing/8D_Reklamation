<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if (!db_table_exists('standorte')) {
    flash('warning', 'Die Standort-Tabellen sind noch nicht vorhanden.');
    redirect('locations.php');
}

$id = (int)($_POST['id'] ?? 0);
$mode = (string)($_POST['mode'] ?? 'archive');
$location = location_by_id($id);
if (!$location) {
    flash('danger', 'Standort wurde nicht gefunden.');
    redirect('locations.php');
}

try {
    if ($mode === 'reactivate') {
        $stmt = pdo()->prepare('UPDATE standorte SET aktiv = 1 WHERE id = ?');
        $stmt->execute([$id]);
        flash('success', 'Standort wurde wieder aktiviert.');
    } else {
        $activeCount = (int)pdo()->query('SELECT COUNT(*) FROM standorte WHERE aktiv = 1')->fetchColumn();
        if ((int)$location['aktiv'] === 1 && $activeCount <= 1) {
            flash('danger', 'Der letzte aktive Standort kann nicht archiviert werden. Lege zuerst einen weiteren aktiven Standort an.');
            redirect('locations.php');
        }

        $stmt = pdo()->prepare('UPDATE standorte SET aktiv = 0 WHERE id = ?');
        $stmt->execute([$id]);

        if (isset($_SESSION['standort_id']) && (string)$_SESSION['standort_id'] === (string)$id) {
            $_SESSION['standort_id'] = 'all';
        }

        flash('success', 'Standort wurde archiviert. Bestehende Reklamationen bleiben erhalten.');
    }
} catch (Throwable $e) {
    flash('danger', APP_DEBUG ? 'Standort konnte nicht archiviert/aktiviert werden: ' . $e->getMessage() : 'Standort konnte nicht archiviert/aktiviert werden.');
}

redirect('locations.php');
