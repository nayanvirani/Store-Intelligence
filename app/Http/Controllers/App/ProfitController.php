<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Profit\Breakdowns;
use App\Services\Profit\ProfitCalculator;
use App\Support\Period;
use App\Support\Spa\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Profit (Final MVP F12 dashboard, F13 breakdowns, F32 sales analytics). One page with tabs:
 * Overview (waterfall, KPIs, trend), Products, Orders, Channels and Sales.
 */
class ProfitController extends Controller
{
    public const TABS = [
        'overview' => ['Overview', null],
        'products' => ['Products', 'profit_breakdowns'],
        'orders' => ['Orders', 'profit_breakdowns'],
        'channels' => ['Channels', 'profit_breakdowns'],
        'sales' => ['Sales', 'sales_analytics'],
    ];

    public function show(Request $request, Store $store, ProfitCalculator $calc, Breakdowns $breakdowns, string $tab = 'overview'): Page
    {
        $tab = array_key_exists($tab, self::TABS) ? $tab : 'overview';
        $period = Period::from($request, $store);
        $feature = self::TABS[$tab][1];
        $props = [
            'tab' => $tab,
            'tabs' => collect(self::TABS)->map(fn ($t) => ['label' => $t[0], 'locked' => $t[1] !== null && ! $store->planIncludes($t[1])])->all(),
            'period' => $period->toArray($store),
            'currency' => $store->currency ?: 'USD',
            'canExport' => $store->planIncludes('csv_exports'),
            'locked' => $feature !== null && ! $store->planIncludes($feature) ? $feature : null,
        ];

        if ($props['locked']) {
            return page('profit/show', $props);
        }

        $profit = $calc->calculate($store, $period->from, $period->to);
        [$pf, $pt] = $period->previous();
        $props['profit'] = $profit;
        $props['previous'] = collect($calc->calculate($store, $pf, $pt))->only(['net_sales', 'profit_after_ads', 'margin', 'ad_spend', 'orders', 'aov', 'gross_profit', 'contribution_profit', 'returns', 'units', 'discounts', 'gross_sales'])->all();

        if ($tab !== 'overview') {
            $orders = $breakdowns->orders($store, $period->from, $period->to);
            $props += match ($tab) {
                'products' => ['rows' => $this->products($breakdowns->products($store, $period->from, $period->to, $orders), $request)],
                'orders' => ['rows' => $this->orders($orders, $request, $store)],
                'channels' => $this->round($breakdowns->channels($store, $period->from, $period->to, $orders)),
                'sales' => ['interval' => $this->interval($request, $period), 'trend' => $breakdowns->trend($store, $period->from, $period->to, $this->interval($request, $period), $orders)],
            };
        }

        return page('profit/show', $props);
    }

