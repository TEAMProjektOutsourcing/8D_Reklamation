<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_admin();

$db = pdo();

function verwaltung_fix_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);

    return (bool)$stmt->fetchColumn();
}

function verwaltung_fix_column_exists(
    PDO $db,
    string $table,
    string $column
): bool {
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?'
    );
    $stmt->execute([$table, $column]);

    return (bool)$stmt->fetchColumn();
}

function verwaltung_fix_load_groups(
    PDO $db,
    bool $forUpdate = false
): array {
    $sql = "SELECT *
            FROM claim_groups
            WHERE LOWER(TRIM(name)) IN ('verwaltung', 'logistik')
            ORDER BY
                CASE
                    WHEN id = 4 THEN 0
                    WHEN LOWER(TRIM(name)) = 'verwaltung' THEN 1
                    ELSE 2
                END,
                id ASC";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    return $db->query($sql)->fetchAll();
}

function verwaltung_fix_pick_target(array $groups): ?array
{
    if (!$groups) {
        return null;
    }

    /*
     * Bevorzugt wird ausdrücklich ID 4, weil Daniel diese Gruppe bereits
     * in „Verwaltung“ umbenannt hat und ihre ID erhalten bleiben soll.
     */
    foreach ($groups as $group) {
        if ((int)($group['id'] ?? 0) === 4) {
            return $group;
        }
    }

    /*
     * Fallback: vorhandene Gruppe „Verwaltung“, danach kleinste ID.
     */
    foreach ($groups as $group) {
        if (
            strtolower(trim((string)($group['name'] ?? '')))
            === 'verwaltung'
        ) {
            return $group;
        }
    }

    usort(
        $groups,
        static fn(array $a, array $b): int =>
            (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0)
    );

    return $groups[0] ?? null;
}

function verwaltung_fix_count(
    PDO $db,
    string $table,
    int $groupId
): int {
    $stmt = $db->prepare(
        "SELECT COUNT(*) FROM {$table} WHERE group_id = ?"
    );
    $stmt->execute([$groupId]);

    return (int)$stmt->fetchColumn();
}

$requiredTables = [
    'claim_groups',
    'claim_group_members',
    'claim_group_assignments',
];

$missingTables = [];

foreach ($requiredTables as $table) {
    if (!verwaltung_fix_table_exists($db, $table)) {
        $missingTables[] = $table;
    }
}

if ($missingTables) {
    http_response_code(500);
    die(
        'Bereinigung nicht möglich. Fehlende Tabelle(n): '
        . e(implode(', ', $missingTables))
    );
}

