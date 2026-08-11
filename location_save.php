<?php
require_once __DIR__ . '/auth.php';
require_admin();
require_csrf();

if (!db_table_exists('standorte')) {
    flash('warning', 'Die Standort-Tabellen sind noch nicht vorhanden. Bitte zuerst die Migration ausführen.');
    redirect('run_location_migration.php');
}

$id = (int)($_POST['id'] ?? 0);
$name = trim((string)($_POST['name'] ?? ''));
$kuerzel = strtoupper(trim((string)($_POST['kuerzel'] ?? '')));
$kuerzel = preg_replace('/[^A-Z0-9_-]/', '', $kuerzel) ?: '';
$adresse = trim((string)($_POST['adresse'] ?? '')) ?: null;
$aktiv = isset($_POST['aktiv']) ? 1 : 0;

if ($name === '' || $kuerzel === '') {
    flash('danger', 'Name und Kürzel sind Pflichtfelder.');
    redirect($id > 0 ? 'locations.php?edit=' . $id : 'locations.php');
}

try {
    if ($id > 0) {
        $stmt = pdo()->prepare('UPDATE standorte SET name = ?, kuerzel = ?, adresse = ?, aktiv = ? WHERE id = ?');
        $stmt->execute([$name, $kuerzel, $adresse, $aktiv, $id]);
        flash('success', 'Standort wurde aktualisiert.');
    } else {
        $stmt = pdo()->prepare('INSERT INTO standorte (name, kuerzel, adresse, aktiv) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $kuerzel, $adresse, $aktiv]);
        flash('success', 'Standort wurde angelegt. Bitte Benutzer zuordnen.');
    }
} catch (Throwable $e) {
    flash('danger', APP_DEBUG ? 'Standort konnte nicht gespeichert werden: ' . $e->getMessage() : 'Standort konnte nicht gespeichert werden.');
}

redirect('locations.php');
