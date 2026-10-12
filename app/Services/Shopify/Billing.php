<?php

namespace App\Services\Shopify;

use App\Models\AuditLog;
use App\Models\Store;
use App\Models\Subscription;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Shopify Managed Pricing. Plans live in the Partner Dashboard and merchants
 * choose them on Shopify's hosted page (Store::pricingUrl()), so the app never
 * creates or cancels a subscription. It mirrors what Shopify reports:
 *
 * - app_subscriptions/update webhook: every plan change (applyWebhook)
 * - a live query when the app is first opened, when a shop still looks
 *   unsubscribed, and on the billing page (sync) — the webhook and the
 *   merchant's first visit race each other.
 *
 * Each Shopify subscription is its own row, so upgrades, downgrades and
 * cancellations keep their history.
 */
class Billing
{
    public function __construct(private readonly AdminApi $api) {}

    /**
     * Pulls the active subscription from Shopify and mirrors it locally.
     */
    public function sync(Store $store): ?Subscription
    {
        $data = $this->api->graphql($store, <<<'GQL'
            {
              currentAppInstallation {
                app { handle }
                activeSubscriptions { id name status test trialDays createdAt currentPeriodEnd }
              }
            }
            GQL);

        // The pricing page URL needs the app's handle; Shopify tells us, so nobody has to configure it.
        if ($handle = $data['currentAppInstallation']['app']['handle'] ?? null) {
            Cache::forever('shopify.app_handle', $handle);
        }

        $active = collect($data['currentAppInstallation']['activeSubscriptions'] ?? [])->firstWhere('status', 'ACTIVE');

        if ($active === null) {
            // Shopify reports nothing active: close any row we still think is active.
            // A cancellation's paid-up period (if we captured one) still grants access.
            $store->subscriptions()->where('status', 'ACTIVE')->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
            $this->refreshStorePlan($store);

            return null;
        }

        return $this->mirror($store, $active['id'], 'ACTIVE', $active['name'], [
            'test' => (bool) ($active['test'] ?? false),
            'trial_ends_at' => ($active['trialDays'] ?? 0) > 0 ? Carbon::parse($active['createdAt'])->addDays($active['trialDays']) : null,
            'current_period_ends_at' => ! empty($active['currentPeriodEnd']) ? Carbon::parse($active['currentPeriodEnd']) : null,
        ]);
    }

    /**
     * Applies an app_subscriptions/update webhook payload.
     */
    public function applyWebhook(Store $store, array $payload): ?Subscription
    {
        $sub = $payload['app_subscription'] ?? $payload;
        $gid = (string) ($sub['admin_graphql_api_id'] ?? '');

        if ($gid === '') {
            return null;
        }

        $status = strtoupper((string) ($sub['status'] ?? 'PENDING'));

        // The payload doesn't reliably carry the period end, so an activation asks
        // Shopify once. A cancellation deliberately doesn't: the period end captured
        // while it was active is what grants the grace period.
        if ($status === 'ACTIVE') {
            try {
                return $this->sync($store) ?? $this->mirror($store, $gid, $status, (string) ($sub['name'] ?? ''));
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return $this->mirror($store, $gid, $status, (string) ($sub['name'] ?? ''));
    }

    /**
     * Maps a Shopify plan display name to our plan key. Case-insensitive, and
     * tolerates a "Store Intelligence " prefix. Unknown names (e.g. a free plan) map to null.
     */
    public static function planKeyFromName(?string $name): ?string
    {
        $needle = strtolower(trim((string) $name));
        // Plan names may carry the app's name: "Store Intelligence Growth".
        $needle = preg_replace('/^store\s+intelligence\s+/', '', $needle);

        foreach (config('shopify.billing.plans') as $key => $plan) {
            foreach ([$plan['shopify_name'] ?? null, $plan['name'], $key] as $candidate) {
                if ($candidate !== null && strtolower((string) $candidate) === $needle) {
                    return $key;
                }
            }
        }

        return null;
    }

    private function mirror(Store $store, string $gid, string $status, string $name, array $extra = []): Subscription
    {
        $planKey = self::planKeyFromName($name);

        $subscription = DB::transaction(function () use ($store, $gid, $status, $name, $planKey, $extra) {
            $subscription = Subscription::firstOrNew(['shopify_subscription_id' => $gid]);
            $subscription->fill([
                'store_id' => $store->id,
                'plan' => $planKey ?? strtolower($name ?: 'unknown'),
                'status' => $status,
                'price' => config("shopify.billing.plans.{$planKey}.price", $subscription->price ?? 0),
            ]);

            // Only overwrite dates we actually received, so a cancellation keeps the
            // period end recorded while the subscription was active.
            foreach ($extra as $key => $value) {
                if ($value !== null || $key === 'test') {
                    $subscription->{$key} = $value;
                }
            }

            if ($status === 'ACTIVE') {
                $subscription->activated_at ??= now();
                $subscription->cancelled_at = null;
            } elseif (in_array($status, ['CANCELLED', 'EXPIRED', 'DECLINED'], true)) {
                $subscription->cancelled_at ??= now();
            }
            $subscription->save();

            // Shopify has one active subscription per shop. When this one activates,
            // any other "active" row is a plan the merchant switched away from.
            if ($status === 'ACTIVE') {
                $store->subscriptions()->whereKeyNot($subscription->id)->where('status', 'ACTIVE')
                    ->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
            }

            return $subscription;
        });

        $before = $store->plan;
        $this->refreshStorePlan($store);

        if ($store->plan !== $before) {
            AuditLog::record($store->plan ? 'billing.plan_activated' : 'billing.plan_ended', $store, ['plan' => $store->plan ?? $before, 'status' => $status]);
        }

        return $subscription;
    }

    /**
     * Mirrors the usable plan onto the store: the active subscription, else a
     * cancelled one whose paid period hasn't ended (grace period), else none.
     */
    private function refreshStorePlan(Store $store): void
    {
        $active = $store->subscriptions()->where('status', 'ACTIVE')->latest('id')->first();
        $grace = $active ? null : $store->subscriptions()
            ->where('status', 'CANCELLED')
            ->where('current_period_ends_at', '>', now())
            ->latest('current_period_ends_at')
            ->first();

        $current = $active ?? $grace;
        $known = $current && array_key_exists($current->plan, config('shopify.billing.plans'));

        $before = $store->plan;
        $store->forceFill([
            'plan' => $known ? $current->plan : null,
            'plan_expires_at' => $known && $grace ? $grace->current_period_ends_at : null,
        ])->save();

        // A new plan: features it doesn't include stop on the storefront and at checkout, and
        // live counts over its limits are paused, straight away.
        if ($store->plan !== $before) {
            app(\App\Services\Billing\Entitlements::class)->apply($store);
            // An upgrade within a week of clicking "Upgrade" counts for the feature that prompted it.
            $price = fn ($key) => (float) config("shopify.billing.plans.{$key}.price", 0);
            if ($store->plan && $price($store->plan) > $price((string) $before)) {
                \App\Models\UpgradeEvent::where('store_id', $store->id)->whereNull('converted_at')->where('created_at', '>=', now()->subDays(7))
                    ->update(['to_plan' => $store->plan, 'converted_at' => now()]);
            }
        }
    }
}
