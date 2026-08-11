<?php
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/auth.php';
require_admin();

$db = pdo();

function verwaltung_cleanup_normalize(string $value): string
{
    $value = trim($value);

    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function verwaltung_cleanup_table_exists(PDO $db, string $table): bool
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

function verwaltung_cleanup_column_exists(
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

function verwaltung_cleanup_load_groups(PDO $db, bool $forUpdate = false): array
{
    $sql = "SELECT *
            FROM claim_groups
            WHERE LOWER(TRIM(name)) IN ('logistik', 'verwaltung')
            ORDER BY
                CASE WHEN LOWER(TRIM(name)) = 'verwaltung' THEN 0 ELSE 1 END,
                active DESC,
                id ASC";

    if ($forUpdate) {
        $sql .= ' FOR UPDATE';
    }

    return $db->query($sql)->fetchAll();
}

function verwaltung_cleanup_scope_key(array $group): string
{
    $locationId = isset($group['standort_id'])
        ? (int)($group['standort_id'] ?? 0)
        : 0;

    return $locationId > 0 ? 'location_' . $locationId : 'global';
}

function verwaltung_cleanup_group_by_scope(array $groups): array
{
    $scopes = [];

    foreach ($groups as $group) {
        $scopes[verwaltung_cleanup_scope_key($group)][] = $group;
    }

    return $scopes;
}

function verwaltung_cleanup_pick_target(array $groups): array
{
    usort($groups, static function (array $a, array $b): int {
        $aIsVerwaltung = verwaltung_cleanup_normalize(
            (string)($a['name'] ?? '')
        ) === 'verwaltung';
        $bIsVerwaltung = verwaltung_cleanup_normalize(
            (string)($b['name'] ?? '')
        ) === 'verwaltung';

        if ($aIsVerwaltung !== $bIsVerwaltung) {
            return $aIsVerwaltung ? -1 : 1;
        }

        $aActive = (int)($a['active'] ?? 1);
        $bActive = (int)($b['active'] ?? 1);

        if ($aActive !== $bActive) {
            return $bActive <=> $aActive;
        }

        return (int)($a['id'] ?? 0) <=> (int)($b['id'] ?? 0);
    });

    return $groups[0];
}

$requiredTables = [
    'claim_groups',
    'claim_group_members',
    'claim_group_assignments',
];

$missingTables = array_values(array_filter(
    $requiredTables,
    static fn(string $table): bool => !verwaltung_cleanup_table_exists(
        $db,
        $table
    )
));

if ($missingTables) {
    http_response_code(500);
    die(
        'Bereinigung nicht möglich. Fehlende Tabelle(n): '
        . e(implode(', ', $missingTables))
    );
}

$hasActiveColumn = verwaltung_cleanup_column_exists(
    $db,
    'claim_groups',
    'active'
);
$hasDescriptionColumn = verwaltung_cleanup_column_exists(
    $db,
    'claim_groups',
    'description'
);
$hasUpdatedAtColumn = verwaltung_cleanup_column_exists(
    $db,
    'claim_groups',
    'updated_at'
);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();

    try {
        $db->beginTransaction();

        $groups = verwaltung_cleanup_load_groups($db, true);
        $scopes = verwaltung_cleanup_group_by_scope($groups);

        $mergedGroups = 0;
        $movedMembers = 0;
        $movedAssignments = 0;
        $renamedTargets = 0;

        foreach ($scopes as $scopeGroups) {
            if (!$scopeGroups) {
                continue;
            }

            $target = verwaltung_cleanup_pick_target($scopeGroups);
            $targetId = (int)$target['id'];

            $bestDescription = '';
            if ($hasDescriptionColumn) {
                foreach ($scopeGroups as $candidate) {
                    $candidateDescription = trim(
                        (string)($candidate['description'] ?? '')
                    );

                    if ($candidateDescription !== '') {
                        $bestDescription = $candidateDescription;
                        break;
                    }
                }
            }

            $targetSet = ['name = ?'];
            $targetParams = ['Verwaltung'];

            if ($hasActiveColumn) {
                $targetSet[] = 'active = 1';
            }

            if (
                $hasDescriptionColumn
                && trim((string)($target['description'] ?? '')) === ''
                && $bestDescription !== ''
            ) {
                $targetSet[] = 'description = ?';
                $targetParams[] = $bestDescription;
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

            if (
                verwaltung_cleanup_normalize(
                    (string)($target['name'] ?? '')
                ) !== 'verwaltung'
            ) {
                $renamedTargets++;
            }

            foreach ($scopeGroups as $source) {
                $sourceId = (int)$source['id'];

                if ($sourceId === $targetId) {
                    continue;
                }

                $memberInsert = $db->prepare(
                    'INSERT IGNORE INTO claim_group_members
                        (group_id, user_id, created_at)
                     SELECT ?, user_id, created_at
                     FROM claim_group_members
                     WHERE group_id = ?'
                );
                $memberInsert->execute([$targetId, $sourceId]);
                $movedMembers += $memberInsert->rowCount();

                $assignmentInsert = $db->prepare(
                    'INSERT IGNORE INTO claim_group_assignments
                        (claim_id, group_id, assigned_by, created_at)
                     SELECT claim_id, ?, assigned_by, created_at
                     FROM claim_group_assignments
                     WHERE group_id = ?'
                );
                $assignmentInsert->execute([$targetId, $sourceId]);
                $movedAssignments += $assignmentInsert->rowCount();

                $deleteMembers = $db->prepare(
                    'DELETE FROM claim_group_members WHERE group_id = ?'
                );
                $deleteMembers->execute([$sourceId]);

                $deleteAssignments = $db->prepare(
                    'DELETE FROM claim_group_assignments WHERE group_id = ?'
                );
                $deleteAssignments->execute([$sourceId]);

                $legacyName = 'Verwaltung – Altbestand #' . $sourceId;
                $sourceSet = ['name = ?'];
                $sourceParams = [$legacyName];

                if ($hasActiveColumn) {
                    $sourceSet[] = 'active = 0';
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

                $mergedGroups++;
            }
        }

        $db->commit();

        flash(
            'success',
            'Gruppenbereinigung abgeschlossen. '
            . $mergedGroups . ' Altgruppe(n) zusammengeführt, '
            . $movedMembers . ' Mitgliedschaft(en) und '
            . $movedAssignments . ' Reklamationszuordnung(en) übernommen.'
        );
        redirect('groups.php');
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }

        error_log(
            'Verwaltung-Gruppenbereinigung fehlgeschlagen: '
            . $e->getMessage()
        );

        flash(
            'error',
            'Die Gruppenbereinigung konnte nicht durchgeführt werden: '
            . $e->getMessage()
        );
        redirect('run_group_verwaltung_cleanup.php');
    }
}

$groups = verwaltung_cleanup_load_groups($db);
$scopes = verwaltung_cleanup_group_by_scope($groups);
$duplicateCount = 0;

foreach ($scopes as $scopeGroups) {
    $duplicateCount += max(0, count($scopeGroups) - 1);
}

require __DIR__ . '/header.php';
?>

<div class="card page-hero mb-4">
    <div class="card-body p-4 p-lg-5">
        <div class="row g-4 align-items-center">
            <div class="col-lg-8">
                <div class="page-kicker mb-3">Administration · Gruppenbereinigung</div>
                <h1 class="page-title display-6 fw-bold mb-2">
                    Logistik vollständig in Verwaltung überführen
                </h1>
                <div class="page-subtitle">
                    Diese einmalige Reparatur führt alte und neue Gruppeneinträge
                    zusammen, ohne Mitgliedschaften oder Reklamationszuordnungen
                    zu verlieren.
                </div>
            </div>

            <div class="col-lg-4">
                <div class="page-actions">
                    <a href="groups.php" class="btn btn-outline-secondary">
                        Zur Gruppenverwaltung
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header fw-bold">Gefundene Datensätze</div>
    <div class="card-body">
        <?php if (!$groups): ?>
            <div class="alert alert-success mb-0">
                Es wurde keine Gruppe mit der Bezeichnung „Logistik“ oder
                „Verwaltung“ gefunden.
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0">
                    <thead>
                    <tr>
                        <th>ID</th>
                        <th>Bezeichnung</th>
                        <th>Standort-ID</th>
                        <th>Beschreibung</th>
                        <th>Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($groups as $group): ?>
                        <tr>
                            <td><?= (int)$group['id'] ?></td>
                            <td class="fw-semibold"><?= e($group['name']) ?></td>
                            <td><?= !empty($group['standort_id']) ? (int)$group['standort_id'] : 'Global' ?></td>
                            <td><?= e($group['description'] ?? '-') ?></td>
                            <td>
                                <?php if (!$hasActiveColumn || (int)$group['active'] === 1): ?>
                                    <span class="badge bg-success">Aktiv</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inaktiv</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="card">
    <div class="card-body">
        <h2 class="h5 fw-bold">Was wird durchgeführt?</h2>
        <ul class="mb-4">
            <li>„Verwaltung“ wird als aktive Hauptgruppe beibehalten.</li>
            <li>Bestehende Mitglieder werden verlustfrei übernommen.</li>
            <li>Vorhandene Reklamationszuordnungen werden übernommen.</li>
            <li>Alte Gruppen werden deaktiviert und ohne das Wort „Logistik“ archiviert.</li>
            <li>Die Gruppen-ID der Hauptgruppe bleibt bestehen.</li>
        </ul>

        <?php if ($duplicateCount > 0): ?>
            <form method="post">
                <?= csrf_field() ?>
                <button
                    type="submit"
                    class="btn btn-primary"
                    data-confirm="Gruppen jetzt zusammenführen?"
                >
                    Gruppen jetzt bereinigen
                </button>
            </form>
        <?php elseif ($groups): ?>
            <div class="alert alert-success mb-0">
                Es existiert bereits nur eine passende Gruppe. Eine
                Zusammenführung ist nicht notwendig.
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/footer.php'; ?>
