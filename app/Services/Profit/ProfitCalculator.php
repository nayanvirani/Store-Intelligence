<?php

namespace App\Services\Profit;

use App\Models\CostRule;
use App\Models\Order;
use App\Models\Refund;
use App\Models\Store;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The profit waterfall (Final MVP F12, section 7 metric definitions):
 *
 *   Gross sales − discounts − returns = net sales
 *   Net sales − COGS = gross profit
 *   Gross profit − payment fees − shipping − handling = contribution profit
 *   Contribution profit − ad spend = profit after ads
 *   Profit after ads − fixed costs = estimated net profit (only when fixed costs are entered)
 *
 * Amounts are in the shop's currency; taxes are never revenue. Returns count in the period the refund
 * happened. COGS uses each line's cost at order time. Lines without a cost are reported as missing
 * coverage (or, if the merchant set a default margin, an assumed cost that is labelled as such).
 */
class ProfitCalculator
{
    public function calculate(Store $store, Carbon $from, Carbon $to): array
    {
        $tz = $store->timezone ?: 'UTC';
        $from = $from->copy()->setTimezone($tz)->startOfDay();
        $to = $to->copy()->setTimezone($tz)->endOfDay();
        $rules = CostRule::where('store_id', $store->id)->where('active', true)->get()->groupBy('type');
        $margin = $store->setting('default_margin');

        $days = [];
        for ($d = $from->copy(); $d->lte($to); $d->addDay()) {
            $days[$d->toDateString()] = ['date' => $d->toDateString(), 'net_sales' => 0.0, 'profit' => 0.0, 'orders' => 0];
        }

        $t = [
            'orders' => 0, 'units' => 0, 'gross_sales' => 0.0, 'discounts' => 0.0, 'returns' => 0.0, 'cogs' => 0.0, 'assumed_cogs' => 0.0,
            'payment_fees' => 0.0, 'shipping_costs' => 0.0, 'handling_costs' => 0.0, 'units_with_cost' => 0, 'units_counted' => 0,
        ];

        Order::counted($store)->whereBetween('processed_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->with('lines')->chunkById(500, function (Collection $orders) use (&$t, &$days, $rules, $margin, $tz) {
                foreach ($orders as $order) {
                    $day = $order->processed_at->copy()->setTimezone($tz)->toDateString();
                    $orderCogs = 0.0;
                    foreach ($order->lines as $line) {
                        if ($line->is_gift_card) {
                            continue; // gift cards are a liability, not merchandise sales
                        }
                        $kept = max(0, $line->quantity - $line->refunded_quantity);
                        $t['units_counted'] += $line->quantity;
                        if ($line->unit_cost !== null) {
                            $t['units_with_cost'] += $line->quantity;
                            $orderCogs += (float) $line->unit_cost * $kept;
                        } elseif ($margin !== null && $line->quantity > 0) {
                            $unitRevenue = (float) $line->price - (float) $line->discount / $line->quantity;
                            $assumed = max(0, $unitRevenue * (1 - (float) $margin / 100)) * $kept;
                            $orderCogs += $assumed;
                            $t['assumed_cogs'] += $assumed;
                        }
                    }

                    $fees = $this->paymentFee($order, $rules->get('payment_fee', collect()));
                    $shipping = $this->shippingCost($order, $rules->get('shipping', collect()));
                    $handling = $order->units > 0 ? (float) $rules->get('handling', collect())->sum(fn ($r) => (float) ($r->config['amount'] ?? 0)) : 0.0;
                    $merch = (float) $order->gross_sales - (float) $order->total_discounts;

                    $t['orders']++;
                    $t['units'] += $order->units;
                    $t['gross_sales'] += (float) $order->gross_sales;
                    $t['discounts'] += (float) $order->total_discounts;
                    $t['cogs'] += $orderCogs;
                    $t['payment_fees'] += $fees;
                    $t['shipping_costs'] += $shipping;
                    $t['handling_costs'] += $handling;

                    if (isset($days[$day])) {
                        $days[$day]['orders']++;
                        $days[$day]['net_sales'] += $merch;
                        $days[$day]['profit'] += $merch - $orderCogs - $fees - $shipping - $handling;
                    }
                }
            });

        // Returns reduce the period the refund happened in.
        Refund::where('refunds.store_id', $store->id)->whereBetween('refunds.processed_at', [$from->copy()->utc(), $to->copy()->utc()])
            ->join('orders', 'orders.id', '=', 'refunds.order_id')->whereNull('orders.cancelled_at')
            ->when($store->setting('exclude_test_orders'), fn ($q) => $q->where('orders.test', false))
            ->select('refunds.amount', 'refunds.processed_at')->orderBy('refunds.id')
            ->each(function ($refund) use (&$t, &$days, $tz) {
                $t['returns'] += (float) $refund->amount;
                $day = Carbon::parse($refund->processed_at)->setTimezone($tz)->toDateString();
                if (isset($days[$day])) {
                    $days[$day]['net_sales'] -= (float) $refund->amount;
                    $days[$day]['profit'] -= (float) $refund->amount;
                }
            });

