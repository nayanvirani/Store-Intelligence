<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Services\Profit\ProfitCalculator;
use App\Services\Sync\SyncManager;
use App\Support\Spa\Page;
use Illuminate\Http\Request;

/**
 * Home: the profit snapshot (Final MVP F2/F12), sync status and the setup checklist.
 */
class HomeController extends Controller
{
    public const RANGES = ['7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];

    public function show(Request $request, Store $store, ProfitCalculator $profit, SyncManager $sync): Page
    {
        // Free shows a 30-day snapshot; other plans pick a range within their history.
        $full = $store->planIncludes('profit_dashboard');
        $range = $full && array_key_exists((string) $request->query('range'), self::RANGES) ? (string) $request->query('range') : '30';
        $tz = $store->timezone ?: 'UTC';
        $to = now($tz);
        $from = now($tz)->subDays((int) $range - 1);
        if (($start = $store->historyStart()) && $from->lt($start)) {
            $from = $start->copy()->setTimezone($tz);
        }
        $previous = $profit->calculate($store, $from->copy()->subDays((int) $range), $from->copy()->subDay());

        return page('home', [
            'range' => $range,
            'ranges' => $full ? self::RANGES : ['30' => self::RANGES['30']],
            'profit' => $profit->calculate($store, $from, $to),
            'previous' => ['net_sales' => $previous['net_sales'], 'profit_after_ads' => $previous['profit_after_ads'], 'orders' => $previous['orders']],
            'sync' => $sync->status($store),
            'counts' => [
                'products' => $store->products()->whereNull('deleted_at')->count(),
                'variants' => $store->variants()->whereNull('deleted_at')->count(),
                'missing_costs' => $store->variants()->whereNull('deleted_at')->whereNull('app_cost')->whereNull('shopify_cost')->count(),
                'cost_rules' => $store->costRules()->where('active', true)->count(),
            ],
            'checklist' => OnboardingController::checklist($store),
        ]);
    }
}
