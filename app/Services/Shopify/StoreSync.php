<?php

namespace App\Services\Shopify;

use App\Models\Store;

/**
 * Pulls shop details and granted scopes after install and on demand.
 */
class StoreSync
{
    public function __construct(private readonly AdminApi $api) {}

    public function sync(Store $store): Store
    {
        $data = $this->api->graphql($store, <<<'GQL'
            {
              shop {
                name email currencyCode ianaTimezone
                billingAddress { countryCodeV2 }
                enabledPresentmentCurrencies
                plan { displayName partnerDevelopment shopifyPlus }
              }
              currentAppInstallation { accessScopes { handle } }
            }
            GQL);

        $shop = $data['shop'];
        $plan = $shop['plan'] ?? [];

        $store->forceFill([
            'name' => $shop['name'],
            'email' => $shop['email'],
            'currency' => $shop['currencyCode'],
            'timezone' => $shop['ianaTimezone'],
            'country' => $shop['billingAddress']['countryCodeV2'] ?? $store->country,
            'shopify_plan' => $plan['displayName'] ?? null,
            'scopes' => implode(',', array_column($data['currentAppInstallation']['accessScopes'] ?? [], 'handle')) ?: $store->scopes,
            'capabilities' => array_merge($store->capabilities ?? [], [
                'plus' => (bool) ($plan['shopifyPlus'] ?? false),
                'development_store' => (bool) ($plan['partnerDevelopment'] ?? false),
                'presentment_currencies' => $shop['enabledPresentmentCurrencies'] ?? [],
            ]),
            'capabilities_checked_at' => now(),
        ])->save();

        return $store;
    }
}
