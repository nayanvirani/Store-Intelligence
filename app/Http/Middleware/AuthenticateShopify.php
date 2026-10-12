<?php

namespace App\Http\Middleware;

use App\Exceptions\InvalidSessionToken;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\StoreUser;
use App\Services\Shopify\Billing;
use App\Services\Shopify\SessionToken;
use App\Services\Shopify\ShopDomain;
use App\Services\Shopify\StoreSync;
use App\Services\Shopify\TokenExchange;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Authenticates embedded-app requests with App Bridge session tokens.
 *
 * Document requests without a fresh token get a "bounce" page that asks App Bridge
 * for one and reloads; XHR/fetch requests get a 401 with Shopify's retry header.
 */
class AuthenticateShopify
{
    public function __construct(
        private readonly SessionToken $sessionTokens,
        private readonly TokenExchange $tokenExchange,
        private readonly StoreSync $storeSync,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $token = $request->bearerToken() ?? $request->input('id_token');

        try {
            $claims = $token ? $this->sessionTokens->decode($token) : null;
        } catch (InvalidSessionToken) {
            $claims = null;
        }

        if ($claims === null) {
            return $this->unauthenticated($request);
        }

        $shop = SessionToken::shopFromClaims($claims);
        $store = Store::firstOrNew(['shop_domain' => $shop]);

        // A store without a refresh token holds a legacy non-expiring token, which Shopify rejects.
        $needsExchange = ! $store->exists || ! $store->isInstalled() || $store->missingScopes() !== [] || $store->refresh_token === null;
        if (! $needsExchange && $store->needsTokenRefresh()) {
            try {
                $this->tokenExchange->refresh($store);
            } catch (Throwable $e) {
                // Refresh token expired or revoked: the session token gets a new pair.
                $needsExchange = true;
            }
        }

        if ($needsExchange) {
            $store = $this->tokenExchange->exchange($store, $token);

            try {
                $this->storeSync->sync($store);
            } catch (Throwable $e) {
                report($e);
            }

            // Under Managed Pricing the merchant may have chosen a plan during install,
            // before the app_subscriptions/update webhook reaches us.
            try {
                app(Billing::class)->sync($store);
            } catch (Throwable $e) {
                report($e);
            }

            // First import of products and orders (once per install).
            try {
                app(\App\Services\Sync\SyncManager::class)->startInitial($store);
            } catch (Throwable $e) {
                report($e);
            }
        }

        $request->attributes->set('store', $store);
        app()->instance(Store::class, $store);

        $user = isset($claims['sub']) ? $this->resolveUser($store, (int) $claims['sub'], $token) : null;
        $request->attributes->set('storeUser', $user);

        if ($user?->disabled_at !== null) {
            $response = page('forbidden', ['removed' => true], 403)->toResponse($request);
        } else {
            $response = $next($request);
        }

        $response->headers->set('Content-Security-Policy', "frame-ancestors https://{$shop} https://admin.shopify.com;");

        return $response;
    }

    private function resolveUser(Store $store, int $shopifyUserId, string $token): StoreUser
    {
        $user = StoreUser::where('store_id', $store->id)->where('shopify_user_id', $shopifyUserId)->first();

        $info = null;
        if ($user === null || $user->email === null) {
            try {
                $info = $this->tokenExchange->associatedUser($store, $token);
            } catch (Throwable $e) {
                report($e);
            }
        }

        if ($user === null) {
            $user = $this->claimInvite($store, $info['email'] ?? null) ?? new StoreUser([
                'store_id' => $store->id,
                'role' => $store->activeOwners()->exists() ? 'analyst' : 'owner',
            ]);
            $user->shopify_user_id = $shopifyUserId;
            $isNew = ! $user->exists;
        }

        if ($info !== null) {
            $user->fill(array_intersect_key($info, array_flip(['first_name', 'last_name', 'email', 'account_owner'])));
            // The Shopify account owner is always a Store Intelligence owner.
            if ($info['account_owner']) {
                $user->role = 'owner';
            }
        }

        if ($user->last_active_at === null || $user->last_active_at->lt(now()->subMinutes(5))) {
            $user->last_active_at = now();
        }

        $user->save();

        if ($isNew ?? false) {
            request()->attributes->set('storeUser', $user);
            AuditLog::record('user.joined', $store, ['role' => $user->role], $user);
        }

        return $user;
    }

    private function claimInvite(Store $store, ?string $email): ?StoreUser
    {
        if ($email === null) {
            return null;
        }

        return StoreUser::where('store_id', $store->id)
            ->whereNull('shopify_user_id')
            ->whereNull('disabled_at')
            ->whereRaw('lower(email) = ?', [strtolower($email)])
            ->first();
    }

    private function unauthenticated(Request $request): Response
    {
        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            $shop = $request->query('shop');
            $ancestors = ShopDomain::isValid($shop) ? "https://{$shop}" : 'https://*.myshopify.com';

            // A token that still fails after one bounce will never succeed; stop the loop.
            $view = $request->query('bounced') ? 'app.session-error' : 'app.bounce';

            return response()->view($view, [], $view === 'app.bounce' ? 200 : 401)
                ->header('Content-Security-Policy', "frame-ancestors {$ancestors} https://admin.shopify.com;");
        }

        return response()->json(['message' => 'Your session has expired. Please reopen Store Intelligence from Shopify admin.'], 401)
            ->header('X-Shopify-Retry-Invalid-Session-Request', '1');
    }
}
