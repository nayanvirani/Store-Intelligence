<?php

namespace App\Services\Shopify;

use App\Models\AuditLog;
use App\Models\Store;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Obtains expiring offline access tokens via token exchange (Shopify managed
 * installation) and refreshes them with their refresh token.
 *
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/access-tokens/token-exchange
 */
class TokenExchange
{
    public function exchange(Store $store, string $sessionToken): Store
    {
        $response = Http::asJson()->acceptJson()->post("https://{$store->shop_domain}/admin/oauth/access_token", [
            'client_id' => config('shopify.api_key'),
            'client_secret' => config('shopify.api_secret'),
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $sessionToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:offline-access-token',
            // Shopify only accepts expiring offline tokens (refreshed with the refresh token).
            'expiring' => 1,
        ]);

        if ($response->failed()) {
            throw new RuntimeException("Token exchange failed for {$store->shop_domain}: HTTP {$response->status()}");
        }

        $firstInstall = $store->installed_at === null || $store->uninstalled_at !== null;

        $this->storeTokens($store, $response->json());
        $store->forceFill([
            'installed_at' => $firstInstall ? now() : $store->installed_at,
            'uninstalled_at' => null,
        ])->save();

        AuditLog::record($firstInstall ? 'store.installed' : 'store.token_refreshed', $store, ['scopes' => $store->scopes]);
        if ($firstInstall) {
            // Installing is agreeing to the Terms and policies in force today: keep which versions.
            \App\Support\Legal::acknowledge($store, 'install');
        }

        return $store;
    }

    /**
     * Identifies the Shopify staff member behind a session token. Uses an online-token
     * exchange for the associated_user details; the online token itself is not stored.
     *
     * @return array{id: int, first_name: ?string, last_name: ?string, email: ?string, account_owner: bool}|null
     */
    public function associatedUser(Store $store, string $sessionToken): ?array
    {
        $response = Http::asJson()->acceptJson()->post("https://{$store->shop_domain}/admin/oauth/access_token", [
            'client_id' => config('shopify.api_key'),
            'client_secret' => config('shopify.api_secret'),
            'grant_type' => 'urn:ietf:params:oauth:grant-type:token-exchange',
            'subject_token' => $sessionToken,
            'subject_token_type' => 'urn:ietf:params:oauth:token-type:id_token',
            'requested_token_type' => 'urn:shopify:params:oauth:token-type:online-access-token',
        ]);

        $user = $response->successful() ? $response->json('associated_user') : null;

        return $user ? [
            'id' => (int) $user['id'],
            'first_name' => $user['first_name'] ?? null,
            'last_name' => $user['last_name'] ?? null,
            'email' => $user['email'] ?? null,
            'account_owner' => (bool) ($user['account_owner'] ?? false),
        ] : null;
    }

    public function refresh(Store $store): Store
    {
        if ($store->refresh_token === null) {
            return $store;
        }

        $response = Http::asJson()->acceptJson()->post("https://{$store->shop_domain}/admin/oauth/access_token", [
            'client_id' => config('shopify.api_key'),
            'client_secret' => config('shopify.api_secret'),
            'grant_type' => 'refresh_token',
            'refresh_token' => $store->refresh_token,
        ]);

        if ($response->failed()) {
            // Shopify rejected the refresh token (expired or revoked): drop it, so the next time the
            // merchant opens the app a token exchange gets a new expiring token instead of failing again.
            if (in_array($response->status(), [400, 401], true)) {
                $store->forceFill(['refresh_token' => null, 'access_token_expires_at' => now()->subMinute()])->save();
                AuditLog::record('store.token_refresh_rejected', $store, ['status' => $response->status()]);
            }
            throw new RuntimeException("Token refresh failed for {$store->shop_domain}: HTTP {$response->status()}");
        }

        $this->storeTokens($store, $response->json());
        $store->save();

        return $store;
    }

    private function storeTokens(Store $store, array $data): void
    {
        $store->forceFill([
            'access_token' => $data['access_token'],
            'scopes' => $data['scope'] ?? $store->scopes,
            'refresh_token' => $data['refresh_token'] ?? null,
            'access_token_expires_at' => isset($data['expires_in']) ? now()->addSeconds((int) $data['expires_in']) : null,
        ]);
    }
}
