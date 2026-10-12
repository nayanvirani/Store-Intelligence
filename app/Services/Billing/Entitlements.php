<?php

namespace App\Services\Billing;

use App\Models\AuditLog;
use App\Models\Store;

/**
 * Applies what a store may use whenever the plan, the plan's features or limits, or the store's own
 * overrides change, and on a schedule (si:apply-entitlements) for changes nothing else announces,
 * such as a complimentary plan running out.
 */
class Entitlements
{
    public function __construct(private readonly PlanLimits $limits) {}

    /** @return bool whether anything was applied */
    public function apply(Store $store, bool $force = false): bool
    {
        if (! $store->isInstalled()) {
            return false;
        }
        $fingerprint = $store->entitlementsFingerprint();
        if (! $force && $fingerprint === $store->entitlements_hash) {
            return false;
        }

        $changed = $this->limits->apply($store);

        $store->forceFill(['entitlements_hash' => $fingerprint])->save();
        AuditLog::record('store.entitlements_applied', $store, ['plan' => $store->effectivePlan(), 'changed' => $changed]);

        return true;
    }
}
