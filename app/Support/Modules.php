<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Every feature a plan (or a single store, through its entitlements) can switch on or off.
 * A plan "includes" a list of these keys; App\Models\Store::planIncludes() checks one.
 * A feature with a parent only works when the parent is included too.
 * Labels, descriptions and pricing-page visibility can be changed in the super admin.
 */
class Modules
{
    /** key => [label, group, help, parent] */
    public const ALL = [
        'profit_snapshot' => ['Profit snapshot', 'Profit', 'Profit for the last 30 days, store level.', null],
        'profit_dashboard' => ['Real Profit Dashboard', 'Profit', 'Full profit waterfall and trend with any date range.', null],
        'profit_breakdowns' => ['Profit breakdowns', 'Profit', 'Profit by product, order, channel and campaign.', 'profit_dashboard'],
        'break_even_roas' => ['Break-even ROAS & POAS', 'Profit', 'Break-even ROAS per product and profit on ad spend per campaign.', 'profit_dashboard'],
        'profit_goals' => ['Profit goals & pacing', 'Profit', 'Monthly sales and profit goals with a month-end projection.', 'profit_dashboard'],

        'health_score' => ['Store Health Score', 'Protect', 'One 0–100 score that rises as issues are fixed.', null],
        'alerts_basic' => ['Essential alerts', 'Protect', 'Price mistakes, best sellers running low and missing costs on best sellers.', null],
        'alerts_all' => ['All money-leak alerts', 'Protect', 'Every store, inventory and ads alert.', null],
        'alerts_advanced' => ['Advanced alerts', 'Protect', 'Below break-even campaigns, loss-making order spikes and slow key pages.', 'alerts_all'],
        'url_monitor' => ['Landing page monitor', 'Protect', 'Checks ad landing pages and key URLs for failures and slowdowns.', null],
        'page_speed' => ['Page speed health', 'Protect', 'Core Web Vitals for key pages.', null],
        'data_quality' => ['Product data quality', 'Protect', 'Missing costs, SKUs, images and other catalog gaps.', null],

        'ads_dashboard' => ['Ads insights', 'Ads', 'Google Ads, Meta Ads and GA4 in one dashboard.', null],
        'campaign_health' => ['Campaign health', 'Ads', 'Scale, watch and pause signals per campaign.', 'ads_dashboard'],
        'creative_leaderboard' => ['Creative leaderboard', 'Ads', 'Which ads bring revenue, not just clicks.', 'ads_dashboard'],

        'marketing_calendar' => ['Marketing calendar', 'Social', 'Holidays, shopping events and your own dates.', null],
        'social_publishing' => ['Social scheduling & publishing', 'Social', 'Schedule and publish posts to connected channels.', null],
        'ai_captions' => ['AI captions', 'Social', 'AI writes captions and hashtags for a post.', 'social_publishing'],
        'ai_planner' => ['AI 30-day planner', 'Social', 'AI plans a full month of posts.', 'social_publishing'],
        'auto_publish' => ['Auto-publish', 'Social', 'Posts publish without approval (you can still pause any).', 'social_publishing'],
        'social_reports' => ['Social reports', 'Social', 'Monthly social report with orders and revenue from social.', null],

        'sales_analytics' => ['Sales analytics', 'Insights', 'Sales, orders, AOV and refund trends.', null],
        'product_insights' => ['Product insights', 'Insights', 'Best sellers, rising and falling products, what to advertise.', null],
        'customer_insights' => ['Customer insights', 'Insights', 'Repeat rate, cohorts and lifetime value.', null],
        'morning_brief' => ['AI Morning Brief', 'Insights', 'A one-minute daily summary in the app and by email.', null],
        'ask_ai' => ['Ask your store', 'Insights', 'Questions answered from your store data.', null],
        'monthly_report' => ['Monthly Business Report', 'Insights', 'Month-in-review PDF and email on the 1st.', null],
        'csv_exports' => ['CSV exports', 'Insights', 'Download reports as CSV.', null],

        'team_roles' => ['Team members & roles', 'Account', 'Invite staff with Owner, Admin, Marketer or Analyst roles.', null],
        'growvia_connection' => ['Growvia connection', 'Account', 'Link with Growvia for conversion data and one-click fixes.', null],
        'priority_support' => ['Priority support', 'Account', 'Tickets answered first.', null],
    ];

    private const OVERRIDES_KEY = 'feature-catalog:v1';

    /** The catalog with the super admin's label, help and pricing-page changes. key => [label, group, help, parent, public] */
    public static function all(): array
    {
        $overrides = self::overrides();

        return collect(self::ALL)->map(fn ($m, $key) => [
            $overrides[$key]['label'] ?? $m[0], $m[1], $overrides[$key]['help'] ?? $m[2], $m[3], $overrides[$key]['public'] ?? true,
        ])->all();
    }

    public static function label(string $key): string
    {
        return self::all()[$key][0] ?? $key;
    }

    /** @return array<string, array<string, array>> group => features */
    public static function grouped(): array
    {
        return collect(self::all())->groupBy(fn ($m) => $m[1], true)->map->all()->all();
    }

    public static function overrides(): array
    {
        try {
            return Cache::rememberForever(self::OVERRIDES_KEY, fn () => (array) json_decode((string) DB::table('platform_settings')->where('key', 'feature_catalog')->value('value'), true));
        } catch (Throwable) {
            return [];
        }
    }

    public static function saveOverrides(array $overrides): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => 'feature_catalog'], ['value' => json_encode($overrides), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget(self::OVERRIDES_KEY);
    }

    /** A feature's key plus its parents (a child only works with its parent). */
    public static function chain(string $key): array
    {
        $chain = [$key];
        while (($parent = self::ALL[$key][3] ?? null) !== null) {
            $chain[] = $key = $parent;
        }

        return $chain;
    }
}
