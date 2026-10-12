<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Shopify\Billing;
use App\Services\Usage;
use Illuminate\Http\Request;
use App\Support\Spa\Page;
use Throwable;

/**
 * Settings → Billing. Read-only under Shopify Managed Pricing: shows the plan Shopify reports,
 * usage against every plan limit, the plans and how they compare, and links to Shopify's own
 * plan picker for changes.
 */
class BillingController extends Controller
{
    public function index(Request $request, Store $store, Billing $billing, Usage $usage): Page
    {
        $syncError = false;

        try {
            $billing->sync($store);
        } catch (Throwable $e) {
            report($e);
            $syncError = true;
        }

        // An "Upgrade" click from a locked feature or a full limit: remember what prompted it.
        if (($from = (string) $request->query('from', '')) !== '' && preg_match('/^[a-z_]{2,60}$/', $from)
            && ! \App\Models\UpgradeEvent::where('store_id', $store->id)->where('feature', $from)->where('created_at', '>=', now()->subHour())->exists()) {
            \App\Models\UpgradeEvent::create(['store_id' => $store->id, 'feature' => $from, 'from_plan' => $store->effectivePlan()]);
        }

        $subscription = $store->activeSubscription()->first();
        $latest = $store->subscriptions()->latest('id')->first();

        return page('settings/billing', [
            'effective' => $store->effectivePlan(),
            'plan' => $store->plan,
            'planExpiresAt' => $store->plan_expires_at?->isFuture() ? $store->plan_expires_at : null,
            'testShop' => $store->isTestShop(),
            'subscription' => $subscription && $store->plan ? [
                'plan' => $subscription->plan, 'price' => (float) $subscription->price, 'test' => (bool) $subscription->test,
                'trial_ends_at' => $subscription->trial_ends_at?->isFuture() ? $subscription->trial_ends_at : null,
                'current_period_ends_at' => $subscription->current_period_ends_at,
            ] : null,
            'latestStatus' => $latest?->status,
            'plans' => \App\Support\PlanCatalog::cards(),
            'compare' => \App\Support\PlanCatalog::compare(),
            'pricingPage' => \App\Support\PricingPage::get(),
            'from' => $request->query('from'),
            // Every limit the plan sets, with what's used now (and a warning near the limit).
            'usage' => array_values(array_filter($usage->summary($store), fn ($m) => $m['limit'] !== null && ($m['limit'] > 0 || $m['used'] > 0))),
            'warnAt' => (float) config('shopify.billing.warn_at', 0.8),
            'syncError' => $syncError,
            // Only people who may change the plan get Shopify's plan picker.
            'pricingUrl' => $request->attributes->get('storeUser')?->can('manage_billing') ? $store->pricingUrl() : null,
            'billingUrl' => $store->adminUrl('settings/billing'),
        ]);
    }
}
