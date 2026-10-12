<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CostRule;
use App\Models\Store;
use App\Models\SyncRun;
use App\Services\Sync\SyncManager;
use App\Support\Spa\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Onboarding (Final MVP F2): from install to the first profit snapshot in a few minutes.
 * Progress comes from what the store has actually done, so it can be resumed at any time.
 */
class OnboardingController extends Controller
{
    /** The setup checklist on Home and in onboarding: key => [label, help, route]. */
    public const STEPS = [
        'connected' => ['Connect your store', 'Approve the read-only permissions Store Intelligence needs.', 'app.settings.store'],
        'synced' => ['Import products and orders', 'Your catalog and recent orders are copied so profit can be calculated.', 'app.settings.store'],
        'costs' => ['Check product costs', 'Add costs for your best sellers so profit is accurate.', 'app.products.index'],
        'fees' => ['Add payment fees and shipping', 'Fees and shipping turn gross profit into real profit.', 'app.settings.costs'],
        'alerts' => ['Choose your email updates', 'Daily or weekly summaries and who receives alerts.', 'app.settings.general'],
    ];

    /** @return list<array{key: string, label: string, help: string, done: bool, href: string}> */
    public static function checklist(Store $store): array
    {
        $variants = $store->variants()->whereNull('deleted_at');
        $total = (clone $variants)->count();
        $withCost = (clone $variants)->where(fn ($q) => $q->whereNotNull('app_cost')->orWhereNotNull('shopify_cost'))->count();
        $synced = collect(['products', 'orders'])->every(fn ($kind) => SyncRun::where('store_id', $store->id)->where('kind', $kind)->where('status', 'done')->exists());

        $done = [
            'connected' => $store->isInstalled() && $store->missingScopes() === [],
            'synced' => $synced,
            'costs' => $total > 0 && $withCost / $total >= 0.8 || $store->setting('default_margin') !== null,
            'fees' => CostRule::where('store_id', $store->id)->whereIn('type', ['payment_fee', 'shipping'])->exists(),
            'alerts' => array_key_exists('digest', $store->settings ?? []),
        ];

        return collect(self::STEPS)->map(fn ($s, $key) => [
            'key' => $key, 'label' => $s[0], 'help' => $s[1], 'done' => $done[$key], 'href' => route($s[2], [], false),
        ])->values()->all();
    }

    public function show(Request $request, Store $store, SyncManager $sync): Page
    {
        $variants = $store->variants()->whereNull('deleted_at');

        return page('onboarding', [
            'store' => ['name' => $store->name ?? $store->shop_domain, 'currency' => $store->currency, 'timezone' => $store->timezone],
            'checklist' => self::checklist($store),
            'sync' => $sync->status($store),
            'coverage' => ['total' => (clone $variants)->count(), 'with_cost' => (clone $variants)->where(fn ($q) => $q->whereNotNull('app_cost')->orWhereNotNull('shopify_cost'))->count()],
            'completed' => (bool) $store->onboarding_completed_at,
        ]);
    }

    public function complete(Store $store): RedirectResponse
    {
        if (! $store->onboarding_completed_at) {
            $store->forceFill(['onboarding_completed_at' => now()])->save();
            AuditLog::record('onboarding.completed', $store);
        }

        return redirect()->to(app_route('app.dashboard', ['notice' => 'setup_done']));
    }
}