$hasActiveColumn = verwaltung_fix_column_exists(
    $db,
    'claim_groups',
    'active'
);
$hasDescriptionColumn = verwaltung_fix_column_exists(
    $db,
    'claim_groups',
    'description'
);
$hasColorColumn = verwaltung_fix_column_exists(
    $db,
    'claim_groups',
    'color'
);
$hasUpdatedAtColumn = verwaltung_fix_column_exists(
    $db,
    'claim_groups',
    'updated_at'
);
$hasUpdatedByColumn = verwaltung_fix_column_exists(
    $db,
    'claim_groups',
    'updated_by'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    try {
        $db->beginTransaction();

        $groups = verwaltung_fix_load_groups($db, true);
        $target = verwaltung_fix_pick_target($groups);

        if (!$target) {
            throw new RuntimeException(
                'Es wurde weder „Verwaltung“ noch „Logistik“ gefunden.'
            );
        }

        $targetId = (int)$target['id'];
        $currentUser = current_user();
        $currentUserId = (int)($currentUser['id'] ?? 0);

        /*
         * Zielgruppe aktivieren und vollständig auf Verwaltung setzen.
         * standort_id bleibt absichtlich unverändert. Bei ID 4 bleibt sie
         * damit global, wie aktuell in der Datenbank hinterlegt.
         */
        $targetSet = ['name = ?'];
        $targetParams = ['Verwaltung'];

        if ($hasActiveColumn) {
            $targetSet[] = 'active = 1';
        }

        if ($hasDescriptionColumn) {
            $targetSet[] = 'description = ?';
            $targetParams[] = 'Operations Leitung · Manuel Edel';
        }

        if ($hasColorColumn) {
            $targetSet[] = 'color = ?';
            $targetParams[] = 'primary';
        }

        if ($hasUpdatedByColumn && $currentUserId > 0) {
            $targetSet[] = 'updated_by = ?';
            $targetParams[] = $currentUserId;
        }

        if ($hasUpdatedAtColumn) {
            $targetSet[] = 'updated_at = NOW()';
        }

        $targetParams[] = $targetId;

        $updateTarget = $db->prepare(
            'UPDATE claim_groups
             SET ' . implode(', ', $targetSet) . '
             WHERE id = ?'
        );
        $updateTarget->execute($targetParams);

        $sourceIds = [];
        $movedMembers = 0;
        $movedAssignments = 0;

        foreach ($groups as $source) {
            $sourceId = (int)($source['id'] ?? 0);

            if ($sourceId <= 0 || $sourceId === $targetId) {
                continue;
            }

            $sourceIds[] = $sourceId;

            /*
             * Mitgliedschaften verlustfrei übernehmen.
             * INSERT IGNORE verhindert doppelte Datensätze.
             */
            $memberInsert = $db->prepare(
                'INSERT IGNORE INTO claim_group_members
                    (group_id, user_id, created_at)
                 SELECT ?, user_id, created_at
                 FROM claim_group_members
                 WHERE group_id = ?'
            );
            $memberInsert->execute([$targetId, $sourceId]);
            $movedMembers += $memberInsert->rowCount();

            /*
             * Reklamationszuordnungen verlustfrei übernehmen.
             */
            $assignmentInsert = $db->prepare(
                'INSERT IGNORE INTO claim_group_assignments
                    (claim_id, group_id, assigned_by, created_at)
                 SELECT claim_id, ?, assigned_by, created_at
                 FROM claim_group_assignments
                 WHERE group_id = ?'
            );
            $assignmentInsert->execute([$targetId, $sourceId]);
            $movedAssignments += $assignmentInsert->rowCount();

            /*
             * Alte Verknüpfungen entfernen, nachdem sie übernommen wurden.
             */
            $deleteMembers = $db->prepare(
                'DELETE FROM claim_group_members WHERE group_id = ?'
            );
            $deleteMembers->execute([$sourceId]);

            $deleteAssignments = $db->prepare(
                'DELETE FROM claim_group_assignments WHERE group_id = ?'
            );
            $deleteAssignments->execute([$sourceId]);

            /*
             * Altgruppe eindeutig archivieren und deaktivieren.
             */
            $sourceSet = ['name = ?'];
            $sourceParams = ['Verwaltung – Altbestand #' . $sourceId];

            if ($hasActiveColumn) {
                $sourceSet[] = 'active = 0';
            }

            if ($hasUpdatedByColumn && $currentUserId > 0) {
                $sourceSet[] = 'updated_by = ?';
                $sourceParams[] = $currentUserId;
            }

            if ($hasUpdatedAtColumn) {
                $sourceSet[] = 'updated_at = NOW()';
            }

            $sourceParams[] = $sourceId;

            $updateSource = $db->prepare(
                'UPDATE claim_groups
                 SET ' . implode(', ', $sourceSet) . '
                 WHERE id = ?'
            );
            $updateSource->execute($sourceParams);
        }

        /*
         * Abschließende Sicherheitsprüfungen vor dem Commit.
         */
        $targetCheck = $db->prepare(
            'SELECT name'
            . ($hasActiveColumn ? ', active' : '')
            . ' FROM claim_groups WHERE id = ?'
        );
        $targetCheck->execute([$targetId]);
        $targetAfter = $targetCheck->fetch();

        if (!$targetAfter) {
            throw new RuntimeException(
                'Die Zielgruppe konnte nach der Bereinigung nicht geprüft werden.'
            );
        }

        if (
            strtolower(trim((string)$targetAfter['name']))
            !== 'verwaltung'
        ) {
            throw new RuntimeException(
                'Die Zielgruppe wurde nicht korrekt in Verwaltung umbenannt.'
            );
        }

        if (
            $hasActiveColumn
            && (int)($targetAfter['active'] ?? 0) !== 1
        ) {
            throw new RuntimeException(
                'Die Gruppe Verwaltung wurde nicht aktiviert.'
            );
        }

        foreach ($sourceIds as $sourceId) {
            if (
                verwaltung_fix_count(
                    $db,
                    'claim_group_members',
                    $sourceId
                ) !== 0
            ) {
                throw new RuntimeException(
                    'Bei einer Altgruppe sind noch Mitgliedschaften vorhanden.'
                );
            }

            if (
                verwaltung_fix_count(
                    $db,
                    'claim_group_assignments',
                    $sourceId
                ) !== 0
            ) {
                throw new RuntimeException(
                    'Bei einer Altgruppe sind noch Reklamationszuordnungen vorhanden.'
                );
            }
        }

        $db->commit();

        flash(
            'success',
            'Gruppenbereinigung abgeschlossen: '
            . '„Verwaltung“ (ID ' . $targetId . ') wurde aktiviert. '
            . count($sourceIds) . ' Altgruppe(n), '
            . $movedMembers . ' neue Mitgliedschaft(en) und '
            . $movedAssignments . ' neue Reklamationszuordnung(en) '
            . 'wurden übernommen.'
        );

        redirect('groups.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'Verwaltung-Gruppenbereinigung Fix fehlgeschlagen: '
            . $e->getMessage()
        );

        flash(
            'error',
            'Die Gruppenbereinigung konnte nicht durchgeführt werden: '
            . $e->getMessage()
        );

        redirect('run_group_verwaltung_cleanup_fix.php');
    }
}

