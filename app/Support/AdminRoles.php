<?php

namespace App\Support;

use App\Models\User;

/**
 * Internal Admin roles: what each part of the admin needs.
 */
class AdminRoles
{
    public const ROLES = [
        'super_admin' => ['Super Admin', 'Everything, including plans, email providers, legal pages, website content and design, platform settings and the team.'],
        'operations' => ['Operations Admin', 'Stores and their access, templates, flags, failures, support, audit, website content and the blog.'],
        'support' => ['Support Agent', 'Support tickets, and read-only store details.'],
    ];

    /** area => roles allowed */
    private const AREAS = [
        'dashboard' => ['super_admin', 'operations', 'support'],
        'stores' => ['super_admin', 'operations', 'support'],
        'stores.manage' => ['super_admin', 'operations'],
        'support' => ['super_admin', 'operations', 'support'],
        'plans' => ['super_admin', 'operations'],
        'plans.manage' => ['super_admin'],
        'templates' => ['super_admin', 'operations'],
        'flags' => ['super_admin', 'operations'],
        'failures' => ['super_admin', 'operations'],
        'analytics' => ['super_admin', 'operations'],
        'audit' => ['super_admin', 'operations'],
        'settings' => ['super_admin'],
        'content' => ['super_admin', 'operations'],
        'legal' => ['super_admin'],
        'email' => ['super_admin'],
        'team' => ['super_admin'],
        'finance' => ['super_admin'],
    ];

    public static function can(?User $user, string $area): bool
    {
        return $user !== null && $user->is_admin && $user->disabled_at === null
            && in_array($user->admin_role ?: 'super_admin', self::AREAS[$area] ?? [], true);
    }

    public static function label(?string $role): string
    {
        return self::ROLES[$role ?: 'super_admin'][0] ?? $role;
    }
}
