<?php

namespace Tests\Feature;

use App\Models\CostRule;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Refund;
use App\Models\Store;
use App\Models\Variant;
use App\Services\Profit\Breakdowns;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

/** Phase 2 profit pages: breakdowns (F13), sales trends (F32), insights (F33) and product detail. */
class ProfitPagesTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Store $store;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
        Http::fake(fn () => Http::response(['data' => []]));
        Queue::fake();
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $this->store = $this->installedStore(['plan' => 'growth', 'timezone' => 'UTC']);
    }

    private function product(int $id, string $title, float $price, ?float $cost, int $stock = 100): Variant
    {
        $product = Product::create(['store_id' => $this->store->id, 'shopify_id' => $id, 'title' => $title, 'status' => 'ACTIVE']);

        return Variant::create(['store_id' => $this->store->id, 'product_id' => $product->id, 'shopify_id' => $id * 10, 'sku' => "SKU-{$id}", 'price' => $price, 'shopify_cost' => $cost, 'inventory_quantity' => $stock]);
    }

    /** lines: [variant, quantity, unit price, line discount, unit cost, refunded quantity] */
    private function order(string $at, array $lines, array $extra = []): Order
    {
        $gross = array_sum(array_map(fn ($l) => $l[1] * $l[2], $lines));
        $discounts = array_sum(array_map(fn ($l) => $l[3] ?? 0, $lines));
        $order = Order::create($extra + [
            'store_id' => $this->store->id, 'shopify_id' => ++$this->seq, 'name' => '#'.(1000 + $this->seq), 'processed_at' => Carbon::parse($at),
            'gross_sales' => $gross, 'total_discounts' => $discounts, 'subtotal' => $gross - $discounts, 'total_price' => $gross - $discounts,
            'units' => array_sum(array_column($lines, 1)), 'source_name' => 'web',
        ]);
        foreach ($lines as $i => $l) {
            OrderLine::create([
                'store_id' => $this->store->id, 'order_id' => $order->id, 'shopify_id' => $order->shopify_id * 100 + $i,
                'product_shopify_id' => $l[0]->product->shopify_id, 'variant_shopify_id' => $l[0]->shopify_id, 'title' => $l[0]->product->title,
                'quantity' => $l[1], 'price' => $l[2], 'discount' => $l[3] ?? 0, 'unit_cost' => $l[4] ?? null, 'refunded_quantity' => $l[5] ?? 0,
            ]);
        }

        return $order;
    }

    public function test_orders_are_costed_with_a_loss_reason(): void
    {
        $serum = $this->product(1, 'Serum', 40, 12);
        $cream = $this->product(2, 'Cream', 30, 25);
        CostRule::create(['store_id' => $this->store->id, 'type' => 'shipping', 'name' => 'Flat', 'config' => ['amount' => 8, 'basis' => 'order']]);
        $this->order('2026-10-05 10:00', [[$serum, 2, 40, 0, 12]]); // 80 − 24 − 8 = 48
        $this->order('2026-10-06 10:00', [[$cream, 1, 30, 10, 25]]); // 20 − 25 − 8 = −13: discount + cost

        $rows = app(Breakdowns::class)->orders($this->store, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-10'))->keyBy('name');

        $this->assertSame(48.0, $rows['#1001']['profit']);
        $this->assertNull($rows['#1001']['reason']);
        $this->assertSame(-13.0, $rows['#1002']['profit']);
        $this->assertSame('cost', $rows['#1002']['reason'], 'Product cost took the biggest share of that order.');
    }

    public function test_profit_tabs_breakdowns_and_csv_export(): void
    {
        $owner = $this->member($this->store, 'owner');
        $serum = $this->product(1, 'Serum', 40, 12);
        $cream = $this->product(2, 'Cream', 30, null);
        $refunded = $this->order('2026-10-04 10:00', [[$serum, 2, 40, 0, 12, 1]], ['utm_source' => 'facebook', 'utm_medium' => 'cpc', 'total_refunded' => 40]);
        Refund::create(['store_id' => $this->store->id, 'order_id' => $refunded->id, 'shopify_id' => 1, 'processed_at' => Carbon::parse('2026-10-05'), 'amount' => 40]);
        $this->order('2026-10-08 10:00', [[$cream, 3, 30, 0, null]], ['source_name' => 'pos']);

        $this->page('/app/profit?range=7', $owner)->assertOk()->assertJsonPath('component', 'profit/show')
            ->assertJsonPath('props.tab', 'overview')->assertJsonPath('props.period.key', '7')
            ->assertJsonPath('props.profit.net_sales', 130)->assertJsonPath('props.tabs.orders.locked', false);

        $this->page('/app/profit/products?range=7', $owner)->assertOk()
            ->assertJsonPath('props.rows.0.title', 'Cream')->assertJsonPath('props.rows.0.missing_cost_units', 3)
            ->assertJsonPath('props.rows.1.title', 'Serum')->assertJsonPath('props.rows.1.refund_rate', 50)
            ->assertJsonPath('props.rows.1.gross_profit', 28); // 80 − 40 refunded − 12 cost for the unit kept

        $this->page('/app/profit/orders?range=7&sort=profit', $owner)->assertOk()->assertJsonPath('props.rows.total', 2)->assertJsonPath('props.rows.sort', 'profit');

        $channels = $this->page('/app/profit/channels?range=7', $owner)->assertOk()->json('props');
        $this->assertEqualsCanonicalizing(['Online Store', 'Point of Sale'], array_column($channels['channels'], 'label'));
        $this->assertEqualsCanonicalizing(['Meta Ads', 'Direct'], array_column($channels['sources'], 'label'));

        $sales = $this->page('/app/profit/sales?range=7', $owner)->assertOk()->assertJsonPath('props.interval', 'day')->json('props.trend');
        $this->assertCount(7, $sales);
        $this->assertSame(40.0, (float) collect($sales)->firstWhere('period', '2026-10-05')['refunds'], 'Refunds count on the day they happened.');

        $csv = $this->get('/app/profit/orders/export?range=7', $this->as($owner))->assertOk();
        $this->assertStringContainsString('text/csv', $csv->headers->get('Content-Type'));
        $this->assertStringContainsString('#1001', $csv->streamedContent());

        // Custom range within history.
        $this->page('/app/profit?range=custom&from=2026-10-08&to=2026-10-09', $owner)->assertJsonPath('props.period.days', 2)->assertJsonPath('props.profit.orders', 1);
    }

    public function test_free_plan_sees_the_overview_with_locked_tabs(): void
    {
        $free = $this->installedStore(['shop_domain' => 'free.myshopify.com', 'plan' => 'free', 'timezone' => 'UTC']);
        $owner = $this->member($free, 'owner');
        $headers = ['Authorization' => 'Bearer '.$this->sessionToken($owner->shopify_user_id, 'free.myshopify.com'), \App\Support\Spa\Page::HEADER => '1'];

        $this->getJson('/app/profit?range=90', $headers)->assertOk()->assertJsonPath('props.period.key', '30')->assertJsonPath('props.period.locked', true)
            ->assertJsonPath('props.tabs.products.locked', true);
        $this->getJson('/app/profit/orders', $headers)->assertOk()->assertJsonPath('props.locked', 'profit_breakdowns')->assertJsonMissingPath('props.rows');
        $this->get('/app/profit/orders/export', ['Authorization' => $headers['Authorization']])->assertStatus(402);
        $this->getJson('/app/products/insights', $headers)->assertOk()->assertJsonPath('props.locked', 'product_insights');
    }

    public function test_insights_and_product_detail(): void
    {
        $owner = $this->member($this->store, 'owner');
        $serum = $this->product(1, 'Serum', 40, 12, 30);
        $cream = $this->product(2, 'Cream', 30, 20);
        foreach ([2, 4, 6, 8] as $day) {
            $this->order("2026-10-0{$day} 10:00", [[$serum, 2, 40, 0, 12]]);
        }
        $this->order('2026-09-01 10:00', [[$cream, 2, 30, 0, 20]]);
        $this->order('2026-09-02 10:00', [[$cream, 2, 30, 0, 20]]);
        $this->order('2026-09-03 10:00', [[$cream, 2, 30, 0, 20]]);

        $lists = $this->page('/app/products/insights', $owner)->assertOk()->assertJsonPath('component', 'products/insights')->json('props.lists');
        $this->assertSame('Serum', $lists['best_sellers']['items'][0]['title']);
        $this->assertSame(8, $lists['best_sellers']['items'][0]['units']);
        $this->assertSame('Cream', $lists['falling']['items'][0]['title'], 'Sold before, nothing now.');
        $this->assertSame('Serum', $lists['advertise']['items'][0]['title']);
        $this->assertNotEmpty($lists['rising']['rule']);

        $this->page("/app/products/{$serum->product_id}", $owner)->assertOk()->assertJsonPath('component', 'products/show')
            ->assertJsonPath('props.stats.units', 8)->assertJsonPath('props.variants.0.sold_30d', 8)
            ->assertJsonPath('props.variants.0.days_of_stock_30', 112.5); // 30 ÷ (8 ÷ 30)
        $this->page('/app/products/999999', $owner)->assertNotFound();

        $this->send("/app/products/variants/{$serum->id}/cost", ['cost' => 13, 'back' => 'product'], $owner)
            ->assertJsonPath('redirect', "/app/products/{$serum->product_id}");
    }
}
