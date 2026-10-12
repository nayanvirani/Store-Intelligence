<?php

namespace Tests\Feature;

use App\Services\Shopify\AdminApi;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class TokenRefreshTest extends TestCase
{
    use InteractsWithShopify;
    use RefreshDatabase;

    public function test_an_expired_token_is_refreshed_before_the_call(): void
    {
        $this->setUpShopify();
        $store = $this->installedStore(['refresh_token' => 'shprt_old', 'access_token_expires_at' => now()->subHour()]);
        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_new', 'refresh_token' => 'shprt_new', 'expires_in' => 3600, 'scope' => $store->scopes]),
            '*/graphql.json' => Http::response(['data' => ['shop' => ['name' => 'Demo']]]),
        ]);

        $this->assertSame(['shop' => ['name' => 'Demo']], app(AdminApi::class)->graphql($store, '{ shop { name } }'));
        $store->refresh();
        $this->assertSame(['shpat_new', 'shprt_new'], [$store->access_token, $store->refresh_token]);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/graphql.json') && $r->header('X-Shopify-Access-Token')[0] === 'shpat_new');
    }

    public function test_a_rejected_refresh_token_is_dropped_so_the_next_app_open_exchanges_a_new_one(): void
    {
        $this->setUpShopify();
        $store = $this->installedStore(['refresh_token' => 'shprt_dead', 'access_token_expires_at' => now()->subHour()]);
        Http::fake(['*/admin/oauth/access_token' => Http::response(['error' => 'invalid_grant'], 400)]);

        try {
            app(AdminApi::class)->graphql($store, '{ shop { name } }');
            $this->fail('Expected the refresh to fail.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('Token refresh failed', $e->getMessage());
        }
        $this->assertNull($store->refresh()->refresh_token);
        $this->assertDatabaseHas('audit_logs', ['action' => 'store.token_refresh_rejected']);
    }
}
