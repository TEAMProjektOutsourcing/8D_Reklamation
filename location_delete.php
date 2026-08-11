<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if (!db_table_exists('standorte')) {
    flash('warning', 'Die Standort-Tabellen sind noch nicht vorhanden.');
    redirect('locations.php');
}

$id = (int)($_POST['id'] ?? 0);
$location = location_by_id($id);
if (!$location) {
    flash('danger', 'Standort wurde nicht gefunden.');
    redirect('locations.php');
}

try {
    $claimCount = 0;
    if (db_table_exists('claims') && db_column_exists('claims', 'standort_id')) {
        $stmt = pdo()->prepare('SELECT COUNT(*) FROM claims WHERE standort_id = ?');
        $stmt->execute([$id]);
        $claimCount = (int)$stmt->fetchColumn();
    }

    if ($claimCount > 0) {
        flash('danger', 'Standort kann nicht gelöscht werden, weil noch ' . $claimCount . ' Reklamation' . ($claimCount === 1 ? '' : 'en') . ' damit verknüpft sind. Bitte stattdessen archivieren.');
        redirect('locations.php');
    }

    $activeCount = (int)pdo()->query('SELECT COUNT(*) FROM standorte WHERE aktiv = 1')->fetchColumn();
    if ((int)$location['aktiv'] === 1 && $activeCount <= 1) {
        flash('danger', 'Der letzte aktive Standort kann nicht gelöscht werden. Lege zuerst einen weiteren aktiven Standort an.');
        redirect('locations.php');
    }

    $db = pdo();
    $db->beginTransaction();

    if (db_table_exists('user_standorte')) {
        $stmt = $db->prepare('DELETE FROM user_standorte WHERE standort_id = ?');
        $stmt->execute([$id]);
    }

    $stmt = $db->prepare('DELETE FROM standorte WHERE id = ?');
    $stmt->execute([$id]);

    $db->commit();

    if (isset($_SESSION['standort_id']) && (string)$_SESSION['standort_id'] === (string)$id) {
        $_SESSION['standort_id'] = 'all';
    }

    flash('success', 'Standort wurde gelöscht.');
} catch (Throwable $e) {
    if (pdo()->inTransaction()) {
        pdo()->rollBack();
    }
    flash('danger', APP_DEBUG ? 'Standort konnte nicht gelöscht werden: ' . $e->getMessage() : 'Standort konnte nicht gelöscht werden.');
}

redirect('locations.php');
