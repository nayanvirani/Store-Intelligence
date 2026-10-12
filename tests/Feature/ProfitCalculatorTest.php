<?php

namespace Tests\Feature;

use App\Models\CostRule;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Refund;
use App\Models\Store;
use App\Services\Profit\ProfitCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class ProfitCalculatorTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Store $store;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
        $this->travelTo(Carbon::parse('2026-10-10 12:00:00', 'UTC'));
        $this->store = $this->installedStore(['timezone' => 'UTC']);
    }

    /** An order: lines are [quantity, unit price, line discount, unit cost|null, refunded quantity, gift card]. */
    private function order(string $at, array $lines, array $extra = []): Order
    {
        $gross = array_sum(array_map(fn ($l) => ($l[5] ?? false) ? 0 : $l[0] * $l[1], $lines));
        $discounts = array_sum(array_map(fn ($l) => $l[2], $lines));
        $order = Order::create($extra + [
            'store_id' => $this->store->id, 'shopify_id' => ++$this->seq, 'processed_at' => Carbon::parse($at),
            'gross_sales' => $gross, 'total_discounts' => $discounts, 'subtotal' => $gross - $discounts, 'total_price' => $gross - $discounts,
            'units' => array_sum(array_map(fn ($l) => ($l[5] ?? false) ? 0 : $l[0], $lines)), 'payment_gateway' => 'shopify_payments',
        ]);
        foreach ($lines as $i => $l) {
            OrderLine::create([
                'store_id' => $this->store->id, 'order_id' => $order->id, 'shopify_id' => $order->shopify_id * 100 + $i, 'quantity' => $l[0], 'price' => $l[1],
                'discount' => $l[2], 'unit_cost' => $l[3], 'refunded_quantity' => $l[4] ?? 0, 'is_gift_card' => $l[5] ?? false,
            ]);
        }

        return $order;
    }

    private function calc(string $from = '2026-10-01', string $to = '2026-10-10'): array
    {
        return app(ProfitCalculator::class)->calculate($this->store->fresh(), Carbon::parse($from), Carbon::parse($to));
    }

    public function test_the_waterfall_with_costs_fees_shipping_and_ad_spend(): void
    {
        $this->order('2026-10-02 09:00', [[2, 50, 10, 20], [1, 30, 0, 10]]); // gross 130, discounts 10, cogs 50
        $this->order('2026-10-05 15:00', [[1, 100, 0, 40]]); // gross 100, cogs 40
        CostRule::create(['store_id' => $this->store->id, 'type' => 'payment_fee', 'name' => 'Shopify Payments', 'config' => ['percent' => 2.9, 'fixed' => 0.30, 'gateway' => 'shopify_payments']]);
        CostRule::create(['store_id' => $this->store->id, 'type' => 'shipping', 'name' => 'Flat', 'config' => ['amount' => 5, 'basis' => 'order']]);
        CostRule::create(['store_id' => $this->store->id, 'type' => 'handling', 'name' => 'Box', 'config' => ['amount' => 1]]);
        CostRule::create(['store_id' => $this->store->id, 'type' => 'ad_spend', 'name' => 'Influencers', 'config' => ['amount' => 310]]); // $10/day in October

        $p = $this->calc();

        $this->assertSame([2, 230.0, 10.0, 0.0, 220.0, 90.0, 130.0], [$p['orders'], $p['gross_sales'], $p['discounts'], $p['returns'], $p['net_sales'], $p['cogs'], $p['gross_profit']]);
        // Fees: 2.9% of 120 + 0.30, and 2.9% of 100 + 0.30.
        $this->assertSame(round(120 * 0.029 + 0.3 + 100 * 0.029 + 0.3, 2), $p['payment_fees']);
        $this->assertSame([10.0, 2.0, 100.0], [$p['shipping_costs'], $p['handling_costs'], $p['ad_spend']]);
        $this->assertSame(round(130 - $p['payment_fees'] - 12, 2), $p['contribution_profit']);
        $this->assertSame(round($p['contribution_profit'] - 100, 2), $p['profit_after_ads']);
        $this->assertNull($p['net_profit'], 'No fixed costs entered: no "net" figure.');
        $this->assertSame(110.0, $p['aov']);
        $this->assertSame(['units_with_cost' => 4, 'units' => 4, 'percent' => 100.0], $p['coverage']);
        $this->assertCount(10, $p['daily']);
        $this->assertSame(1, $p['daily'][1]['orders']);
    }

    public function test_refunds_cancellations_test_orders_and_gift_cards(): void
    {
        $refunded = $this->order('2026-09-25 10:00', [[2, 40, 0, 15, 1]]); // before the range
        Refund::create(['store_id' => $this->store->id, 'order_id' => $refunded->id, 'shopify_id' => 1, 'processed_at' => Carbon::parse('2026-10-03'), 'amount' => 40]);
        $this->order('2026-10-04 10:00', [[1, 60, 0, 20]], ['cancelled_at' => now()]);
        $this->order('2026-10-04 11:00', [[1, 60, 0, 20]], ['test' => true]);
        $this->order('2026-10-06 10:00', [[1, 50, 0, 20], [1, 25, 0, null, 0, true]]); // gift card line

        $p = $this->calc();

        $this->assertSame(1, $p['orders'], 'Cancelled and test orders are not counted.');
        $this->assertSame([50.0, 40.0, 10.0, 20.0], [$p['gross_sales'], $p['returns'], $p['net_sales'], $p['cogs']], 'The refund counts on the day it happened; the gift card is not sales.');
        $this->assertSame(1, $p['coverage']['units']);

        $this->store->forceFill(['settings' => ['exclude_test_orders' => false]])->save();
        $this->assertSame(2, $this->calc()['orders']);

        // A partial refund keeps the cost of the kept unit only.
        $this->assertSame(15.0, $this->calc('2026-09-20', '2026-09-30')['cogs']);
    }

    public function test_missing_costs_lower_coverage_or_use_the_labelled_default_margin(): void
    {
        $this->order('2026-10-02 09:00', [[1, 100, 0, 40], [3, 20, 0, null]]);

        $p = $this->calc();
        $this->assertSame([40.0, 0.0], [$p['cogs'], $p['assumed_cogs']]);
        $this->assertSame(['units_with_cost' => 1, 'units' => 4, 'percent' => 25.0], $p['coverage']);

        $this->store->forceFill(['settings' => ['default_margin' => 60]])->save();
        $p = $this->calc();
        $this->assertSame([40.0 + 24.0, 24.0], [$p['cogs'], $p['assumed_cogs']], '3 × $20 at a 60% margin costs $24, labelled as assumed.');
        $this->assertTrue($p['configured']['default_margin']);
    }

    public function test_zero_revenue_and_fixed_costs(): void
    {
        $p = $this->calc();
        $this->assertSame([0, 0.0, null, null, null], [$p['orders'], $p['net_sales'], $p['margin'], $p['aov'], $p['coverage']['percent']]);

        CostRule::create(['store_id' => $this->store->id, 'type' => 'fixed_cost', 'name' => 'Apps', 'config' => ['amount' => 310]]);
        $p = $this->calc();
        $this->assertSame([100.0, -100.0], [$p['fixed_costs'], $p['net_profit']]);
    }

    public function test_the_store_timezone_decides_the_day(): void
    {
        $this->store->forceFill(['timezone' => 'Asia/Kolkata'])->save();
        $this->order('2026-10-01 20:00:00', [[1, 10, 0, 1]]); // 01:30 on Oct 2 in India
        $p = $this->calc();
        $this->assertSame(['2026-10-02', 1], [$p['daily'][1]['date'], $p['daily'][1]['orders']]);
    }
}
