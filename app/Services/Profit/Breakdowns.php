<?php

namespace App\Services\Profit;

use App\Models\CostRule;
use App\Models\Order;
use App\Models\Product;
use App\Models\Store;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Profit breakdowns (Final MVP F13) and sales trends (F32). Each order is costed the same way as the
 * dashboard waterfall (ProfitCalculator): cost at order time, the merchant's fee, shipping and handling
 * rules. Per-order and per-product figures use the order's own refunds, so a row explains itself.
 */
class Breakdowns
{
    public function __construct(private readonly ProfitCalculator $calc) {}

    /**
     * Every counted order in the period with its costs and profit (before ad spend, which isn't per order).
     *
     * @return Collection<int, array>
     */
    public function orders(Store $store, Carbon $from, Carbon $to): Collection
    {
        [$from, $to] = $this->bounds($store, $from, $to);
        $rules = CostRule::where('store_id', $store->id)->where('active', true)->get()->groupBy('type');
        $margin = $store->setting('default_margin');
        $rows = collect();

        Order::counted($store)->whereBetween('processed_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->with('lines')->chunkById(500, function (Collection $orders) use (&$rows, $rules, $margin, $store) {
                foreach ($orders as $order) {
                    $rows->push($this->costOrder($store, $order, $rules, $margin));
                }
            });

