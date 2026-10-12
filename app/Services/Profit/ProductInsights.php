<?php

namespace App\Services\Profit;

use App\Models\Product;
use App\Models\Store;
use Illuminate\Support\Collection;

/**
 * Product insights (Final MVP F33): which products to push, fix or restock. Every list states its
 * ranking rule. Compares the last 30 days with the 30 before.
 */
class ProductInsights
{
    public const LISTS = [
        'best_sellers' => ['Best sellers', 'Most units sold in the last 30 days.'],
        'most_profitable' => ['Most profitable', 'Highest gross profit (net sales − product costs) in the last 30 days.'],
        'advertise' => ['Best to advertise', 'Margin at or above your store average, steady sales (3+ orders) and at least 14 days of stock.'],
        'rising' => ['Rising', 'Net sales up 25% or more vs the previous 30 days (at least 3 orders now).'],
        'falling' => ['Falling', 'Net sales down 25% or more vs the previous 30 days (at least 3 orders before).'],
        'high_refunds' => ['High refund rate', '10% or more of units refunded (at least 5 units sold).'],
        'discount_dependent' => ['Discount-dependent', '30% or more of gross sales given away as discounts (at least 3 orders).'],
    ];

    public function __construct(private readonly Breakdowns $breakdowns) {}

    /** @return array<string, array{label: string, rule: string, items: list<array>}> */
    public function lists(Store $store): array
    {
        $tz = $store->timezone ?: 'UTC';
        $now = $this->breakdowns->products($store, now($tz)->subDays(29), now($tz))->keyBy(fn ($p) => $p['product_shopify_id'] ?? $p['title']);
        $before = $this->breakdowns->products($store, now($tz)->subDays(59), now($tz)->subDays(30))->keyBy(fn ($p) => $p['product_shopify_id'] ?? $p['title']);
        $stock = $this->stock($store);

        $rows = $now->map(function ($p, $key) use ($before, $stock) {
            $prev = $before->get($key);
            $p['previous_net_sales'] = $prev['net_sales'] ?? 0.0;
            $p['change'] = $prev && $prev['net_sales'] > 0 ? ($p['net_sales'] - $prev['net_sales']) / $prev['net_sales'] * 100 : null;
            $p['days_of_stock'] = $stock[$p['product_shopify_id']] ?? null;

            return $p;
        });
        // Products that sold before but not at all now are "falling" too.
        foreach ($before as $key => $prev) {
            if (! $rows->has($key) && $prev['orders'] >= 3) {
                $rows->put($key, array_merge($prev, ['units' => 0, 'orders' => 0, 'net_sales' => 0.0, 'gross_profit' => 0.0, 'previous_net_sales' => $prev['net_sales'], 'change' => -100.0, 'margin' => null, 'refund_rate' => null, 'discount_share' => null, 'days_of_stock' => $stock[$prev['product_shopify_id']] ?? null, 'falling_only' => true]));
            }
        }
        $current = $rows->reject(fn ($p) => $p['falling_only'] ?? false);
        $avgMargin = $current->sum('net_sales') > 0 ? $current->sum('gross_profit') / $current->sum('net_sales') * 100 : null;

        $lists = [
            'best_sellers' => $current->sortByDesc('units'),
            'most_profitable' => $current->filter(fn ($p) => $p['gross_profit'] > 0 && $p['missing_cost_units'] < $p['units'])->sortByDesc('gross_profit'),
            'advertise' => $current->filter(fn ($p) => $avgMargin !== null && $p['margin'] !== null && $p['margin'] >= $avgMargin && $p['orders'] >= 3
                && $p['missing_cost_units'] === 0 && ($p['days_of_stock'] === null || $p['days_of_stock'] >= 14))->sortByDesc(fn ($p) => $p['gross_profit']),
            'rising' => $current->filter(fn ($p) => $p['orders'] >= 3 && ($p['change'] === null ? $p['previous_net_sales'] == 0 : $p['change'] >= 25))->sortByDesc(fn ($p) => $p['net_sales'] - $p['previous_net_sales']),
            'falling' => $rows->filter(fn ($p) => $p['change'] !== null && $p['change'] <= -25 && $p['previous_net_sales'] > 0)->sortBy(fn ($p) => $p['net_sales'] - $p['previous_net_sales']),
            'high_refunds' => $current->filter(fn ($p) => $p['units'] >= 5 && ($p['refund_rate'] ?? 0) >= 10)->sortByDesc('refund_rate'),
            'discount_dependent' => $current->filter(fn ($p) => $p['orders'] >= 3 && ($p['discount_share'] ?? 0) >= 30)->sortByDesc('discount_share'),
        ];

        return collect(self::LISTS)->map(fn ($meta, $key) => [
            'label' => $meta[0],
            'rule' => $meta[1],
            'items' => $lists[$key]->take(10)->values()->map(fn ($p) => $this->present($p))->all(),
        ])->all() + ['_meta' => ['average_margin' => $avgMargin === null ? null : round($avgMargin, 1)]];
    }

    private function present(array $p): array
    {
        return [
            'product_id' => $p['product_id'] ?? null,
            'title' => $p['title'],
            'image' => $p['image'] ?? null,
            'units' => $p['units'],
            'orders' => $p['orders'],
            'net_sales' => round($p['net_sales'], 2),
            'gross_profit' => round($p['gross_profit'], 2),
            'margin' => $p['margin'] === null ? null : round($p['margin'], 1),
            'change' => $p['change'] === null ? null : round($p['change'], 1),
            'refund_rate' => $p['refund_rate'] === null ? null : round($p['refund_rate'], 1),
            'discount_share' => $p['discount_share'] === null ? null : round($p['discount_share'], 1),
            'days_of_stock' => $p['days_of_stock'],
            'missing_cost' => ($p['missing_cost_units'] ?? 0) > 0,
        ];
    }

    /**
     * Days of stock per product (Shopify ID): tracked stock across its variants ÷ 30-day daily sales.
     *
     * @return array<int, float|null>
     */
    public function stock(Store $store): array
    {
        $sold = Breakdowns::unitsSold($store, 30);
        $out = [];
        Product::where('store_id', $store->id)->whereNull('deleted_at')->with(['variants' => fn ($q) => $q->whereNull('deleted_at')])
            ->each(function (Product $product) use ($sold, &$out) {
                $tracked = $product->variants->where('inventory_tracked', true);
                if ($tracked->isEmpty()) {
                    return;
                }
                $units = (float) $tracked->sum(fn ($v) => $sold[$v->shopify_id] ?? 0);
                $out[$product->shopify_id] = Breakdowns::daysOfStock((int) $tracked->sum('inventory_quantity'), $units, 30);
            });

        return $out;
    }
}
