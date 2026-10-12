<?php

namespace App\Support;

/**
 * Role-based permissions for merchant staff (Final MVP F4: Owner / Admin / Marketer / Analyst).
 */
class Permissions
{
    public const ROLES = [
        'owner' => 'Owner',
        'admin' => 'Admin',
        'marketer' => 'Marketer',
        'analyst' => 'Analyst',
    ];

    public const MATRIX = [
        'view_dashboard' => ['label' => 'View dashboards and reports', 'roles' => ['owner', 'admin', 'marketer', 'analyst']],
        'manage_marketing' => ['label' => 'Create, approve and publish social posts', 'roles' => ['owner', 'admin', 'marketer']],
        'manage_alerts' => ['label' => 'Acknowledge, snooze and resolve alerts', 'roles' => ['owner', 'admin', 'marketer']],
        'manage_costs' => ['label' => 'Edit product costs and cost rules', 'roles' => ['owner', 'admin']],
        'manage_integrations' => ['label' => 'Connect and disconnect accounts', 'roles' => ['owner', 'admin']],
        'manage_settings' => ['label' => 'Change store settings', 'roles' => ['owner', 'admin']],
        'manage_users' => ['label' => 'Invite staff and change roles', 'roles' => ['owner', 'admin']],
        'view_activity' => ['label' => 'View the activity log', 'roles' => ['owner', 'admin']],
        'manage_billing' => ['label' => 'Change plan and billing', 'roles' => ['owner']],
    ];

    public static function allows(?string $role, string $permission): bool
    {
        return $role !== null && in_array($role, self::MATRIX[$permission]['roles'] ?? [], true);
    }

    /**
     * Roles a user with $role may assign to others.
     */
    public static function assignableBy(string $role): array
    {
        return match ($role) {
            'owner' => ['owner', 'admin', 'marketer', 'analyst'],
            'admin' => ['admin', 'marketer', 'analyst'],
            default => [],
        };
    }
}
