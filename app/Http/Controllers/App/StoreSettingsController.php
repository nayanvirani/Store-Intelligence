<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Store;
use App\Services\Shopify\StoreSync;
use App\Services\Shopify\TokenExchange;
use App\Services\Sync\SyncManager;
use App\Support\Spa\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Settings → Store: the Shopify connection and data sync (Final MVP F1, F7).
 */
class StoreSettingsController extends Controller
{
    public function show(Store $store, SyncManager $sync): Page
    {
        return page('settings/store', [
            'store' => [
                'name' => $store->name, 'shop_domain' => $store->shop_domain, 'shopify_plan' => $store->shopify_plan,
                'currency' => $store->currency, 'timezone' => $store->timezone, 'country' => $store->country,
                'installed_at' => $store->installed_at, 'installed' => $store->isInstalled(),
                'checked_at' => $store->capabilities_checked_at,
            ],
            'missingScopes' => $store->missingScopes(),
            'grantedScopes' => array_values(array_filter(explode(',', (string) $store->scopes))),
            'sync' => $sync->status($store),
            'counts' => [
                'products' => $store->products()->whereNull('deleted_at')->count(),
                'variants' => $store->variants()->whereNull('deleted_at')->count(),
                'orders' => $store->orders()->count(),
                'oldest_order' => $store->orders()->min('processed_at'),
            ],
            'history' => [
                'all_orders' => $store->hasScope('read_all_orders'),
                'customer_ids' => $store->capability('orders_customer') !== false,
                'visit_source' => $store->capability('orders_journey') !== false,
            ],
        ]);
    }

    public function syncNow(Store $store, SyncManager $sync): RedirectResponse
    {
        // A manual sync at most every 10 minutes per store.
        if (RateLimiter::tooManyAttempts('manual-sync:'.$store->id, 1)) {
            return redirect()->to(app_route('app.settings.store', ['notice' => 'sync_wait']));
        }
        RateLimiter::hit('manual-sync:'.$store->id, 600);
        $sync->start($store, 'products', 'manual');
        $sync->start($store, 'orders', 'manual');

        return redirect()->to(app_route('app.settings.store', ['notice' => 'sync_started']));
    }

    public function recheck(Store $store, StoreSync $sync): RedirectResponse
    {
        try {
            $sync->sync($store);
        } catch (Throwable $e) {
            report($e);

            return redirect()->to(app_route('app.settings.store', ['notice' => 'shopify']));
        }

        AuditLog::record('store.capabilities_checked', $store);

        return redirect()->to(app_route('app.settings.store', ['notice' => 'rechecked']));
    }

    public function reconnect(Request $request, Store $store, TokenExchange $tokens, StoreSync $sync): RedirectResponse
    {
        try {
            $tokens->exchange($store, (string) ($request->bearerToken() ?? $request->input('id_token')));
            $sync->sync($store);
        } catch (Throwable $e) {
            report($e);

            return redirect()->to(app_route('app.settings.store', ['notice' => 'shopify']));
        }

        AuditLog::record('store.reconnected', $store);

        return redirect()->to(app_route('app.settings.store', ['notice' => 'reconnected']));
    }
}
