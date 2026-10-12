<?php

namespace Tests\Feature;

use App\Jobs\RefreshResource;
use App\Jobs\RunSync;
use App\Models\CostSnapshot;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Product;
use App\Models\Store;
use App\Models\SyncRun;
use App\Models\Variant;
use App\Services\Sync\Costs;
use App\Services\Sync\SyncManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class SyncEngineTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    private Store $store;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpShopify();
        $this->store = $this->installedStore(['plan' => 'growth', 'timezone' => 'UTC']);
    }

    private function product(int $id, array $variants, array $extra = []): array
    {
        return $extra + [
            'id' => "gid://shopify/Product/{$id}", 'title' => "Product {$id}", 'handle' => "product-{$id}", 'status' => 'ACTIVE',
            'vendor' => 'Glow', 'productType' => 'Serum', 'isGiftCard' => false, 'createdAt' => '2026-01-01T00:00:00Z', 'updatedAt' => '2026-10-01T00:00:00Z',
            'featuredMedia' => ['preview' => ['image' => ['url' => 'https://cdn.shopify.com/p.jpg']]],
            'variants' => ['nodes' => $variants],
        ];
    }

    private function variant(int $id, ?string $cost, int $stock = 10, string $price = '40.00'): array
    {
        return [
            'id' => "gid://shopify/ProductVariant/{$id}", 'title' => 'Default Title', 'sku' => "SKU-{$id}", 'price' => $price, 'compareAtPrice' => null,
            'position' => 1, 'inventoryQuantity' => $stock, 'updatedAt' => '2026-10-01T00:00:00Z',
            'inventoryItem' => ['id' => "gid://shopify/InventoryItem/{$id}9", 'tracked' => true, 'requiresShipping' => true, 'unitCost' => $cost === null ? null : ['amount' => $cost]],
        ];
    }

    private function order(int $id, array $lines, array $extra = []): array
    {
        $money = fn ($v) => ['shopMoney' => ['amount' => (string) $v]];

        return $extra + [
            'id' => "gid://shopify/Order/{$id}", 'name' => "#{$id}", 'processedAt' => '2026-10-05T10:00:00Z', 'createdAt' => '2026-10-05T10:00:00Z',
            'updatedAt' => '2026-10-05T10:00:00Z', 'cancelledAt' => null, 'test' => false, 'currencyCode' => 'USD', 'presentmentCurrencyCode' => 'USD',
            'displayFinancialStatus' => 'PAID', 'displayFulfillmentStatus' => 'FULFILLED', 'sourceName' => 'web', 'paymentGatewayNames' => ['shopify_payments'],
            'subtotalPriceSet' => $money(70), 'totalDiscountsSet' => $money(10), 'totalShippingPriceSet' => $money(5), 'totalTaxSet' => $money(7),
            'totalPriceSet' => $money(82), 'totalRefundedSet' => $money(0),
            'lineItems' => ['nodes' => $lines], 'refunds' => [],
            'customer' => ['id' => 'gid://shopify/Customer/555'],
            'customerJourneySummary' => ['lastVisit' => ['landingPage' => 'https://demo.com/?utm_source=instagram', 'referrerUrl' => 'https://instagram.com', 'utmParameters' => ['source' => 'instagram', 'medium' => 'social', 'campaign' => 'oct']]],
        ];
    }

    private function line(int $id, int $variant, int $qty, string $price, string $discount = '0'): array
    {
        return [
            'id' => "gid://shopify/LineItem/{$id}", 'sku' => "SKU-{$variant}", 'name' => "Product {$variant}", 'variantTitle' => null, 'quantity' => $qty, 'isGiftCard' => false,
            'originalUnitPriceSet' => ['shopMoney' => ['amount' => $price]], 'totalDiscountSet' => ['shopMoney' => ['amount' => $discount]],
            'product' => ['id' => 'gid://shopify/Product/1'], 'variant' => ['id' => "gid://shopify/ProductVariant/{$variant}"],
        ];
    }

    public function test_initial_import_pages_through_products_then_orders_with_costs_at_order_time(): void
    {
        Http::fake(function (Request $request) {
            $q = $request['query'];
            if (str_contains($q, 'products(first')) {
                return ((array) $request['variables'])['after'] === null
                    ? Http::response(['data' => ['products' => ['nodes' => [$this->product(1, [$this->variant(11, '12.50'), $this->variant(12, null)])], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'c1']]]])
                    : Http::response(['data' => ['products' => ['nodes' => [$this->product(2, [$this->variant(21, '3.00')], ['isGiftCard' => false])], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
            }
            if (str_contains($q, 'orders(first')) {
                return Http::response(['data' => ['orders' => ['nodes' => [$this->order(1001, [$this->line(1, 11, 2, '30.00', '10.00'), $this->line(2, 12, 1, '20.00')])], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
            }

            return Http::response(['data' => []]);
        });

        app(SyncManager::class)->startInitial($this->store);

        $this->assertSame(['done', 'done'], SyncRun::orderBy('id')->pluck('status')->all());
        $this->assertSame(2, Product::count());
        $this->assertSame(3, Variant::count());
        $v11 = Variant::where('shopify_id', 11)->sole();
        $this->assertSame([12.5, 'shopify'], [$v11->effectiveCost(), $v11->costSource()]);
        $this->assertNull(Variant::where('shopify_id', 12)->sole()->effectiveCost());
        $this->assertSame(3, CostSnapshot::count(), 'A first snapshot per variant.');

        $order = Order::sole();
        $this->assertSame(['instagram', 'social', 'oct', 555, 'shopify_payments'], [$order->utm_source, $order->utm_medium, $order->utm_campaign, $order->customer_id, $order->payment_gateway]);
        $this->assertSame(['80.00', '10.00', 3], [(string) $order->gross_sales, (string) $order->total_discounts, $order->units]);
        $this->assertSame(['12.5000', null], OrderLine::orderBy('id')->pluck('unit_cost')->map(fn ($c) => $c === null ? null : (string) $c)->all());

        // A cost entered later fills the past order that had none, but never rewrites known costs.
        Costs::setAppCost(Variant::where('shopify_id', 12)->sole(), 8.0);
        Costs::setAppCost($v11->fresh(), 99.0);
        $this->assertSame(['12.5000', '8.0000'], OrderLine::orderBy('id')->pluck('unit_cost')->map(fn ($c) => (string) $c)->all());
        $this->assertSame([99.0, 'app'], [$v11->fresh()->effectiveCost(), $v11->fresh()->costSource()]);
        $this->assertSame(12.5, Costs::at($v11->fresh(), now()->subMonth()), 'Older moments keep the old cost.');
    }

    public function test_optional_order_fields_shopify_refuses_are_dropped_and_remembered(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;
            if (str_contains($request['query'], 'customer { id }')) {
                return Http::response(['errors' => [['message' => 'This app is not approved to access the Customer object.', 'extensions' => ['code' => 'ACCESS_DENIED']]]]);
            }

            return Http::response(['data' => ['orders' => ['nodes' => [$this->order(7, [], ['customer' => null])], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]]);
        });

        app(\App\Services\Sync\OrderSync::class)->page($this->store, now()->subDays(5), null);

        $this->assertSame(1, Order::count());
        $this->assertFalse($this->store->fresh()->capability('orders_customer'));
        $this->assertSame(2, $calls);
        // Next time the refused field isn't asked for at all.
        app(\App\Services\Sync\OrderSync::class)->page($this->store->fresh(), now()->subDays(5), null);
        $this->assertSame(3, $calls);
    }

    public function test_refunds_and_cancellations_are_kept(): void
    {
        $refund = ['id' => 'gid://shopify/Refund/9', 'createdAt' => '2026-10-07T00:00:00Z', 'totalRefundedSet' => ['shopMoney' => ['amount' => '30.00']],
            'refundLineItems' => ['nodes' => [['quantity' => 1, 'lineItem' => ['id' => 'gid://shopify/LineItem/1']]]]];
        Http::fake(fn () => Http::response(['data' => ['order' => $this->order(1001, [$this->line(1, 11, 2, '30.00')], ['refunds' => [$refund], 'cancelledAt' => null])]]));

        app(\App\Services\Sync\OrderSync::class)->refresh($this->store, 1001);
        app(\App\Services\Sync\OrderSync::class)->refresh($this->store, 1001);

        $this->assertSame(1, Order::count(), 'Idempotent.');
        $this->assertSame(1, OrderLine::sole()->refunded_quantity);
        $this->assertSame('30.00', (string) \App\Models\Refund::sole()->amount);
    }

    public function test_webhooks_queue_a_refresh_and_handle_compliance(): void
    {
        Queue::fake();
        $product = Product::create(['store_id' => $this->store->id, 'shopify_id' => 1, 'title' => 'P']);
        Variant::create(['store_id' => $this->store->id, 'product_id' => $product->id, 'shopify_id' => 11, 'inventory_item_id' => 119]);
        Order::create(['store_id' => $this->store->id, 'shopify_id' => 1001, 'processed_at' => now(), 'customer_id' => 555]);

        $send = function (string $topic, array $payload) {
            $body = json_encode($payload);

            return $this->call('POST', '/webhooks/shopify', [], [], [], [
                'HTTP_X-Shopify-Topic' => $topic, 'HTTP_X-Shopify-Shop-Domain' => $this->shop, 'HTTP_X-Shopify-Webhook-Id' => uniqid('w', true),
                'HTTP_X-Shopify-Hmac-Sha256' => base64_encode(hash_hmac('sha256', $body, 'test-secret', true)), 'CONTENT_TYPE' => 'application/json',
            ], $body);
        };

        $send('products/update', ['id' => 1])->assertNoContent();
        $send('orders/create', ['id' => 1002])->assertNoContent();
        $send('refunds/create', ['order_id' => 1001])->assertNoContent();
        $send('inventory_levels/update', ['inventory_item_id' => 119, 'available' => 3])->assertNoContent();
        // The stock change is for product 1 again within the dedupe window: one refresh covers both.
        Queue::assertPushed(RefreshResource::class, 3);
        Queue::assertPushed(RefreshResource::class, fn ($job) => $job->type === 'product' && $job->id === 1);

        $send('products/delete', ['id' => 1])->assertNoContent();
        $this->assertNotNull($product->fresh()->deleted_at);

        $send('customers/redact', ['customer' => ['id' => 555]])->assertNoContent();
        $this->assertNull(Order::sole()->customer_id);

        SyncRun::create(['store_id' => $this->store->id, 'kind' => 'orders', 'status' => 'running']);
        $send('app/uninstalled', [])->assertNoContent();
        $this->assertFalse($this->store->fresh()->isInstalled());
        $this->assertSame('failed', SyncRun::sole()->status);
    }

    public function test_sync_runs_resume_and_fail_safely(): void
    {
        Queue::fake();
        $manager = app(SyncManager::class);
        $run = $manager->start($this->store, 'orders', 'manual');
        $this->assertSame($run->id, $manager->start($this->store, 'orders', 'manual')->id, 'One active run per kind.');
        Queue::assertPushed(RunSync::class, 1);
        $this->assertTrue($run->since->lte(now()->subDays(59)), 'Without read_all_orders, history is Shopify\'s 60 days.');

        // Free plan: 30 days of history.
        $free = $this->installedStore(['shop_domain' => 'free.myshopify.com', 'plan' => 'free']);
        $this->assertTrue(app(\App\Services\Sync\OrderSync::class)->initialSince($free)->gte(now()->subDays(31)));
    }
}
