<?php

namespace App\Support;

use App\Models\FinanceEntry;
use App\Models\Subscription;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Internal Admin (Finance): monthly earnings, expenses and profit.
 *
 * Revenue: each store with a paid subscription counts once in every month it was billable (after
 * its free trial, until it was cancelled), at the plan it was on last that month. Development,
 * partner and staff stores, test stores, test charges and complimentary plans don't count.
 * Plus manual income entries.
 * Expenses: Railway hosting (from Railway's API), other fixed monthly costs, percentage fees on subscription revenue
 * (e.g. Shopify's 2.9% processing fee) and manual expense entries.
 */
class Finance
{
    private const KEY = 'finance';

    private const CACHE = 'finance-settings:v1';

    public const CATEGORIES = ['Hosting', 'Domain & email', 'Software & tools', 'Marketing', 'Contractors', 'Fees', 'Taxes', 'Other'];

    /** @return array{recurring: list<array{name: string, amount: float, from: string, until: ?string}>, fees: list<array{name: string, percent: float}>} */
    public static function settings(): array
    {
        $saved = [];
        try {
            $saved = Cache::rememberForever(self::CACHE, function () {
                $row = Schema::hasTable('platform_settings') ? DB::table('platform_settings')->where('key', self::KEY)->value('value') : null;

                return $row ? (array) json_decode($row, true) : [];
            });
        } catch (Throwable) {
            // No database yet: defaults.
        }

        // Railway comes from its API (RailwayBilling); these are other fixed costs (domain, tools…).
        $recurring = $saved['recurring'] ?? [];
        $fees = $saved['fees'] ?? [['name' => 'Shopify processing fee', 'percent' => 2.9]];

        return [
            'recurring' => array_map(fn ($r) => ['name' => (string) $r['name'], 'amount' => (float) $r['amount'], 'from' => (string) $r['from'], 'until' => $r['until'] ?? null], $recurring),
            'fees' => array_map(fn ($f) => ['name' => (string) $f['name'], 'percent' => (float) $f['percent']], $fees),
        ];
    }

    /** Saves the fixed costs and fees from the admin form (rows with a name). */
    public static function save(array $recurring, array $fees): void
    {
        $month = fn ($v) => preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', (string) $v) ? (string) $v : null;
        $rows = [];
        foreach (array_slice($recurring, 0, 50) as $r) {
            $name = mb_substr(trim(strip_tags((string) ($r['name'] ?? ''))), 0, 80);
            if ($name === '') {
                continue;
            }
            $rows[] = ['name' => $name, 'amount' => round(max(0, (float) ($r['amount'] ?? 0)), 2), 'from' => $month($r['from'] ?? null) ?? now()->format('Y-m'), 'until' => $month($r['until'] ?? null)];
        }
        $feeRows = [];
        foreach (array_slice($fees, 0, 20) as $f) {
            $name = mb_substr(trim(strip_tags((string) ($f['name'] ?? ''))), 0, 80);
            if ($name !== '') {
                $feeRows[] = ['name' => $name, 'percent' => round(min(100, max(0, (float) ($f['percent'] ?? 0))), 3)];
            }
        }
        DB::table('platform_settings')->updateOrInsert(['key' => self::KEY], ['value' => json_encode(['recurring' => $rows, 'fees' => $feeRows]), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget(self::CACHE);
    }

    /**
     * One month's numbers.
     *
     * @return array{month: string, label: string, subscriptions: float, subscribers: int, income: float, revenue: float, recurring: list<array>, fees: list<array>, entries: \Illuminate\Support\Collection, manual: float, expenses: float, profit: float, margin: ?float}
     */
    public static function month(string $month): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month)->startOfMonth();
        $end = $start->endOfMonth();
        $settings = self::settings();

        $billable = self::billable($start, $end);
        $subscriptions = round($billable->sum('price'), 2);
        // By date only: entries on the month's last day count in that month.
        $entries = FinanceEntry::whereDate('date', '>=', $start->toDateString())->whereDate('date', '<=', $end->toDateString())->orderBy('date')->orderBy('id')->get();
        $income = round((float) $entries->where('kind', 'income')->sum('amount'), 2);
        $manual = round((float) $entries->where('kind', 'expense')->sum('amount'), 2);

        $recurring = collect($settings['recurring'])
            ->filter(fn ($r) => $r['from'] <= $month && ($r['until'] === null || $r['until'] >= $month))
            ->values()->all();
        $fees = collect($settings['fees'])->map(fn ($f) => $f + ['amount' => round($subscriptions * $f['percent'] / 100, 2)])->all();

        $railway = RailwayBilling::forMonth($month);
        // Connected to Railway: a typed-in Railway cost would count it twice.
        $skipped = $railway['connected'] ? array_values(array_filter($recurring, fn ($r) => stripos($r['name'], 'railway') !== false)) : [];
        $recurring = array_values(array_filter($recurring, fn ($r) => ! in_array($r, $skipped, true)));

        $revenue = round($subscriptions + $income, 2);
        $expenses = round($railway['amount'] + array_sum(array_column($recurring, 'amount')) + array_sum(array_column($fees, 'amount')) + $manual, 2);
        $profit = round($revenue - $expenses, 2);

        return [
            'month' => $month, 'label' => $start->format('F Y'),
            'subscriptions' => $subscriptions, 'subscribers' => $billable->count(), 'income' => $income, 'revenue' => $revenue,
            'railway' => $railway, 'skipped' => $skipped, 'recurring' => $recurring, 'fees' => $fees, 'entries' => $entries, 'manual' => $manual,
            'expenses' => $expenses, 'profit' => $profit, 'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : null,
        ];
    }

    /** The last $count months, newest first. */
    public static function months(int $count = 12, ?string $until = null): array
    {
        $last = CarbonImmutable::createFromFormat('!Y-m', $until ?? now()->format('Y-m'));

        return collect(range(0, $count - 1))->map(fn ($i) => self::month($last->subMonths($i)->format('Y-m')))->all();
    }

    /** Shopify plans of stores that are never charged (development, partner sandbox, staff…). */
    public const UNBILLED_PLANS = '/develop|partner|sandbox|staff|affiliate|preview/i';

    /**
     * Paid subscriptions billed in the month: at most one per store (the plan it was on last that
     * month, as Shopify credits a replaced plan), never for development, partner or staff stores.
     */
    private static function billable(CarbonImmutable $start, CarbonImmutable $end)
    {
        return self::active($start, $end)
            ->reject(fn (Subscription $s) => preg_match(self::UNBILLED_PLANS, (string) $s->store->shopify_plan))
            ->groupBy('store_id')
            ->map(fn ($subs) => $subs->sortBy(fn ($s) => [$s->activated_at->timestamp, $s->id])->last())
            ->values();
    }

    /** Paid subscriptions active at some point in the month. */
    private static function active(CarbonImmutable $start, CarbonImmutable $end)
    {
        return Subscription::with('store')
            ->where('test', false)->where('price', '>', 0)->whereNotNull('activated_at')
            ->get()
            ->filter(function (Subscription $s) use ($start, $end) {
                $from = $s->trial_ends_at && $s->trial_ends_at->gt($s->activated_at) ? $s->trial_ends_at : $s->activated_at;
                // Not counted in a month that hasn't started yet.
                if ($from->gt($end) || $start->gt(now())) {
                    return false;
                }
                if ($s->cancelled_at && $s->cancelled_at->lt($start)) {
                    return false;
                }
                // Cancelled before the trial ended: never charged.
                if ($s->cancelled_at && $s->cancelled_at->lte($from)) {
                    return false;
                }

                return $s->store && ! $s->store->isTestShop() && ! $s->store->compPlan();
            })
            ->values();
    }
}