$groups = verwaltung_fix_load_groups($db);
$target = verwaltung_fix_pick_target($groups);
$targetId = $target ? (int)$target['id'] : 0;
$sourceGroups = array_values(array_filter(
    $groups,
    static fn(array $group): bool =>
        (int)($group['id'] ?? 0) !== $targetId
));

require __DIR__ . '/header.php';
?>

<div class="card page-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">
                    Administration · Gruppenbereinigung
                </div>
                <h1 class="page-title display-6 fw-bold mb-2">
                    Verwaltung als Hauptgruppe aktivieren
                </h1>
                <div class="page-subtitle">
                    Diese korrigierte Reparatur führt die globale Gruppe
                    „Verwaltung“ und die Standortgruppe „Logistik“ zusammen.
                    Unterschiedliche Standortfelder verhindern die
                    Zusammenführung nicht mehr.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a
                        href="groups.php"
                        class="btn btn-outline-secondary"
                    >
                        Zur Gruppenverwaltung
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="alert alert-warning">
    <strong>Warum war die erste Prüfung falsch?</strong><br>
    Die Gruppe „Verwaltung“ ist global, während „Logistik“ dem Standort 1
    zugeordnet ist. Die erste Version hat beide Standortbereiche getrennt
    betrachtet und deshalb keine Dublette erkannt.
</div>

<div class="card mb-4">
    <div class="card-header fw-bold">Geplante Zusammenführung</div>
    <div class="card-body">
        <?php if (!$target): ?>
            <div class="alert alert-danger mb-0">
                Es wurde keine passende Gruppe gefunden.
            </div>
        <?php else: ?>
            <div class="mb-4">
                <div class="text-muted small">Hauptgruppe nach der Bereinigung</div>
                <div class="fw-bold fs-5">
                    ID <?= $targetId ?> · Verwaltung
                </div>
                <div class="small">
                    Global · Operations Leitung · Manuel Edel · Aktiv
                </div>
            </div>

            <?php if ($sourceGroups): ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead>
                        <tr>
                            <th>ID</th>
                            <th>Aktuelle Bezeichnung</th>
                            <th>Standort-ID</th>
                            <th>Aktueller Status</th>
                            <th>Ergebnis</th>
                        </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($sourceGroups as $source): ?>
                            <tr>
                                <td><?= (int)$source['id'] ?></td>
                                <td><?= e($source['name']) ?></td>
                                <td>
                                    <?= !empty($source['standort_id'])
                                        ? (int)$source['standort_id']
                                        : 'Global' ?>
                                </td>
                                <td>
                                    <?php if (
                                        !$hasActiveColumn
                                        || (int)($source['active'] ?? 1) === 1
                                    ): ?>
                                        <span class="badge bg-success">Aktiv</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Inaktiv</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    Mitglieder und Reklamationen → ID <?= $targetId ?><br>
                                    <span class="text-muted small">
                                        anschließend inaktiv als Altbestand
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php else: ?>
                <div class="alert alert-success mb-0">
                    Es ist keine weitere Gruppe „Logistik“ oder „Verwaltung“
                    vorhanden. Die Hauptgruppe kann dennoch aktiviert und
                    vereinheitlicht werden.
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php if ($target): ?>
    <div class="card">
        <div class="card-body">
            <h2 class="h5 fw-bold">Die Reparatur führt Folgendes aus</h2>
            <ul>
                <li>ID <?= $targetId ?> wird zu „Verwaltung“ und aktiviert.</li>
                <li>Beschreibung wird „Operations Leitung · Manuel Edel“.</li>
                <li>Farbe wird auf „primary“ gesetzt.</li>
                <li>Mitglieder aus ID 2 werden verlustfrei übernommen.</li>
                <li>Reklamationszuordnungen aus ID 2 werden übernommen.</li>
                <li>ID 2 wird anschließend deaktiviert und archiviert.</li>
                <li>Die globale Einstellung von ID <?= $targetId ?> bleibt erhalten.</li>
            </ul>

            <form method="post" class="mt-4">
                <?= csrf_field() ?>
                <button
                    type="submit"
                    class="btn btn-primary"
                    data-confirm="Verwaltung jetzt aktivieren und Logistik vollständig zusammenführen?"
                >
                    Verwaltung jetzt korrekt zusammenführen
                </button>
            </form>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
