<?php

namespace Tests\Concerns;

use App\Models\Store;
use App\Models\StoreUser;
use Illuminate\Support\Facades\Http;

trait InteractsWithShopify
{
    protected string $shop = 'demo.myshopify.com';

    protected function setUpShopify(): void
    {
        config([
            'shopify.api_key' => 'test-key',
            'shopify.api_secret' => 'test-secret',
            'shopify.scopes' => 'read_products,read_orders',
        ]);
    }

    protected function installedStore(array $attributes = []): Store
    {
        return Store::create(array_merge([
            'shop_domain' => $this->shop,
            'access_token' => 'shpat_test',
            'refresh_token' => 'shprt_test',
            'access_token_expires_at' => now()->addHour(),
            'scopes' => 'read_products,read_orders',
            'name' => 'Demo Store',
            'currency' => 'USD',
            'plan' => 'growth',
            'installed_at' => now(),
        ], $attributes));
    }

    protected function member(Store $store, string $role, array $attributes = []): StoreUser
    {
        static $id = 1000;

        return StoreUser::create(array_merge([
            'store_id' => $store->id,
            'shopify_user_id' => ++$id,
            'first_name' => ucfirst($role),
            'last_name' => 'User',
            'email' => $role.$id.'@example.com',
            'role' => $role,
        ], $attributes));
    }

    protected function sessionToken(int|string $sub, ?string $shop = null): string
    {
        $shop ??= $this->shop;
        $claims = [
            'iss' => "https://{$shop}/admin", 'dest' => "https://{$shop}", 'aud' => 'test-key', 'sub' => (string) $sub,
            'exp' => time() + 60, 'nbf' => time() - 1, 'iat' => time() - 1, 'jti' => uniqid(), 'sid' => 'sid',
        ];
        $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $input = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);

        return $input.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $input, 'test-secret', true)), '+/', '-_'), '=');
    }

    protected function as(StoreUser|int $user): array
    {
        $sub = $user instanceof StoreUser ? $user->shopify_user_id : $user;

        return ['Authorization' => 'Bearer '.$this->sessionToken($sub)];
    }

    /**
     * A React admin page as the client fetches it: {component, props, shared}.
     */
    protected function page(string $url, StoreUser|int $user): \Illuminate\Testing\TestResponse
    {
        return $this->getJson($url, $this->as($user) + [\App\Support\Spa\Page::HEADER => '1']);
    }

    /**
     * A form submit as the React admin sends it (JSON, X-OO-Page): redirects come back as {redirect, notice}.
     */
    protected function send(string $url, array $data, StoreUser|int $user): \Illuminate\Testing\TestResponse
    {
        return $this->postJson($url, $data, $this->as($user) + [\App\Support\Spa\Page::HEADER => '1']);
    }

    protected function fakeAssociatedUser(array $user): void
    {
        Http::fake(["{$this->shop}/admin/oauth/access_token" => Http::response([
            'access_token' => 'online', 'scope' => 'read_products', 'associated_user' => $user,
        ])]);
    }
}
