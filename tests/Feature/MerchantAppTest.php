<?php

namespace Tests\Feature;

use App\Models\CostRule;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Store;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class MerchantAppTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
        Http::fake(fn () => Http::response(['data' => []]));
        Queue::fake();
        $this->store = $this->installedStore(['plan' => 'growth', 'timezone' => 'UTC']);
    }

    private function catalog(Store $store, int $offset = 0): Variant
    {
        $product = Product::create(['store_id' => $store->id, 'shopify_id' => 1 + $offset, 'title' => 'Vitamin C Serum', 'status' => 'ACTIVE']);
        Variant::create(['store_id' => $store->id, 'product_id' => $product->id, 'shopify_id' => 11 + $offset, 'sku' => 'VC-30', 'price' => 40, 'shopify_cost' => 12]);

        return Variant::create(['store_id' => $store->id, 'product_id' => $product->id, 'shopify_id' => 12 + $offset, 'sku' => 'VC-60', 'price' => 70]);
    }

    public function test_home_shows_the_profit_snapshot_sync_and_checklist(): void
    {
        $owner = $this->member($this->store, 'owner');
        $variant = $this->catalog($this->store);
        $order = Order::create(['store_id' => $this->store->id, 'shopify_id' => 9, 'processed_at' => now()->subDay(), 'gross_sales' => 70, 'total_price' => 70, 'units' => 1]);
        OrderLine::create(['store_id' => $this->store->id, 'order_id' => $order->id, 'shopify_id' => 1, 'variant_shopify_id' => $variant->shopify_id, 'quantity' => 1, 'price' => 70]);

        $this->get('/app', $this->as($owner))->assertOk()->assertSee('<div id="root"></div>', false);
        $this->page('/app?range=7', $owner)->assertOk()->assertJsonPath('component', 'home')
            ->assertJsonPath('props.range', '7')
            ->assertJsonPath('props.profit.net_sales', 70)
            ->assertJsonPath('props.profit.coverage.percent', 0)
            ->assertJsonPath('props.counts.missing_costs', 1)
            ->assertJsonPath('props.checklist.0.key', 'connected')
            ->assertJsonPath('props.sync.orders.status', 'never');

        // Free plan: a 30-day snapshot only.
        $free = $this->installedStore(['shop_domain' => 'free.myshopify.com', 'plan' => 'free']);
        $freeOwner = $this->member($free, 'owner');
        $this->getJson('/app?range=90', ['Authorization' => 'Bearer '.$this->sessionToken($freeOwner->shopify_user_id, 'free.myshopify.com'), \App\Support\Spa\Page::HEADER => '1'])->assertOk()
            ->assertJsonPath('props.range', '30')->assertJsonCount(1, 'props.ranges');
    }

    public function test_products_list_missing_costs_first_and_costs_can_be_set(): void
    {
        $owner = $this->member($this->store, 'owner');
        $missing = $this->catalog($this->store);
        $order = Order::create(['store_id' => $this->store->id, 'shopify_id' => 9, 'processed_at' => now()->subDays(3)]);
        OrderLine::create(['store_id' => $this->store->id, 'order_id' => $order->id, 'shopify_id' => 1, 'variant_shopify_id' => $missing->shopify_id, 'quantity' => 2, 'price' => 70]);

        $this->page('/app/products', $owner)->assertOk()->assertJsonPath('component', 'products/index')
            ->assertJsonPath('props.filter', 'missing')->assertJsonCount(1, 'props.variants.data')
            ->assertJsonPath('props.variants.data.0.sku', 'VC-60')->assertJsonPath('props.variants.data.0.units_30d', 2)
            ->assertJsonPath('props.coverage.percent', 50);
        $this->page('/app/products?filter=all&q=VC-30', $owner)->assertJsonCount(1, 'props.variants.data')->assertJsonPath('props.variants.data.0.source', 'shopify');

        $this->send("/app/products/variants/{$missing->id}/cost", ['cost' => '21.5', 'filter' => 'missing'], $owner)
            ->assertJsonPath('notice.message', 'Cost saved. Profit now uses it, including past orders that had no cost.')
            ->assertJsonPath('redirect', '/app/products?filter=missing');
        $this->assertSame([21.5, 'app'], [$missing->fresh()->effectiveCost(), $missing->fresh()->costSource()]);
        $this->assertSame('21.5000', (string) OrderLine::sole()->unit_cost, 'The past order without a cost gets it.');
        $this->assertDatabaseHas('audit_logs', ['action' => 'cost.updated']);

        $this->send("/app/products/variants/{$missing->id}/cost", ['cost' => '-4'], $owner)->assertStatus(422);
    }

    public function test_roles_and_stores_are_kept_apart(): void
    {
        $analyst = $this->member($this->store, 'analyst');
        $variant = $this->catalog($this->store);
        $this->page('/app/products', $analyst)->assertOk();
        $this->send("/app/products/variants/{$variant->id}/cost", ['cost' => 5], $analyst)->assertForbidden();
        $this->send('/app/settings/costs', ['type' => 'handling', 'name' => 'Box', 'config' => ['amount' => 1]], $analyst)->assertForbidden();
        $this->send('/app/settings/general', ['default_range' => 'last_7_days'], $analyst)->assertForbidden();
        $this->send('/app/settings/store/sync', [], $analyst)->assertForbidden();

        // Another store's variant can't be touched by changing the ID.
        $other = $this->installedStore(['shop_domain' => 'other.myshopify.com']);
        $theirs = $this->catalog($other, 100);
        $owner = $this->member($this->store, 'owner');
        $this->send("/app/products/variants/{$theirs->id}/cost", ['cost' => 5], $owner)->assertNotFound();
        $this->assertNull($theirs->fresh()->app_cost);
        $this->page('/app/products?filter=all', $owner)->assertJsonCount(2, 'props.variants.data');
    }

    public function test_general_settings_and_cost_rules(): void
    {
        $owner = $this->member($this->store, 'owner');
        $this->send('/app/settings/general', ['default_range' => 'last_7_days', 'week_starts_on' => 'sunday', 'exclude_test_orders' => false, 'default_margin' => '55',
            'digest' => 'weekly', 'digest_hour' => 7, 'alert_emails' => 'Ops@Store.com, ops@store.com'], $owner)->assertJsonPath('notice.message', 'Changes saved successfully.');
        $store = $this->store->fresh();
        $this->assertSame(['last_7_days', false, 55.0, 'weekly', ['ops@store.com']], [$store->setting('default_range'), $store->setting('exclude_test_orders'), (float) $store->setting('default_margin'), $store->setting('digest'), $store->setting('alert_emails')]);
        $this->send('/app/settings/general', ['default_range' => 'last_7_days', 'week_starts_on' => 'monday', 'digest' => 'daily', 'digest_hour' => 8, 'alert_emails' => 'nope'], $owner)
            ->assertStatus(422)->assertJsonValidationErrors('alert_emails');

        $this->send('/app/settings/costs', ['type' => 'payment_fee', 'name' => 'Shopify Payments', 'config' => ['percent' => '2.9', 'fixed' => '0.30', 'gateway' => 'shopify_payments']], $owner)->assertJsonPath('redirect', '/app/settings/costs');
        $rule = CostRule::sole();
        $this->assertSame(['percent' => 2.9, 'fixed' => 0.3, 'gateway' => 'shopify_payments'], $rule->config);
        $this->page('/app/settings/costs', $owner)->assertJsonPath('props.rules.0.summary', '2.9% + $0.30 per order (shopify_payments)');
        $this->send('/app/settings/costs', ['type' => 'shipping', 'name' => 'Flat', 'config' => ['amount' => '5']], $owner)->assertStatus(422);
        $this->send("/app/settings/costs/{$rule->id}", ['name' => 'Stripe', 'config' => ['percent' => 3, 'fixed' => 0.3], 'active' => false], $owner)->assertJsonPath('redirect', '/app/settings/costs');
        $this->assertSame(['Stripe', false], [$rule->fresh()->name, $rule->fresh()->active]);
        $this->send("/app/settings/costs/{$rule->id}/delete", [], $owner)->assertJsonPath('redirect', '/app/settings/costs');
        $this->assertSame(0, CostRule::count());
    }

    public function test_manual_sync_onboarding_and_team_limit(): void
    {
        $owner = $this->member($this->store, 'owner');
        $this->send('/app/settings/store/sync', [], $owner)->assertJsonPath('notice.message', 'Sync started. New data appears within a few minutes.');
        $this->assertDatabaseCount('sync_runs', 2);
        $this->send('/app/settings/store/sync', [], $owner)->assertJsonPath('notice.error', true);
        $this->assertDatabaseCount('sync_runs', 2);

        $this->page('/app/onboarding', $owner)->assertOk()->assertJsonPath('props.completed', false)->assertJsonCount(5, 'props.checklist');
        $this->send('/app/onboarding/complete', [], $owner)->assertJsonPath('redirect', '/app');
        $this->assertNotNull($this->store->fresh()->onboarding_completed_at);

        // Growth includes 3 team members.
        $this->member($this->store, 'analyst');
        $this->member($this->store, 'marketer');
        $this->send('/app/settings/users', ['email' => 'four@store.com', 'role' => 'analyst'], $owner)->assertJsonPath('notice.error', true);
        $this->assertDatabaseMissing('store_users', ['email' => 'four@store.com']);
    }
}
