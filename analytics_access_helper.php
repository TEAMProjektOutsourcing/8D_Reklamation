<?php
declare(strict_types=1);

/**
 * Gemeinsame Berechtigungsprüfung für die Qualitätsauswertung
 * und vertrauliche QM-Bereiche innerhalb einer Reklamation.
 */
if (!function_exists('analytics_can_view')) {
    function analytics_can_view(?array $user = null): bool
    {
        if (!$user) {
            $user = current_user();
        }

        if (!$user) {
            return false;
        }

        $role = strtolower(trim((string)($user['role'] ?? '')));

        return in_array($role, [
            'admin',
            'quality',
            'qualität',
            'qualitaet',
            'management',
            'managment',
            'betriebsmanagement',
            'betriebsmanagment',
        ], true);
    }
}