    /** CSV of the current tab (CSV exports feature). */
    public function export(Request $request, Store $store, Breakdowns $breakdowns, string $tab): StreamedResponse
    {
        abort_unless($store->planIncludes('csv_exports'), 402);
        abort_unless(in_array($tab, ['products', 'orders', 'channels', 'sales'], true), 404);
        abort_unless($store->planIncludes(self::TABS[$tab][1]), 402);
        $period = Period::from($request, $store);
        $orders = $breakdowns->orders($store, $period->from, $period->to);

        [$head, $rows] = match ($tab) {
            'products' => [['Product', 'Units', 'Orders', 'Gross sales', 'Discounts', 'Refunds', 'Net sales', 'Product costs', 'Gross profit', 'Margin %', 'Refund rate %', 'Discount share %', 'Units without cost'],
                $breakdowns->products($store, $period->from, $period->to, $orders)->sortByDesc('gross_profit')->map(fn ($p) => [$p['title'], $p['units'], $p['orders'], $p['gross_sales'], $p['discounts'], $p['refunds'], $p['net_sales'], $p['cogs'], $p['gross_profit'], $p['margin'], $p['refund_rate'], $p['discount_share'], $p['missing_cost_units']])],
            'orders' => [['Order', 'Date', 'Channel', 'Source', 'Units', 'Gross sales', 'Discounts', 'Refunds', 'Net sales', 'Product costs', 'Payment fees', 'Shipping', 'Handling', 'Profit', 'Margin %', 'Loss reason'],
                $orders->sortByDesc('processed_at')->map(fn ($o) => [$o['name'], $o['processed_at']->setTimezone($store->timezone ?: 'UTC')->toDateTimeString(), Breakdowns::channelLabel($o['source']), Breakdowns::sourceLabel($o), $o['units'], $o['gross_sales'], $o['discounts'], $o['refunds'], $o['net_sales'], $o['cogs'], $o['fees'], $o['shipping'], $o['handling'], $o['profit'], $o['margin'], $o['reason']])],
            'channels' => [['Type', 'Channel or source', 'Orders', 'Net sales', 'Profit before ads', 'Margin %', 'AOV'],
                collect($breakdowns->channels($store, $period->from, $period->to, $orders))->flatMap(fn ($list, $type) => collect($list)->map(fn ($c) => [$type === 'channels' ? 'Sales channel' : 'Marketing source', $c['label'], $c['orders'], $c['net_sales'], $c['profit'], $c['margin'], $c['aov']]))],
            'sales' => [['Period', 'Gross sales', 'Discounts', 'Refunds', 'Net sales', 'Orders', 'Units', 'AOV'],
                collect($breakdowns->trend($store, $period->from, $period->to, $this->interval($request, $period), $orders))->map(fn ($r) => [$r['period'], $r['gross_sales'], $r['discounts'], $r['refunds'], $r['net_sales'], $r['orders'], $r['units'], $r['aov']])],
        };

        $name = "profit-{$tab}-{$period->from->toDateString()}-to-{$period->to->toDateString()}.csv";

        return response()->streamDownload(function () use ($head, $rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, $head);
            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($v) => is_float($v) ? round($v, 2) : (is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'".$v : $v), $row));
            }
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    private function interval(Request $request, Period $period): string
    {
        $i = (string) $request->query('interval');
        if (in_array($i, ['day', 'week', 'month'], true)) {
            return $i;
        }

        return $period->days() > 120 ? 'month' : ($period->days() > 45 ? 'week' : 'day');
    }

    private function products(Collection $rows, Request $request): array
    {
        $sort = in_array($request->query('sort'), ['gross_profit', 'net_sales', 'units', 'margin', 'refund_rate'], true) ? $request->query('sort') : 'gross_profit';
        $only = $request->query('only') === 'loss';

        return $rows->when($only, fn ($c) => $c->filter(fn ($p) => $p['gross_profit'] < 0))
            ->sortByDesc(fn ($p) => $p[$sort] ?? -INF)->values()->take(200)
            ->map(fn ($p) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $p))->all();
    }

    private function orders(Collection $rows, Request $request, Store $store): array
    {
        $only = $request->query('only') === 'loss';
        $sort = $request->query('sort') === 'profit' ? 'profit' : 'date';
        $list = $rows->when($only, fn ($c) => $c->filter(fn ($o) => $o['profit'] < 0))
            ->when($sort === 'profit', fn ($c) => $c->sortBy('profit'), fn ($c) => $c->sortByDesc('processed_at'))->values();
        $page = max(1, (int) $request->query('page', 1));
        $per = 50;

        return [
            'data' => $list->slice(($page - 1) * $per, $per)->map(fn ($o) => [
                'id' => $o['id'], 'name' => $o['name'], 'date' => $o['processed_at']->toIso8601String(),
                'channel' => Breakdowns::channelLabel($o['source']), 'source' => Breakdowns::sourceLabel($o), 'units' => $o['units'],
                'net_sales' => round($o['net_sales'], 2), 'cogs' => round($o['cogs'], 2), 'fees' => round($o['fees'], 2),
                'shipping' => round($o['shipping'] + $o['handling'], 2), 'refunds' => round($o['refunds'], 2), 'discounts' => round($o['discounts'], 2),
                'profit' => round($o['profit'], 2), 'margin' => $o['margin'] === null ? null : round($o['margin'], 1),
                'reason' => $o['reason'], 'missing_cost' => $o['missing_cost_units'] > 0,
                'url' => $store->adminUrl('orders/'.$o['shopify_id']),
            ])->values()->all(),
            'page' => $page,
            'last_page' => max(1, (int) ceil($list->count() / $per)),
            'total' => $list->count(),
            'losses' => $rows->filter(fn ($o) => $o['profit'] < 0)->count(),
            'only' => $only ? 'loss' : null,
            'sort' => $sort,
        ];
    }

    private function round(array $data): array
    {
        return array_map(fn ($list) => array_map(fn ($r) => array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $r), $list), $data);
    }
}
