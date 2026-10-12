<?php

namespace App\Services\Sync;

use App\Models\Order;
use App\Models\OrderLine;
use App\Models\Refund;
use App\Models\Store;
use App\Models\Variant;
use App\Services\Shopify\AccessDeniedException;
use App\Services\Shopify\AdminApi;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Orders, line items and refunds from Shopify (Final MVP F7). Amounts are kept in the shop's currency.
 * Only the Shopify customer ID is kept — never names, emails or addresses. Each line stores its unit cost
 * at the time of the order (cost snapshots), so profit history is stable.
 *
 * Optional fields (customer ID, visit source) need extra Shopify approval on some stores. When Shopify
 * refuses one, the sync carries on without it and remembers that in the store's capabilities.
 */
class OrderSync
{
    public const PAGE_SIZE = 40;

    /** Shopify lets apps read the last 60 days of orders without read_all_orders. */
    public const DEFAULT_HISTORY_DAYS = 60;

    private const OPTIONAL = [
        'customer' => 'customer { id }',
        'journey' => 'customerJourneySummary { lastVisit { landingPage referrerUrl utmParameters { source medium campaign } } firstVisit { landingPage referrerUrl utmParameters { source medium campaign } } }',
    ];

    public function __construct(private readonly AdminApi $api) {}

    /** How far back the first import goes: the plan's history, limited to what Shopify allows. */
    public function initialSince(Store $store): Carbon
    {
        $allowed = $store->hasScope('read_all_orders') ? now()->subYears(3) : now()->subDays(self::DEFAULT_HISTORY_DAYS);
        $plan = $store->hasPlanAccess() ? $store->historyStart() : null;

        return ($plan !== null && $plan->gt($allowed) ? $plan : $allowed)->startOfDay();
    }

