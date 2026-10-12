<?php

namespace Tests\Feature;

use App\Models\Store;
use App\Models\Subscription;
use App\Services\Shopify\Billing;
use App\Services\Usage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class ManagedPricingTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
    }

    private function shopifyReports(array $subscriptions): void
    {
        Http::fake(["{$this->shop}/admin/api/*" => Http::response(['data' => ['currentAppInstallation' => ['activeSubscriptions' => $subscriptions]]])]);
    }

    private function active(string $id, string $name, string $periodEnd = '+30 days'): array
    {
        return ['id' => $id, 'name' => $name, 'status' => 'ACTIVE', 'test' => true, 'trialDays' => 0, 'createdAt' => now()->toIso8601String(), 'currentPeriodEnd' => now()->modify($periodEnd)->toIso8601String()];
    }

    private function webhook(string $status, string $id, string $name, string $webhookId)
    {
        $body = json_encode(['app_subscription' => ['admin_graphql_api_id' => $id, 'name' => $name, 'status' => $status]]);

        return $this->call('POST', '/webhooks/shopify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_TOPIC' => 'app_subscriptions/update',
            'HTTP_X_SHOPIFY_SHOP_DOMAIN' => $this->shop,
            'HTTP_X_SHOPIFY_WEBHOOK_ID' => $webhookId,
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'test-secret', true)),
        ], $body);
    }

    public function test_plan_names_map_case_insensitively(): void
    {
        $this->assertSame('growth', Billing::planKeyFromName('Growth'));
        $this->assertSame('growth', Billing::planKeyFromName('GROWTH'));
        $this->assertSame('pro', Billing::planKeyFromName('Store Intelligence Pro'));
        $this->assertSame('pro', Billing::planKeyFromName('pro'));
        $this->assertSame('free', Billing::planKeyFromName('Free'));
        $this->assertNull(Billing::planKeyFromName('Enterprise'));

        config(['shopify.billing.plans.starter.shopify_name' => 'Basic']);
        $this->assertSame('starter', Billing::planKeyFromName('basic'));
    }

    public function test_activation_webhook_records_plan_and_period_end(): void
    {
        $store = $this->installedStore(['plan' => null]);
        $this->shopifyReports([$this->active('gid://shopify/AppSubscription/1', 'Growth')]);

        $this->webhook('ACTIVE', 'gid://shopify/AppSubscription/1', 'Growth', 'w1')->assertNoContent();

        $store->refresh();
        $this->assertSame('growth', $store->plan);
        $this->assertNull($store->plan_expires_at);
        $this->assertNotNull(Subscription::first()->current_period_ends_at);
    }

    public function test_switching_plans_supersedes_the_old_subscription(): void
    {
        $store = $this->installedStore(['plan' => null]);
        $billing = app(Billing::class);

        $report = fn (array $subs) => ['data' => ['currentAppInstallation' => ['activeSubscriptions' => $subs]]];
        Http::fake(["{$this->shop}/admin/api/*" => Http::sequence()
            ->push($report([$this->active('gid://shopify/AppSubscription/1', 'Starter')]))
            ->push($report([$this->active('gid://shopify/AppSubscription/2', 'Pro')]))]);

        $billing->sync($store);
        $this->assertSame('starter', $store->fresh()->plan);
        $billing->sync($store->fresh());

        $this->assertSame('pro', $store->fresh()->plan);
        $this->assertSame('CANCELLED', Subscription::where('shopify_subscription_id', 'gid://shopify/AppSubscription/1')->value('status'));
    }

    public function test_cancellation_keeps_access_until_the_paid_period_ends(): void
    {
        $store = $this->installedStore(['plan' => null]);
        $this->shopifyReports([$this->active('gid://shopify/AppSubscription/1', 'Growth', '+10 days')]);
        app(Billing::class)->sync($store);

        $this->webhook('CANCELLED', 'gid://shopify/AppSubscription/1', 'Growth', 'w2')->assertNoContent();

        $store->refresh();
        $this->assertSame('growth', $store->plan);
        $this->assertTrue($store->plan_expires_at->isFuture());
        $this->assertTrue($store->hasPlanAccess());

        $this->travel(11)->days();
        $this->assertFalse($store->fresh()->hasPlanAccess());
        $this->assertFalse(app(Usage::class)->allows($store->fresh(), 'active_experiences'));
    }

    public function test_frozen_or_unknown_plans_give_no_access(): void
    {
        $store = $this->installedStore(['plan' => null]);

        $this->webhook('FROZEN', 'gid://shopify/AppSubscription/9', 'Growth', 'w3')->assertNoContent();
        $this->assertFalse($store->fresh()->hasPlanAccess());

        $this->shopifyReports([$this->active('gid://shopify/AppSubscription/10', 'Enterprise')]);
        app(Billing::class)->sync($store->fresh());
        $this->assertNull($store->fresh()->plan);
    }

    public function test_test_shops_get_access_without_a_subscription(): void
    {
        config(['shopify.test_shops' => [$this->shop], 'shopify.test_shop_plan' => 'growth']);
        $store = $this->installedStore(['plan' => null]);

        $this->assertTrue($store->hasPlanAccess());
        $this->assertSame('growth', $store->effectivePlan());
        $this->assertSame(3, $store->planLimit('team_members'));
        $this->assertFalse($this->installedStore(['shop_domain' => 'real.myshopify.com', 'plan' => null])->hasPlanAccess());
    }

    public function test_shops_without_a_plan_only_see_billing(): void
    {
        $store = $this->installedStore(['plan' => null]);
        $owner = $this->member($store, 'owner');
        $this->shopifyReports([]);

        foreach (['/app', '/app/products', '/app/settings/store', '/app/settings/costs', '/app/onboarding'] as $path) {
            $this->get($path, $this->as($owner))->assertRedirectContains('/app/settings/billing');
        }
        $this->page('/app/settings/billing', $owner)->assertOk()
            ->assertJsonPath('props.effective', null)->assertJsonPath('shared.nav', null)->assertJsonCount(4, 'props.plans');
    }

    public function test_returning_from_shopifys_plan_page_syncs_the_new_subscription(): void
    {
        $store = $this->installedStore(['plan' => null]);
        $owner = $this->member($store, 'owner');
        $this->shopifyReports([$this->active('gid://shopify/AppSubscription/77', 'Pro')]);

        $this->get('/app?charge_id=77', $this->as($owner))->assertRedirectContains('notice=plan_updated');
        $this->assertSame('pro', $store->fresh()->plan);
        $this->get('/app', $this->as($owner))->assertOk();
    }

    public function test_plan_changes_are_picked_up_on_return_even_with_an_existing_plan(): void
    {
        $store = $this->installedStore(['plan' => null]);
        $owner = $this->member($store, 'owner');
        $report = fn (array $subs) => ['data' => ['currentAppInstallation' => ['activeSubscriptions' => $subs]]];
        Http::fake(["{$this->shop}/admin/api/*" => Http::sequence()
            ->push($report([$this->active('gid://shopify/AppSubscription/1', 'Starter')]))
            ->push($report([$this->active('gid://shopify/AppSubscription/2', 'Growth')]))]);

        app(Billing::class)->sync($store);
        $this->get('/app?charge_id=2', $this->as($owner))->assertRedirectContains('notice=plan_updated');
        $this->assertSame('growth', $store->fresh()->plan);
    }

    public function test_the_app_handle_comes_from_shopify(): void
    {
        $store = $this->installedStore(['plan' => null]);
        Http::fake(["{$this->shop}/admin/api/*" => Http::response(['data' => ['currentAppInstallation' => ['app' => ['handle' => 'orderorbit-3'], 'activeSubscriptions' => []]]])]);

        app(Billing::class)->sync($store);

        $this->assertSame('https://admin.shopify.com/store/demo/charges/orderorbit-3/pricing_plans', $store->fresh()->pricingUrl());
    }

    public function test_billing_page_links_to_shopifys_plan_picker(): void
    {
        config(['shopify.app_handle' => 'orderorbit-app']);
        Cache::forget('shopify.app_handle');
        $store = $this->installedStore(['plan' => null]);
        $owner = $this->member($store, 'owner');
        $staff = $this->member($store, 'analyst');
        $this->shopifyReports([]);

        $this->page('/app/settings/billing', $owner)->assertOk()
            ->assertJsonPath('props.pricingUrl', 'https://admin.shopify.com/store/demo/charges/orderorbit-app/pricing_plans');
        $this->page('/app/settings/billing', $staff)->assertOk()
            ->assertJsonPath('props.pricingUrl', null)
            ->assertDontSee('charges\/orderorbit-app\/pricing_plans', false);
        $this->assertSame('https://admin.shopify.com/store/demo/charges/orderorbit-app/pricing_plans', Store::first()->pricingUrl());
    }
}
