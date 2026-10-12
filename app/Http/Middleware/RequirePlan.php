<?php

namespace App\Http\Middleware;

use App\Services\Shopify\Billing;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Shopify Managed Pricing paywall, matching SpeedPilot: there is no free plan,
 * so a shop without an active plan only sees Settings → Billing.
 *
 * Also handles the return from Shopify's plan page, which comes back into the
 * app with ?charge_id=<subscription id>: that subscription is synced straight
 * away (the webhook may still be on its way) and the merchant lands on
 * Billing with their new plan.
 */
class RequirePlan
{
    public function __construct(private readonly Billing $billing) {}

    public function handle(Request $request, Closure $next): Response
    {
        $store = $request->attributes->get('store');

        if ($request->query('charge_id')) {
            $this->sync($store);

            return redirect()->to(app_route('app.settings.billing', ['notice' => $store->hasPlanAccess() ? 'plan_updated' : 'plan_pending']));
        }

        // Self-heal a missed or delayed webhook, only while the shop looks unsubscribed.
        if (! $store->hasPlanAccess()) {
            $this->sync($store);
        }

        if ($store->hasPlanAccess()) {
            // Anything that changed what the store may use (e.g. a complimentary plan ending) is
            // applied to the storefront and checkout after the response.
            if ($store->entitlements_hash !== $store->entitlementsFingerprint()) {
                defer(fn () => app(\App\Services\Billing\Entitlements::class)->apply($store));
            }

            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Choose a plan to start using Store Intelligence.'], 402);
        }

        return redirect()->to(app_route('app.settings.billing'));
    }

    private function sync($store): void
    {
        try {
            $this->billing->sync($store);
            $store->refresh();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