    /**
     * One page of orders changed since $since.
     *
     * @return array{count: int, cursor: ?string}
     */
    public function page(Store $store, Carbon $since, ?string $cursor): array
    {
        $data = $this->query($store, fn (string $fields) => ['query ($first: Int!, $after: String, $q: String) {
            orders(first: $first, after: $after, query: $q, sortKey: UPDATED_AT) {
              nodes { '.$fields.' }
              pageInfo { hasNextPage endCursor }
            }
        }', ['first' => self::PAGE_SIZE, 'after' => $cursor, 'q' => 'updated_at:>='.$since->copy()->utc()->format('Y-m-d\TH:i:s\Z')]]);

        $count = 0;
        foreach ($data['orders']['nodes'] ?? [] as $node) {
            $this->upsert($store, $node);
            $count++;
        }
        $info = $data['orders']['pageInfo'] ?? [];

        return ['count' => $count, 'cursor' => ($info['hasNextPage'] ?? false) ? $info['endCursor'] : null];
    }

    /** Refreshes one order (webhooks). */
    public function refresh(Store $store, int $orderId): ?Order
    {
        $data = $this->query($store, fn (string $fields) => ['query ($id: ID!) { order(id: $id) { '.$fields.' } }', ['id' => Gid::make('Order', $orderId)]]);

        return empty($data['order']) ? null : $this->upsert($store, $data['order']);
    }

    /** Runs a query with the optional fields this store allows, dropping any Shopify refuses. */
    private function query(Store $store, callable $build): array
    {
        $optional = array_filter(self::OPTIONAL, fn ($key) => $store->capability('orders_'.$key) !== false, ARRAY_FILTER_USE_KEY);

        while (true) {
            [$query, $variables] = $build($this->fields($optional));
            try {
                return $this->api->graphql($store, $query, $variables);
            } catch (AccessDeniedException $e) {
                $denied = collect(array_keys($optional))->first(fn ($key) => stripos($e->getMessage(), $key) !== false) ?? array_key_last($optional);
                if ($denied === null) {
                    throw $e;
                }
                unset($optional[$denied]);
                $store->forceFill(['capabilities' => array_merge($store->capabilities ?? [], ['orders_'.$denied => false])])->save();
            }
        }
    }

    private function fields(array $optional): string
    {
        return 'id name processedAt createdAt updatedAt cancelledAt test currencyCode presentmentCurrencyCode
            displayFinancialStatus displayFulfillmentStatus sourceName paymentGatewayNames
            subtotalPriceSet { shopMoney { amount } }
            totalDiscountsSet { shopMoney { amount } }
            totalShippingPriceSet { shopMoney { amount } }
            totalTaxSet { shopMoney { amount } }
            totalPriceSet { shopMoney { amount } }
            totalRefundedSet { shopMoney { amount } }
            lineItems(first: 100) {
              nodes {
                id sku name variantTitle quantity isGiftCard
                originalUnitPriceSet { shopMoney { amount } }
                totalDiscountSet { shopMoney { amount } }
                product { id } variant { id }
              }
            }
            refunds(first: 50) {
              id createdAt
              totalRefundedSet { shopMoney { amount } }
              refundLineItems(first: 100) { nodes { quantity lineItem { id } } }
            }
            '.implode("\n", $optional);
    }

    public function upsert(Store $store, array $node): Order
    {
        $money = fn (?array $set) => round((float) ($set['shopMoney']['amount'] ?? 0), 2);
        $visit = $node['customerJourneySummary']['lastVisit'] ?? $node['customerJourneySummary']['firstVisit'] ?? null;
        $utm = $visit['utmParameters'] ?? [];
        $lines = $node['lineItems']['nodes'] ?? [];

        return DB::transaction(function () use ($store, $node, $money, $visit, $utm, $lines) {
            $order = Order::updateOrCreate(
                ['store_id' => $store->id, 'shopify_id' => Gid::id($node['id'])],
                [
                    'name' => $node['name'] ?? null,
                    'processed_at' => $node['processedAt'] ?? $node['createdAt'],
                    'cancelled_at' => $node['cancelledAt'] ?? null,
                    'test' => (bool) ($node['test'] ?? false),
                    'currency' => $node['currencyCode'] ?? $store->currency,
                    'presentment_currency' => $node['presentmentCurrencyCode'] ?? null,
                    'financial_status' => $node['displayFinancialStatus'] ?? null,
                    'fulfillment_status' => $node['displayFulfillmentStatus'] ?? null,
                    'source_name' => isset($node['sourceName']) ? mb_substr($node['sourceName'], 0, 60) : null,
                    'payment_gateway' => isset($node['paymentGatewayNames'][0]) ? mb_substr($node['paymentGatewayNames'][0], 0, 60) : null,
                    'landing_site' => $visit['landingPage'] ?? null,
                    'referring_site' => $visit['referrerUrl'] ?? null,
                    'utm_source' => isset($utm['source']) ? mb_substr($utm['source'], 0, 120) : null,
                    'utm_medium' => isset($utm['medium']) ? mb_substr($utm['medium'], 0, 120) : null,
                    'utm_campaign' => isset($utm['campaign']) ? mb_substr($utm['campaign'], 0, 190) : null,
                    'customer_id' => isset($node['customer']['id']) ? Gid::id($node['customer']['id']) : null,
                    // Merchandise only: gift cards are a liability, not sales.
                    'gross_sales' => round(collect($lines)->reject(fn ($l) => $l['isGiftCard'] ?? false)->sum(fn ($l) => (float) ($l['originalUnitPriceSet']['shopMoney']['amount'] ?? 0) * (int) $l['quantity']), 2),
                    'total_discounts' => $money($node['totalDiscountsSet'] ?? null),
                    'subtotal' => $money($node['subtotalPriceSet'] ?? null),
                    'total_shipping' => $money($node['totalShippingPriceSet'] ?? null),
                    'total_tax' => $money($node['totalTaxSet'] ?? null),
                    'total_price' => $money($node['totalPriceSet'] ?? null),
                    'total_refunded' => $money($node['totalRefundedSet'] ?? null),
                    'units' => (int) collect($lines)->reject(fn ($l) => $l['isGiftCard'] ?? false)->sum('quantity'),
                    'shopify_updated_at' => $node['updatedAt'] ?? null,
                ],
            );

            $refunded = [];
            foreach ($node['refunds'] ?? [] as $r) {
                foreach ($r['refundLineItems']['nodes'] ?? [] as $rl) {
                    $id = Gid::id($rl['lineItem']['id'] ?? null);
                    $refunded[$id] = ($refunded[$id] ?? 0) + (int) $rl['quantity'];
                }
                Refund::updateOrCreate(
                    ['store_id' => $store->id, 'shopify_id' => Gid::id($r['id'])],
                    ['order_id' => $order->id, 'processed_at' => $r['createdAt'], 'amount' => $money($r['totalRefundedSet'] ?? null)],
                );
            }

            $variants = Variant::where('store_id', $store->id)
                ->whereIn('shopify_id', collect($lines)->map(fn ($l) => Gid::id($l['variant']['id'] ?? null))->filter()->all())
                ->get()->keyBy('shopify_id');
            $kept = [];
            foreach ($lines as $l) {
                $lineId = Gid::id($l['id']);
                $variantId = Gid::id($l['variant']['id'] ?? null);
                $line = OrderLine::firstOrNew(['order_id' => $order->id, 'shopify_id' => $lineId]);
                $line->fill([
                    'store_id' => $store->id,
                    'product_shopify_id' => Gid::id($l['product']['id'] ?? null),
                    'variant_shopify_id' => $variantId,
                    'sku' => ($l['sku'] ?? '') !== '' ? $l['sku'] : null,
                    'title' => isset($l['name']) ? mb_substr($l['name'], 0, 255) : null,
                    'variant_title' => isset($l['variantTitle']) ? mb_substr($l['variantTitle'], 0, 255) : null,
                    'quantity' => (int) $l['quantity'],
                    'refunded_quantity' => min((int) $l['quantity'], $refunded[$lineId] ?? 0),
                    'price' => round((float) ($l['originalUnitPriceSet']['shopMoney']['amount'] ?? 0), 2),
                    'discount' => round((float) ($l['totalDiscountSet']['shopMoney']['amount'] ?? 0), 2),
                    'is_gift_card' => (bool) ($l['isGiftCard'] ?? false),
                ]);
                // The cost valid when the order was placed; kept once known.
                if ($line->unit_cost === null && ($variant = $variants[$variantId] ?? null)) {
                    $line->unit_cost = Costs::at($variant, $order->processed_at);
                }
                $line->save();
                $kept[] = $line->id;
            }
            $order->lines()->whereNotIn('id', $kept ?: [0])->delete();

            return $order;
        });
    }
}
