<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** The words on the public pricing page and the in-app plan picker, edited in the super admin. */
class PricingPage
{
    private const KEY = 'pricing-page:v1';

    public const FIELDS = [
        'headline' => ['Headline', 'Plans that grow with your store.'],
        'lead' => ['Supporting message', 'Start free with your real profit. Upgrade for ads insights, AI social planning and more.'],
        'billing_note' => ['Billing note', 'Billed through Shopify. Change or cancel anytime.'],
        'trial_note' => ['Trial note (optional)', ''],
        'compare_title' => ['Comparison table title', 'Compare plans'],
    ];

    public static function get(): array
    {
        $saved = Cache::rememberForever(self::KEY, function () {
            try {
                return (array) json_decode((string) DB::table('platform_settings')->where('key', 'pricing_page')->value('value'), true);
            } catch (Throwable) {
                return [];
            }
        });

        return collect(self::FIELDS)->map(fn ($f, $k) => (string) ($saved[$k] ?? $f[1]))->all();
    }

    public static function save(array $values): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => 'pricing_page'], ['value' => json_encode(collect(self::FIELDS)->map(fn ($f, $k) => trim((string) ($values[$k] ?? '')))->all()), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget(self::KEY);
    }
}