        return $rows;
    }

    /** One order costed line by line. */
    public function costOrder(Store $store, Order $order, Collection $rules, $margin): array
    {
        $cogs = 0.0;
        $missing = 0;
        $lines = [];
        foreach ($order->lines as $line) {
            if ($line->is_gift_card) {
                continue;
            }
            $kept = max(0, $line->quantity - $line->refunded_quantity);
            $unitCost = $line->unit_cost !== null ? (float) $line->unit_cost : null;
            $assumed = false;
            if ($unitCost === null && $margin !== null && $line->quantity > 0) {
                $unitCost = max(0, ((float) $line->price - (float) $line->discount / $line->quantity) * (1 - (float) $margin / 100));
                $assumed = true;
            }
            if ($line->unit_cost === null) {
                $missing += $line->quantity;
            }
            $cogs += ($unitCost ?? 0) * $kept;
            $lines[] = ['line' => $line, 'kept' => $kept, 'unit_cost' => $unitCost, 'assumed' => $assumed];
        }

        $gross = (float) $order->gross_sales;
        $discounts = (float) $order->total_discounts;
        $refunds = min((float) $order->total_refunded, max(0, $gross - $discounts));
        $net = $gross - $discounts - $refunds;
        $fees = $this->calc->paymentFee($order, $rules->get('payment_fee', collect()));
        $shipping = $this->calc->shippingCost($order, $rules->get('shipping', collect()));
        $handling = $order->units > 0 ? (float) $rules->get('handling', collect())->sum(fn ($r) => (float) ($r->config['amount'] ?? 0)) : 0.0;
        $profit = $net - $cogs - $fees - $shipping - $handling;

        return [
            'id' => $order->id,
            'shopify_id' => $order->shopify_id,
            'name' => $order->name,
            'processed_at' => $order->processed_at,
            'source' => $order->source_name,
            'utm_source' => $order->utm_source,
            'utm_medium' => $order->utm_medium,
            'referring_site' => $order->referring_site,
            'units' => (int) $order->units,
            'gross_sales' => $gross,
            'discounts' => $discounts,
            'refunds' => $refunds,
            'net_sales' => $net,
            'cogs' => $cogs,
            'fees' => $fees,
            'shipping' => $shipping,
            'handling' => $handling,
            'profit' => $profit,
            'margin' => $net > 0 ? $profit / $net * 100 : null,
            'missing_cost_units' => $missing,
            'reason' => $profit < 0 ? $this->lossReason($gross, $discounts, $refunds, $cogs, $shipping + $handling, $missing) : null,
            'lines' => $lines,
        ];
    }

    /** Why an order lost money: the cost that took the biggest share of its gross sales. */
    private function lossReason(float $gross, float $discounts, float $refunds, float $cogs, float $shipping, int $missing): string
    {
        $base = max($gross, 0.01);
        $shares = ['refund' => $refunds / $base, 'discount' => $discounts / $base, 'shipping' => $shipping / $base, 'cost' => $cogs / $base];
        arsort($shares);

        return array_key_first($shares);
    }

    /**
     * Products ranked by profit: units, net sales, product costs, gross profit, margin, refunds and
     * how much of their sales were discounted.
     *
     * @return Collection<int, array>
     */
    public function products(Store $store, Carbon $from, Carbon $to, ?Collection $orders = null): Collection
    {
        $orders ??= $this->orders($store, $from, $to);
        $by = [];
        foreach ($orders as $o) {
            foreach ($o['lines'] as $l) {
                $line = $l['line'];
                $key = $line->product_shopify_id ?: 'sku:'.($line->sku ?: $line->title);
                $row = $by[$key] ?? ['product_shopify_id' => $line->product_shopify_id, 'title' => $line->title, 'units' => 0, 'refunded_units' => 0,
                    'gross_sales' => 0.0, 'discounts' => 0.0, 'refunds' => 0.0, 'cogs' => 0.0, 'missing_cost_units' => 0, 'orders' => 0, 'order_ids' => []];
                $unitNet = $line->quantity > 0 ? (float) $line->price - (float) $line->discount / $line->quantity : 0;
                $row['units'] += $line->quantity;
                $row['refunded_units'] += $line->refunded_quantity;
                $row['gross_sales'] += (float) $line->price * $line->quantity;
                $row['discounts'] += (float) $line->discount;
                $row['refunds'] += $unitNet * $line->refunded_quantity;
                $row['cogs'] += ($l['unit_cost'] ?? 0) * $l['kept'];
                $row['missing_cost_units'] += $line->unit_cost === null ? $line->quantity : 0;
                $row['order_ids'][$o['id']] = true;
                $by[$key] = $row;
            }
        }

        $products = Product::where('store_id', $store->id)->whereIn('shopify_id', array_filter(array_column($by, 'product_shopify_id')))
            ->get()->keyBy('shopify_id');

        return collect($by)->map(function ($r) use ($products) {
            $net = $r['gross_sales'] - $r['discounts'] - $r['refunds'];
            $gp = $net - $r['cogs'];
            $p = $r['product_shopify_id'] ? $products->get($r['product_shopify_id']) : null;

            return [
                'product_id' => $p?->id,
                'product_shopify_id' => $r['product_shopify_id'],
                'title' => $p?->title ?? $r['title'],
                'image' => $p?->image_url,
                'units' => $r['units'],
                'orders' => count($r['order_ids']),
                'gross_sales' => $r['gross_sales'],
                'discounts' => $r['discounts'],
                'refunds' => $r['refunds'],
                'net_sales' => $net,
                'cogs' => $r['cogs'],
                'gross_profit' => $gp,
                'margin' => $net > 0 ? $gp / $net * 100 : null,
                'refund_rate' => $r['units'] > 0 ? $r['refunded_units'] / $r['units'] * 100 : null,
                'discount_share' => $r['gross_sales'] > 0 ? $r['discounts'] / $r['gross_sales'] * 100 : null,
                'missing_cost_units' => $r['missing_cost_units'],
                'reason' => $gp < 0 ? ($r['missing_cost_units'] > 0 ? 'cost' : ($r['discounts'] > $r['refunds'] ? 'discount' : 'refund')) : null,
            ];
        })->values();
    }

    /**
     * Sales channels and marketing sources: orders, net sales and profit for each.
     *
     * @return array{channels: list<array>, sources: list<array>}
     */
    public function channels(Store $store, Carbon $from, Carbon $to, ?Collection $orders = null): array
    {
        $orders ??= $this->orders($store, $from, $to);
        $sum = function (Collection $group, string $label) {
            $net = $group->sum('net_sales');

            return ['label' => $label, 'orders' => $group->count(), 'net_sales' => $net, 'profit' => $group->sum('profit'),
                'margin' => $net > 0 ? $group->sum('profit') / $net * 100 : null, 'aov' => $group->count() ? $group->sum('gross_sales') / $group->count() : null];
        };

        return [
            'channels' => $orders->groupBy(fn ($o) => self::channelLabel($o['source']))->map($sum)->sortByDesc('net_sales')->values()->all(),
            'sources' => $orders->groupBy(fn ($o) => self::sourceLabel($o))->map($sum)->sortByDesc('net_sales')->values()->all(),
        ];
    }

    public static function channelLabel(?string $source): string
    {
        return match (true) {
            $source === null || $source === '' => 'Unknown',
            $source === 'web' => 'Online Store',
            $source === 'pos' => 'Point of Sale',
            $source === 'shopify_draft_order' => 'Draft orders',
            $source === 'iphone', $source === 'android' => 'Shopify mobile',
            str_contains($source, 'facebook') || str_contains($source, 'instagram') => 'Facebook & Instagram',
            str_contains($source, 'google') => 'Google & YouTube',
            str_contains($source, 'tiktok') => 'TikTok',
            default => ucwords(str_replace(['_', '-'], ' ', $source)),
        };
    }

    /** Marketing source from UTM tags, then the referring site; "Direct" when neither exists. */
    public static function sourceLabel(array $o): string
    {
        $text = mb_strtolower(trim(($o['utm_source'] ?? '').' '.($o['utm_medium'] ?? '')));
        $ref = mb_strtolower((string) ($o['referring_site'] ?? ''));
        $paid = str_contains($text, 'cpc') || str_contains($text, 'paid') || str_contains($text, 'ppc');

        return match (true) {
            str_contains($text, 'google') || str_contains($ref, 'google.') => $paid ? 'Google Ads' : 'Google',
            str_contains($text, 'facebook') || str_contains($text, 'instagram') || str_contains($text, 'meta') || str_contains($ref, 'facebook.') || str_contains($ref, 'instagram.') => $paid ? 'Meta Ads' : 'Facebook & Instagram',
            str_contains($text, 'tiktok') || str_contains($ref, 'tiktok.') => 'TikTok',
            str_contains($text, 'pinterest') || str_contains($ref, 'pinterest.') => 'Pinterest',
            str_contains($text, 'email') || str_contains($text, 'klaviyo') || str_contains($text, 'newsletter') => 'Email',
            str_contains($text, 'bing') || str_contains($ref, 'bing.') => 'Bing',
            $text !== '' => ucfirst(trim($o['utm_source'] ?: $o['utm_medium'])),
            $ref !== '' => 'Referral',
            default => 'Direct',
        };
    }

    /**
     * Sales over time (F32): gross and net sales, discounts, refunds, orders, units and AOV per day,
     * week or month, with refunds in the period they happened.
     *
     * @return list<array>
     */
    public function trend(Store $store, Carbon $from, Carbon $to, string $interval, ?Collection $orders = null): array
    {
        [$from, $to] = $this->bounds($store, $from, $to);
        $orders ??= $this->orders($store, $from, $to);
        $tz = $store->timezone ?: 'UTC';
        $bucket = fn (Carbon $d) => match ($interval) {
            'week' => $d->copy()->startOfWeek($store->setting('week_starts_on') === 'sunday' ? Carbon::SUNDAY : Carbon::MONDAY)->toDateString(),
            'month' => $d->copy()->startOfMonth()->toDateString(),
            default => $d->toDateString(),
        };
        $rows = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $rows[$bucket($d)] ??= ['period' => $bucket($d), 'gross_sales' => 0.0, 'discounts' => 0.0, 'refunds' => 0.0, 'net_sales' => 0.0, 'orders' => 0, 'units' => 0, 'profit' => 0.0];
        }
        foreach ($orders as $o) {
            $k = $bucket($o['processed_at']->copy()->setTimezone($tz));
            if (! isset($rows[$k])) {
                continue;
            }
            $rows[$k]['gross_sales'] += $o['gross_sales'];
            $rows[$k]['discounts'] += $o['discounts'];
            $rows[$k]['orders']++;
            $rows[$k]['units'] += $o['units'];
            $rows[$k]['profit'] += $o['profit'] + $o['refunds']; // refunds are subtracted below, by refund date
        }
        \App\Models\Refund::where('refunds.store_id', $store->id)->whereBetween('refunds.processed_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->join('orders', 'orders.id', '=', 'refunds.order_id')->whereNull('orders.cancelled_at')
            ->when($store->setting('exclude_test_orders'), fn ($q) => $q->where('orders.test', false))
            ->select('refunds.amount', 'refunds.processed_at')->orderBy('refunds.id')
            ->each(function ($r) use (&$rows, $bucket, $tz) {
                $k = $bucket(Carbon::parse($r->processed_at)->setTimezone($tz));
                if (isset($rows[$k])) {
                    $rows[$k]['refunds'] += (float) $r->amount;
                    $rows[$k]['profit'] -= (float) $r->amount;
                }
            });

        return array_values(array_map(function ($r) {
            $r['net_sales'] = $r['gross_sales'] - $r['discounts'] - $r['refunds'];
            $r['aov'] = $r['orders'] ? ($r['gross_sales'] - $r['discounts']) / $r['orders'] : null;

            return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r);
        }, $rows));
    }

    /**
     * Days of stock per variant (F18): units available ÷ average daily units sold over the window.
     * Null when stock isn't tracked or nothing sold.
     */
    public static function daysOfStock(?int $available, float $unitsSold, int $windowDays): ?float
    {
        if ($available === null || $unitsSold <= 0) {
            return null;
        }

        return round(max(0, $available) / ($unitsSold / $windowDays), 1);
    }

    /** Units sold per variant (Shopify ID) over the last N days. */
    public static function unitsSold(Store $store, int $days): Collection
    {
        return \App\Models\OrderLine::query()->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('order_lines.store_id', $store->id)->whereNull('orders.cancelled_at')
            ->when($store->setting('exclude_test_orders'), fn ($q) => $q->where('orders.test', false))
            ->where('orders.processed_at', '>=', now()->subDays($days))
            ->groupBy('order_lines.variant_shopify_id')
            ->selectRaw('order_lines.variant_shopify_id, sum(order_lines.quantity - order_lines.refunded_quantity) as units')
            ->pluck('units', 'variant_shopify_id')->map(fn ($u) => (float) $u);
    }

    /** @return array{0: Carbon, 1: Carbon} the period in the store's timezone, whole days */
    private function bounds(Store $store, Carbon $from, Carbon $to): array
    {
        $tz = $store->timezone ?: 'UTC';

        return [$from->copy()->setTimezone($tz)->startOfDay(), $to->copy()->setTimezone($tz)->endOfDay()];
    }
}
