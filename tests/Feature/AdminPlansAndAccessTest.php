<?php

namespace Tests\Feature;

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class AdminPlansAndAccessTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
        Http::fake(fn () => Http::response(['data' => []]));
    }

    private function admin(string $role = 'super_admin', string $email = 'ava@example.com'): User
    {
        return User::forceCreate(['name' => 'Ava Team', 'email' => $email, 'password' => 'secret-password-123', 'is_admin' => true, 'admin_role' => $role]);
    }

    public function test_every_admin_page_renders(): void
    {
        $admin = $this->admin();
        $store = $this->installedStore();
        $plan = Plan::where('key', 'growth')->sole();
        foreach (['/admin', '/admin/stores', '/admin/stores?status=custom&plan=growth', "/admin/stores/{$store->id}", "/admin/stores/{$store->id}?tab=access", "/admin/stores/{$store->id}?tab=activity",
            '/admin/plans', '/admin/plans/new', "/admin/plans/{$plan->id}", '/admin/settings', '/admin/team', '/admin/tickets', '/admin/messages', '/admin/failures',
            '/admin/sync', '/admin/flags', '/admin/audit', '/admin/account', '/admin/legal', '/admin/legal/new', '/admin/legal/1', '/admin/legal/1/versions/1',
            '/admin/email', '/admin/email/log', '/admin/finance'] as $url) {
            $this->actingAs($admin)->get($url)->assertOk();
        }
    }

    public function test_plans_are_editable_and_reach_the_app_and_pricing_page(): void
    {
        $admin = $this->admin();
        $plan = Plan::where('key', 'starter')->sole();
        $this->actingAs($admin)->post("/admin/plans/{$plan->id}", [
            'name' => 'Starter', 'shopify_name' => 'Starter', 'price' => '19.99', 'badge' => 'Best value', 'support_label' => 'Standard', 'trial_days' => 7,
            'modules' => ['profit_dashboard', 'url_monitor', 'ads_dashboard'], 'limits' => ['monitored_urls' => '3'], 'features' => "Line one\nLine two",
            'is_active' => '1', 'is_public' => '1',
        ])->assertRedirect('/admin/plans');

        $this->assertSame(19.99, config('shopify.billing.plans.starter.price'));
        $this->assertSame(['profit_dashboard', 'url_monitor', 'ads_dashboard'], config('shopify.billing.plans.starter.includes'));
        $this->assertSame(3, config('shopify.billing.plans.starter.limits.monitored_urls'));
        $store = $this->installedStore(['plan' => 'starter']);
        $this->assertTrue($store->planIncludes('ads_dashboard'));
        $this->assertFalse($store->planIncludes('marketing_calendar'), 'Unticked modules are off.');
        $this->assertSame(3, $store->planLimit('monitored_urls'));
        $this->assertSame(0, $store->planLimit('scheduled_posts'), 'A limit of a module that is off is 0.');
        $this->get('/pricing')->assertOk()->assertSee('$19.99')->assertSee('Best value');

        // The module grid: switch the marketing calendar on for Starter, ads off.
        $grid = Plan::all()->mapWithKeys(fn ($p) => [$p->key => array_fill_keys($p->modules, '1')])->all();
        $grid['starter'] = ['profit_dashboard' => '1', 'url_monitor' => '1', 'marketing_calendar' => '1'];
        $this->actingAs($admin)->post('/admin/plans/modules', ['grid' => $grid])->assertRedirect('/admin/plans');
        $this->assertEqualsCanonicalizing(['profit_dashboard', 'url_monitor', 'marketing_calendar'], Plan::where('key', 'starter')->value('modules'));
        $this->assertTrue($store->fresh()->planIncludes('marketing_calendar'));
        $this->assertFalse($store->fresh()->planIncludes('ads_dashboard'));

        // New plans; hidden plans stay off the pricing pages.
        $this->actingAs($admin)->post('/admin/plans', ['key' => 'partner', 'name' => 'Partner', 'shopify_name' => 'Partner', 'price' => 199, 'modules' => ['profit_dashboard'], 'is_active' => '1'])->assertRedirect('/admin/plans');
        $this->assertSame('Partner', config('shopify.billing.plans.partner.name'));
        $this->assertArrayNotHasKey('partner', \App\Support\Plans::public());

        // Limits in the same grid (empty = unlimited).
        $limits = ['starter' => ['monitored_urls' => '7', 'history_days' => '']];
        $this->actingAs($admin)->post('/admin/plans/modules', ['grid' => $grid, 'limits' => $limits])->assertRedirect('/admin/plans');
        $this->assertSame([7, null], [Plan::where('key', 'starter')->value('limits')['monitored_urls'], Plan::where('key', 'starter')->value('limits')['history_days']]);
        $this->assertSame(7, $store->fresh()->planLimit('monitored_urls'));
        // Plan lines quoting a limit follow it; a line whose limit is 0 is hidden.
        Plan::where('key', 'starter')->update(['features' => json_encode(['{monitored_urls|monitored URL}', '{scheduled_posts} posts/month', 'Everything in Free'])]);
        \App\Support\Plans::forget();
        $this->assertSame(['7 monitored URLs', 'Everything in Free'], \App\Support\Plans::lines(config('shopify.billing.plans.starter')));
        $this->get('/pricing')->assertSee('7 monitored URLs');

        // Feature names and pricing-page visibility, and the pricing page's own text.
        $this->actingAs($admin)->post('/admin/plans/catalog', ['catalog' => ['marketing_calendar' => ['label' => 'Campaign calendar', 'public' => '1'], 'url_monitor' => ['label' => 'Link checker']]])->assertRedirect('/admin/plans');
        $this->actingAs($admin)->post('/admin/plans/pricing-page', ['headline' => 'Simple plans for every store', 'lead' => 'Start free.', 'billing_note' => 'Billed through Shopify.', 'compare_title' => 'Side by side'])->assertRedirect('/admin/plans');
        $this->get('/pricing')->assertSee('Simple plans for every store')->assertSee('Side by side')->assertSee('Campaign calendar')->assertDontSee('Link checker');
        $this->actingAs($admin)->get('/admin/plans')->assertOk()->assertSee('Campaign calendar');
    }

    public function test_store_access_overrides_plan_modules_and_limits(): void
    {
        $admin = $this->admin();
        $store = $this->installedStore(['plan' => null]);
        $this->assertFalse($store->hasPlanAccess());

        // A complimentary Pro plan, with auto-publish switched off.
        $this->actingAs($admin)->post("/admin/stores/{$store->id}/access", [
            'plan' => 'pro', 'plan_until' => now()->addMonth()->toDateString(),
            'modules' => ['auto_publish' => 'off', 'ads_dashboard' => 'default'],
            'limits' => ['monitored_urls' => ['mode' => 'value', 'value' => 2]], 'note' => 'Launch partner',
        ])->assertRedirect();
        $store->refresh();
        $this->assertSame('pro', $store->effectivePlan());
        $this->assertFalse($store->planIncludes('auto_publish'));
        $this->assertTrue($store->planIncludes('ads_dashboard'));
        $this->assertSame(2, $store->planLimit('monitored_urls'));

        // A Free store gets one extra module without changing plan.
        $free = $this->installedStore(['shop_domain' => 'free.myshopify.com', 'plan' => 'free']);
        $this->assertFalse($free->planIncludes('ads_dashboard'));
        $this->actingAs($admin)->post("/admin/stores/{$free->id}/access", ['modules' => ['ads_dashboard' => 'on'], 'limits' => ['ad_accounts' => ['mode' => 'unlimited']]])->assertRedirect();
        $free->refresh();
        $this->assertSame('free', $free->effectivePlan());
        $this->assertTrue($free->planIncludes('ads_dashboard'));
        $this->assertNull($free->planLimit('ad_accounts'));
        $this->assertSame(30, $free->planLimit('history_days'), 'Other limits stay on the plan.');

        // Clearing everything goes back to the plan; an expired complimentary plan ends.
        $this->actingAs($admin)->post("/admin/stores/{$free->id}/access", [])->assertRedirect();
        $this->assertNull($free->fresh()->entitlements);
        $store->forceFill(['entitlements' => ['plan' => 'pro', 'plan_until' => now()->subDay()->toDateString()]])->save();
        $this->assertNull($store->fresh()->effectivePlan());
    }

    public function test_admin_support_actions_start_syncs(): void
    {
        \Illuminate\Support\Facades\Queue::fake();
        $admin = $this->admin();
        $store = $this->installedStore();
        $this->actingAs($admin)->post("/admin/stores/{$store->id}/actions/sync-orders")->assertRedirect()->assertSessionHas('status');
        $this->assertDatabaseHas('sync_runs', ['store_id' => $store->id, 'kind' => 'orders', 'trigger' => 'admin', 'status' => 'queued']);
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RunSync::class);
    }

    public function test_platform_settings_and_team_roles(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/settings', [
            'warn_at' => 90, 'test_shops' => "qa.myshopify.com\nnot a domain", 'test_shop_plan' => 'growth',
        ])->assertRedirect();
        $this->assertSame(0.9, config('shopify.billing.warn_at'));
        $this->assertSame(['qa.myshopify.com'], config('shopify.test_shops'));
        $this->assertSame('growth', $this->installedStore(['shop_domain' => 'qa.myshopify.com', 'plan' => null])->effectivePlan());

        // Team: a support agent sees stores and tickets, but not plans or access changes.
        $this->actingAs($admin)->post('/admin/team', ['name' => 'Sam', 'email' => 'sam@example.com', 'admin_role' => 'support'])->assertRedirect()->assertSessionHas('status');
        $sam = User::where('email', 'sam@example.com')->sole();
        $store = $this->installedStore(['shop_domain' => 's.myshopify.com']);
        $this->actingAs($sam)->get('/admin/stores')->assertOk();
        $this->actingAs($sam)->get('/admin/tickets')->assertOk();
        $this->actingAs($sam)->get('/admin/messages')->assertOk();
        $this->actingAs($sam)->get('/admin/plans')->assertForbidden();
        $this->actingAs($sam)->get('/admin/settings')->assertForbidden();
        $this->actingAs($sam)->get('/admin/sync')->assertForbidden();
        $this->actingAs($sam)->post("/admin/stores/{$store->id}/access", ['modules' => ['ads_dashboard' => 'on']])->assertForbidden();
        $this->assertNull($store->fresh()->entitlements);

        // Operations can change store access but not plans.
        $this->actingAs($admin)->post("/admin/team/{$sam->id}", ['admin_role' => 'operations'])->assertRedirect();
        $this->actingAs($sam->fresh())->post("/admin/stores/{$store->id}/access", ['modules' => ['ads_dashboard' => 'on']])->assertRedirect();
        $this->actingAs($sam->fresh())->get('/admin/plans')->assertOk();
        $this->actingAs($sam->fresh())->post('/admin/plans/modules', ['grid' => []])->assertForbidden();

        // Disabled team members are signed out and can't sign in.
        $this->actingAs($admin)->post("/admin/team/{$sam->id}", ['action' => 'disable'])->assertRedirect();
        $this->actingAs($sam->fresh())->get('/admin')->assertRedirect('/admin/login');
        auth()->logout();
        $this->post('/admin/login', ['email' => 'sam@example.com', 'password' => 'anything'])->assertSessionHasErrors('email');
        $this->actingAs($admin)->post("/admin/team/{$admin->id}", ['action' => 'disable'])->assertSessionHasErrors('team');
    }
}