        // Monthly amounts (other ad spend, fixed costs) spread evenly over the days of each month.
        $adSpend = $this->monthly($rules->get('ad_spend', collect()), $days);
        $fixed = $this->monthly($rules->get('fixed_cost', collect()), $days);
        foreach ($days as $date => $row) {
            $days[$date]['profit'] -= $adSpend['by_day'][$date] ?? 0;
        }

        $netSales = $t['gross_sales'] - $t['discounts'] - $t['returns'];
        $grossProfit = $netSales - $t['cogs'];
        $contribution = $grossProfit - $t['payment_fees'] - $t['shipping_costs'] - $t['handling_costs'];
        $afterAds = $contribution - $adSpend['total'];
        $round = fn ($v) => round($v, 2);

        return [
            'currency' => $store->currency ?: 'USD',
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'orders' => $t['orders'],
            'units' => $t['units'],
            'gross_sales' => $round($t['gross_sales']),
            'discounts' => $round($t['discounts']),
            'returns' => $round($t['returns']),
            'net_sales' => $round($netSales),
            'cogs' => $round($t['cogs']),
            'assumed_cogs' => $round($t['assumed_cogs']),
            'gross_profit' => $round($grossProfit),
            'payment_fees' => $round($t['payment_fees']),
            'shipping_costs' => $round($t['shipping_costs']),
            'handling_costs' => $round($t['handling_costs']),
            'contribution_profit' => $round($contribution),
            'ad_spend' => $round($adSpend['total']),
            'profit_after_ads' => $round($afterAds),
            'fixed_costs' => $round($fixed['total']),
            'net_profit' => $rules->has('fixed_cost') ? $round($afterAds - $fixed['total']) : null,
            'margin' => $netSales > 0 ? round($afterAds / $netSales * 100, 1) : null,
            'aov' => $t['orders'] > 0 ? $round(($t['gross_sales'] - $t['discounts']) / $t['orders']) : null,
            'coverage' => [
                'units_with_cost' => $t['units_with_cost'],
                'units' => $t['units_counted'],
                'percent' => $t['units_counted'] > 0 ? round($t['units_with_cost'] / $t['units_counted'] * 100, 1) : null,
            ],
            'configured' => [
                'payment_fees' => $rules->has('payment_fee'),
                'shipping' => $rules->has('shipping'),
                'default_margin' => $margin !== null,
            ],
            'daily' => array_values(array_map(fn ($d) => ['date' => $d['date'], 'orders' => $d['orders'], 'net_sales' => $round($d['net_sales']), 'profit' => $round($d['profit'])], $days)),
        ];
    }

    /** Payment fee: the rule for the order's gateway, otherwise the rule without a gateway. */
    private function paymentFee(Order $order, Collection $rules): float
    {
        if ($rules->isEmpty() || (float) $order->total_price <= 0) {
            return 0.0;
        }
        $gateway = mb_strtolower((string) $order->payment_gateway);
        $rule = $rules->first(fn ($r) => ($g = mb_strtolower(trim((string) ($r->config['gateway'] ?? '')))) !== '' && $gateway !== '' && str_contains($gateway, $g))
            ?? $rules->first(fn ($r) => trim((string) ($r->config['gateway'] ?? '')) === '');
        if ($rule === null) {
            return 0.0;
        }

        return (float) $order->total_price * (float) ($rule->config['percent'] ?? 0) / 100 + (float) ($rule->config['fixed'] ?? 0);
    }

    /** Shipping cost from the merchant's rules: per order or per item. */
    private function shippingCost(Order $order, Collection $rules): float
    {
        if ($rules->isEmpty() || $order->units <= 0) {
            return 0.0;
        }

        return (float) $rules->sum(fn ($r) => (float) ($r->config['amount'] ?? 0) * (($r->config['basis'] ?? 'order') === 'item' ? $order->units : 1));
    }

    /** @return array{total: float, by_day: array<string, float>} */
    private function monthly(Collection $rules, array $days): array
    {
        $byDay = [];
        $perMonth = (float) $rules->sum(fn ($r) => (float) ($r->config['amount'] ?? 0));
        if ($perMonth <= 0) {
            return ['total' => 0.0, 'by_day' => []];
        }
        foreach (array_keys($days) as $date) {
            $byDay[$date] = $perMonth / Carbon::parse($date)->daysInMonth;
        }

        return ['total' => array_sum($byDay), 'by_day' => $byDay];
    }
}
