<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Store;
use App\Support\Spa\Page;

class ActivityController extends Controller
{
    public const LABELS = [
        'store.installed' => 'Installed Store Intelligence',
        'store.token_refreshed' => 'Shopify connection refreshed',
        'store.reconnected' => 'Reconnected Shopify',
        'store.capabilities_checked' => 'Re-checked store capabilities',
        'store.uninstalled' => 'Uninstalled Store Intelligence',
        'user.joined' => 'Joined Store Intelligence',
        'user.invited' => 'Invited a staff member',
        'user.invite_cancelled' => 'Cancelled an invite',
        'user.role_changed' => 'Changed a role',
        'user.access_removed' => 'Removed access',
        'user.access_restored' => 'Restored access',
        'onboarding.goal_selected' => 'Chose a goal',
        'billing.subscription_requested' => 'Started a plan change',
        'billing.plan_activated' => 'Plan activated',
    ];

    public function index(Store $store): Page
    {
        $logs = AuditLog::with('actor')->where('store_id', $store->id)->latest('id')->paginate(25);
        $logs->setCollection($logs->getCollection()->map(fn (AuditLog $log) => [
            'id' => $log->id, 'created_at' => $log->created_at,
            'who' => $log->actor?->displayName() ?? 'Store Intelligence',
            'what' => self::LABELS[$log->action] ?? $log->action,
            'details' => collect($log->context ?? [])->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_array($v) ? implode(', ', array_map(fn ($x) => is_scalar($x) ? $x : json_encode($x), $v)) : $v))->implode(' · '),
            'request_id' => $log->request_id ? substr($log->request_id, 0, 8) : null,
        ]));

        return page('settings/activity', ['logs' => $logs]);
    }
}
