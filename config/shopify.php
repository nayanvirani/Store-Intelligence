<?php

// Plan defaults from the Final MVP (section 9). Only defaults: the live plans, features and limits
// are in the plans table and edited in the super admin.
$free = ['profit_snapshot', 'alerts_basic', 'health_score', 'growvia_connection'];
$starter = [...$free, 'profit_dashboard', 'profit_breakdowns', 'alerts_all', 'url_monitor', 'data_quality', 'ads_dashboard',
    'marketing_calendar', 'social_publishing', 'ai_captions', 'social_reports', 'sales_analytics', 'product_insights', 'monthly_report'];
$growth = [...$starter, 'break_even_roas', 'alerts_advanced', 'page_speed', 'campaign_health', 'creative_leaderboard', 'ai_planner',
    'morning_brief', 'ask_ai', 'customer_insights', 'csv_exports', 'team_roles'];
$pro = [...$growth, 'profit_goals', 'auto_publish', 'priority_support'];

// Usage limits per plan (null = unlimited, 0 = none). Every limit in App\Services\Usage::METERS.
$limits = fn (array $caps) => $caps + array_fill_keys([
    'history_days', 'ad_accounts', 'social_channels', 'scheduled_posts', 'monitored_urls', 'url_check_minutes', 'ai_questions', 'team_members',
], null);

return [

    /*
    |--------------------------------------------------------------------------
    | Shopify app credentials
    |--------------------------------------------------------------------------
    |
    | Set in the hosting environment (Railway variables). Never commit them.
    |
    */

    'api_key' => env('SHOPIFY_API_KEY'),

    'api_secret' => env('SHOPIFY_API_SECRET'),

    'api_version' => env('SHOPIFY_API_VERSION', '2026-07'),

    // Public "Install app" buttons: the App Store listing, set in Internal Admin → Platform settings
    // once Shopify approves the app. Until then they open the contact page.
    'install_url' => env('SHOPIFY_INSTALL_URL', '/contact'),

    // Header "Sign In": merchants authenticate through Shopify, not a separate password.
    'sign_in_url' => env('SHOPIFY_SIGN_IN_URL', 'https://admin.shopify.com'),

    'scopes' => env('SHOPIFY_SCOPES', 'read_products,read_inventory,read_locations,read_orders'),

    /*
    |--------------------------------------------------------------------------
    | Billing (section 47) — Shopify Managed Pricing
    |--------------------------------------------------------------------------
    |
    | Plans, prices and trials are configured in the Partner Dashboard (App →
    | Distribution → Manage listing → Pricing). Merchants pick a plan on
    | Shopify's own hosted page; the app never creates or cancels charges. It
    | learns the plan from the app_subscriptions/update webhook and a live
    | sync, matching Shopify's plan display name to "shopify_name" below.
    |
    | app_handle is the app's handle in admin URLs
    | (admin.shopify.com/store/{shop}/apps/{app_handle}), used for the
    | pricing page link .../charges/{app_handle}/pricing_plans.
    |
    | test_shops: our own dev stores that get plan access without a
    | subscription (comma-separated *.myshopify.com). Managed Pricing can get
    | stuck on dev stores; this must never block testing. Real merchants
    | always need a real subscription.
    |
    */

    // Fallback only: the handle is read from Shopify on every billing sync.
    'app_handle' => env('SHOPIFY_APP_HANDLE', 'store-intelligence'),

    'test_shops' => array_values(array_filter(array_map('trim', explode(',', (string) env('SI_TEST_SHOPS', ''))))),

    'test_shop_plan' => env('SI_TEST_SHOP_PLAN', 'pro'),

    'billing' => [
        'currency' => 'USD',

        // Merchants see a warning when any usage limit reaches this share.
        'warn_at' => 0.8,

        'plans' => [
            'free' => [
                'name' => 'Free', 'shopify_name' => env('SHOPIFY_PLAN_NAME_FREE', 'Free'), 'price' => 0,
                'description' => 'See your real profit for free', 'support' => 'Standard',
                'includes' => $free,
                'features' => ['Profit snapshot (last 30 days)', 'Store Health Score', '3 essential money-leak alerts', 'Action Center summary', 'Weekly email summary'],
                'limits' => $limits(['history_days' => 30, 'ad_accounts' => 0, 'social_channels' => 0, 'scheduled_posts' => 0, 'monitored_urls' => 0, 'url_check_minutes' => 0, 'ai_questions' => 0, 'team_members' => 1]),
            ],
            'starter' => [
                'name' => 'Starter', 'shopify_name' => env('SHOPIFY_PLAN_NAME_STARTER', 'Starter'), 'price' => 29,
                'description' => 'Know your profit and protect your store', 'support' => 'Standard',
                'includes' => $starter,
                'features' => ['Real Profit Dashboard', 'All store-protection alerts', 'Google & Meta Ads insights ({ad_accounts|ad account})', '{monitored_urls|monitored URL}', '{scheduled_posts} social posts/month with AI captions', 'Monthly Business Report', '{history_days} days of history'],
                'limits' => $limits(['history_days' => 365, 'ad_accounts' => 2, 'social_channels' => 2, 'scheduled_posts' => 15, 'monitored_urls' => 10, 'url_check_minutes' => 60, 'ai_questions' => 0, 'team_members' => 1]),
            ],
            'growth' => [
                'name' => 'Growth', 'shopify_name' => env('SHOPIFY_PLAN_NAME_GROWTH', 'Growth'), 'price' => 79, 'badge' => 'Most Popular',
                'description' => 'Grow profitably with ads and social', 'support' => 'Priority',
                'includes' => $growth,
                'features' => ['Break-even ROAS & profit on ad spend', 'Campaign health & creative leaderboard', 'AI 30-day social planner ({scheduled_posts} posts/month)', 'AI Morning Brief & Ask your store ({ai_questions} questions/month)', 'Page speed & customer insights', '{team_members|team member}', 'Everything in Starter'],
                'limits' => $limits(['history_days' => 730, 'ad_accounts' => 4, 'social_channels' => 4, 'scheduled_posts' => 60, 'monitored_urls' => 30, 'url_check_minutes' => 15, 'ai_questions' => 200, 'team_members' => 3]),
            ],
            'pro' => [
                'name' => 'Pro', 'shopify_name' => env('SHOPIFY_PLAN_NAME_PRO', 'Pro'), 'price' => 149,
                'description' => 'Run marketing on autopilot', 'support' => 'Priority / enhanced',
                'includes' => $pro,
                'features' => ['{scheduled_posts} social posts/month', 'Auto-publish', 'Profit goals & pacing', '{team_members|team member}', '{history_days} days of history', 'Everything in Growth'],
                'limits' => $limits(['history_days' => 1095, 'ad_accounts' => 10, 'social_channels' => 4, 'scheduled_posts' => 300, 'monitored_urls' => 100, 'url_check_minutes' => 5, 'ai_questions' => 1000, 'team_members' => 10]),
            ],
        ],
    ],

];
