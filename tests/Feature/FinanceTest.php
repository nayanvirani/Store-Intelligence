<?php

namespace Tests\Feature;

use App\Models\FinanceEntry;
use App\Models\Store;
use App\Models\Subscription;
use App\Models\User;
use App\Support\Finance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use App\Support\RailwayBilling;
use Tests\TestCase;

/**
 * Finance (Internal Admin): monthly revenue from subscriptions and income, expenses from fixed
 * monthly costs, percentage fees and manual entries, and the profit.
 */
class FinanceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-15 12:00:00');
    }

    private function admin(string $role = 'super_admin'): User
    {
        return User::forceCreate(['name' => 'Ava', 'email' => $role.'@orderorbit.space', 'password' => 'secret-password-123', 'is_admin' => true, 'admin_role' => $role]);
    }

    private function subscription(string $shop, float $price, string $activated, ?string $trialEnds = null, ?string $cancelled = null, bool $test = false, string $shopifyPlan = 'Basic'): void
    {
        $store = Store::firstOrCreate(['shop_domain' => $shop], ['access_token' => 'x', 'installed_at' => $activated, 'plan' => 'growth', 'shopify_plan' => $shopifyPlan]);
        Subscription::create(['store_id' => $store->id, 'plan' => 'growth', 'shopify_subscription_id' => 'gid://shopify/AppSubscription/'.uniqid(), 'status' => $cancelled ? 'CANCELLED' : 'ACTIVE',
            'price' => $price, 'test' => $test, 'activated_at' => $activated, 'trial_ends_at' => $trialEnds, 'cancelled_at' => $cancelled]);
    }

    public function test_monthly_revenue_expenses_and_profit(): void
    {
        $this->subscription('a.myshopify.com', 39.99, '2026-08-01');                              // billed Aug, Sep, Oct
        $this->subscription('b.myshopify.com', 14.99, '2026-09-20', trialEnds: '2026-10-04');     // trial in Sep: billed from Oct
        $this->subscription('c.myshopify.com', 79.99, '2026-07-01', cancelled: '2026-09-10');     // billed Jul–Sep
        $this->subscription('d.myshopify.com', 39.99, '2026-10-01', trialEnds: '2026-10-15', cancelled: '2026-10-10'); // cancelled in trial: never
        $this->subscription('e.myshopify.com', 39.99, '2026-08-01', test: true);                  // test charge: never
        config(['shopify.test_shops' => ['f.myshopify.com']]);
        $this->subscription('f.myshopify.com', 39.99, '2026-08-01');                              // test store: never
        // A development store switching plans (like vantora-plus): Shopify never charges it.
        $this->subscription('dev.myshopify.com', 79.99, '2026-10-01', cancelled: '2026-10-04', shopifyPlan: 'Shopify Plus App Development');
        $this->subscription('dev.myshopify.com', 39.99, '2026-10-04', shopifyPlan: 'Shopify Plus App Development');
        // A real store switching plans in October counts once, at its last plan.
        $this->subscription('g.myshopify.com', 14.99, '2026-08-01', cancelled: '2026-10-06');
        $this->subscription('g.myshopify.com', 79.99, '2026-10-06');

        Finance::save([['name' => 'Railway', 'amount' => 5, 'from' => '2026-09', 'until' => null], ['name' => 'Old tool', 'amount' => 10, 'from' => '2026-01', 'until' => '2026-09']], [['name' => 'Shopify processing fee', 'percent' => 2.9]]);
        FinanceEntry::create(['kind' => 'expense', 'date' => '2026-10-03', 'description' => 'Domain renewal', 'amount' => 12.50]);
        FinanceEntry::create(['kind' => 'income', 'date' => '2026-10-20', 'description' => 'Custom setup', 'amount' => 100]);
        FinanceEntry::create(['kind' => 'expense', 'date' => '2026-09-30', 'description' => 'September ad', 'amount' => 20]);

        $oct = Finance::month('2026-10');
        $this->assertSame(3, $oct['subscribers']);           // a, b and g
        $this->assertSame(134.97, $oct['subscriptions']);    // 39.99 + 14.99 + 79.99
        $this->assertSame(234.97, $oct['revenue']);
        $this->assertSame(['Railway'], array_column($oct['recurring'], 'name'), 'Not connected to Railway: a typed-in Railway cost counts.');
        $this->assertSame(3.91, $oct['fees'][0]['amount']); // 2.9% of 134.97
        $this->assertSame(12.5, $oct['manual']);
        $this->assertSame(21.41, $oct['expenses']);          // 5 + 3.91 + 12.50
        $this->assertSame(213.56, $oct['profit']);

        $sep = Finance::month('2026-09');
        $this->assertSame(134.97, $sep['subscriptions']);    // a + c + g
        $this->assertSame(['Railway', 'Old tool'], array_column($sep['recurring'], 'name'));
        $this->assertSame(round(5 + 10 + 3.91 + 20, 2), $sep['expenses']);

        $this->assertSame(0.0, Finance::month('2026-11')['subscriptions'], 'A month that hasn\'t started has no revenue yet.');
        $this->assertCount(12, Finance::months(12));
    }

    public function test_super_admins_manage_entries_costs_and_fees(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/finance')->assertOk()->assertSee('Finance')->assertSee('Shopify processing fee')->assertSee('Connect Railway');

        $this->actingAs($admin)->post('/admin/finance/entries', ['kind' => 'expense', 'date' => '2026-10-05', 'description' => 'Logo design', 'category' => 'Contractors', 'amount' => '80'])
            ->assertRedirect('/admin/finance?month=2026-10');
        $entry = FinanceEntry::firstOrFail();
        $this->actingAs($admin)->get('/admin/finance?month=2026-10')->assertSee('Logo design')->assertSee('$80.00');

        $this->actingAs($admin)->post("/admin/finance/entries/{$entry->id}", ['kind' => 'expense', 'date' => '2026-10-05', 'description' => 'Logo design (final)', 'amount' => '95'])->assertRedirect();
        $this->assertSame('95.00', $entry->fresh()->amount);
        $this->actingAs($admin)->post('/admin/finance/entries', ['kind' => 'expense', 'date' => 'nope', 'description' => '', 'amount' => '-1'])->assertSessionHasErrors(['date', 'description', 'amount']);
        $this->actingAs($admin)->post("/admin/finance/entries/{$entry->id}/delete")->assertRedirect();
        $this->assertSame(0, FinanceEntry::count());

        $this->actingAs($admin)->post('/admin/finance/settings', [
            'recurring' => [['name' => 'Domain', 'amount' => '20', 'from' => '2026-10', 'until' => ''], ['name' => '', 'amount' => '9']],
            'fees' => [['name' => 'Shopify processing fee', 'percent' => '2.9'], ['name' => 'Currency conversion', 'percent' => '1.5']],
        ])->assertRedirect();
        $this->assertSame([['name' => 'Domain', 'amount' => 20.0, 'from' => '2026-10', 'until' => null]], Finance::settings()['recurring']);
        $this->assertCount(2, Finance::settings()['fees']);

        // Money is for Super Admins only.
        $this->actingAs($this->admin('operations'))->get('/admin/finance')->assertForbidden();
    }

    public function test_railway_counts_this_projects_share_of_the_workspace_bill(): void
    {
        $p1 = '4f701e1c-e495-4fef-9f3c-56ada663b3ae';
        $p2 = 'aa408320-51db-4e24-b35b-98d3fe219033';
        config(['services.railway.project_id' => $p1]);
        // Usage weights: CPU minutes (x 20/43200 $) — the project uses 1 part, the workspace 4 parts (25%).
        $usage = fn ($minutes) => [['measurement' => 'CPU_USAGE', 'value' => $minutes]];
        Http::fake(function ($request) use ($p1, $p2, $usage) {
            $q = $request['query'];
            if (str_contains($q, 'project(id:')) {
                return Http::response(['data' => ['project' => ['id' => $p1, 'name' => 'Growvia', 'workspaceId' => 'w1']]]);
            }
            if (str_contains($q, 'workspace(workspaceId:')) {
                return Http::response(['data' => ['workspace' => [
                    'id' => 'w1', 'name' => 'My Projects', 'plan' => 'HOBBY', 'projects' => ['edges' => [['node' => ['id' => $p1, 'name' => 'Growvia']], ['node' => ['id' => $p2, 'name' => 'SpeedPilot']]]],
                    'customer' => ['currentUsage' => 6.93, 'creditBalance' => 0, 'billingPeriod' => ['start' => '2026-09-19T03:03:04.000Z', 'end' => '2026-10-19T03:03:04.000Z'], 'invoices' => [
                        ['invoiceId' => 'in_3', 'periodStart' => '2026-09-19T03:03:04.000Z', 'periodEnd' => '2026-09-19T03:03:04.000Z', 'total' => 590, 'amountPaid' => 90, 'amountDue' => 90, 'status' => 'paid', 'hostedURL' => 'https://invoice.stripe.com/x'],
                        ['invoiceId' => 'in_1', 'periodStart' => '2026-07-31T12:15:38.000Z', 'periodEnd' => '2026-07-31T12:15:38.000Z', 'total' => 500, 'amountPaid' => 500, 'amountDue' => 500, 'status' => 'paid', 'hostedURL' => null],
                        ['invoiceId' => 'in_0', 'periodStart' => '2026-07-01T00:00:00.000Z', 'periodEnd' => '2026-07-01T00:00:00.000Z', 'total' => 999, 'amountPaid' => 0, 'amountDue' => 999, 'status' => 'void', 'hostedURL' => null],
                    ]],
                ]]]);
            }
            if (str_contains($q, 'usage(')) {
                // Running period and the Sep 19 invoice: 25%; before the project existed (Jul 31 invoice): 0%.
                return Http::response(['data' => [
                    'w_now' => $usage(400), 'p_now' => $usage(100),
                    'w_i0' => $usage(400), 'p_i0' => $usage(100),
                    'w_i1' => $usage(400), 'p_i1' => [],
                ]]);
            }

            return Http::response(['errors' => [['message' => 'Not Authorized']]]);
        });
        $admin = $this->admin();
        $this->actingAs($admin)->post('/admin/finance/railway', ['token' => 'secret-token'])->assertRedirect()->assertSessionHas('status', 'Railway connected: costs of the Growvia project.');
        $this->assertStringNotContainsString('secret-token', (string) \Illuminate\Support\Facades\DB::table('platform_settings')->where('key', 'railway')->value('value'), 'The token is stored encrypted.');
        Finance::save([['name' => 'Railway (project base plan)', 'amount' => 5, 'from' => '2026-01', 'until' => null]], []);

        // Only the project's share of what was charged: 25% of $0.90 in September, nothing in July.
        $this->assertSame(0.0, Finance::month('2026-07')['railway']['amount']);
        $this->assertSame(0.23, Finance::month('2026-09')['railway']['amount']);
        // The running period: 25% of the estimated bill (Hobby's $5 minimum or the usage, if higher).
        $oct = Finance::month('2026-10');
        $this->assertSame(['bill' => 6.93, 'amount' => 1.73, 'share' => 0.25, 'usage' => 6.93, 'minimum' => 5.0, 'due' => '2026-10-19'], $oct['railway']['estimate']);
        $this->assertSame(1.73, $oct['expenses']);
        $this->assertSame(['Railway (project base plan)'], array_column($oct['skipped'], 'name'), 'A typed-in Railway cost is not counted twice.');

        $this->actingAs($admin)->get('/admin/finance')->assertOk()->assertSee('Growvia')->assertSee('25%')->assertSee('Estimate')->assertSee('SpeedPilot');
        $this->actingAs($admin)->get('/admin/finance?month=2026-09')->assertOk()->assertSee('25% of the Sep 19 invoice', false)->assertSee('$0.23');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Bearer secret-token'));

        $this->actingAs($admin)->post('/admin/finance/railway', ['disconnect' => '1'])->assertRedirect();
        $this->assertFalse(RailwayBilling::connected());
    }
}
