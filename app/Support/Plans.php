<?php

namespace App\Support;

use App\Models\Plan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Plans come from the database (edited in the Internal Admin) and are loaded into
 * config('shopify.billing.plans') on boot, so the rest of the app reads them as before.
 */
class Plans
{
    private const KEY = 'billing-plans:v2';

    /** Loads the stored plans over the config defaults; a no-op until the table exists. */
    public static function boot(): void
    {
        try {
            $plans = Cache::get(self::KEY);
            if ($plans === null && Schema::hasTable('plans')) {
                $plans = Plan::orderBy('position')->orderBy('id')->get()->mapWithKeys(fn (Plan $p) => [$p->key => [
                    'name' => $p->name, 'shopify_name' => $p->shopify_name, 'price' => $p->price, 'trial_days' => $p->trial_days,
                    'includes' => array_values($p->modules ?? []), 'limits' => $p->limits ?? [],
                    'features' => $p->features ?? [], 'description' => $p->description, 'badge' => $p->badge, 'support' => $p->support_label,
                    'public' => $p->is_public, 'active' => $p->is_active,
                ]])->all() ?: null;
                if ($plans) {
                    Cache::forever(self::KEY, $plans);
                }
            }
        } catch (Throwable) {
            return; // No database yet (build steps, first deploy).
        }

        if ($plans) {
            config(['shopify.billing.plans' => $plans]);
        }
    }

    public static function forget(): void
    {
        Cache::forget(self::KEY);
        self::boot();
    }

    /** Plans shown to merchants (pricing pages and the in-app plan list). */
    public static function public(): array
    {
        return array_filter((array) config('shopify.billing.plans'), fn ($p) => ($p['public'] ?? true) && ($p['active'] ?? true));
    }

    /** feature => name of the cheapest active public plan that includes it (for upgrade prompts). */
    public static function firstWith(): array
    {
        $plans = collect(self::public())->sortBy('price');
        $first = [];
        foreach (array_keys(Modules::ALL) as $feature) {
            $needs = Modules::chain($feature);
            $plan = $plans->first(fn ($p) => collect($needs)->every(fn ($k) => in_array($k, $p['includes'] ?? [], true)));
            $first[$feature] = $plan['name'] ?? null;
        }

        return $first;
    }

    /**
     * A plan's "What's included" lines with its limits filled in. {bundles} becomes the number (or
     * "Unlimited"); {bundles|bundle} becomes "1 bundle", "3 bundles" or "Unlimited bundles". A line
     * whose limit is 0 (not included) is left out. Keys are App\Services\Usage::METERS.
     *
     * @return list<string>
     */
    public static function lines(array $plan): array
    {
        $limits = (array) ($plan['limits'] ?? []);
        // A module the plan doesn't include counts as 0 (as everywhere else).
        foreach (\App\Services\Usage::FEATURE as $meter => $feature) {
            if (! collect(Modules::chain($feature))->every(fn ($k) => in_array($k, $plan['includes'] ?? [], true))) {
                $limits[$meter] = 0;
            }
        }

        return array_values(array_filter(array_map(function (string $line) use ($limits) {
            $zero = false;
            $text = preg_replace_callback('/\{([a-z_]+)(?:\|([^}]+))?\}/', function ($m) use ($limits, &$zero) {
                if (! array_key_exists($m[1], \App\Services\Usage::METERS)) {
                    return $m[0];
                }
                $limit = $limits[$m[1]] ?? null;
                if ($limit !== null && (int) $limit === 0) {
                    $zero = true;
                }
                $count = $limit === null ? 'Unlimited' : number_format((int) $limit);
                $noun = $m[2] ?? null;

                return $noun === null ? $count : $count.' '.($limit !== null && (int) $limit === 1 ? $noun : \Illuminate\Support\Str::plural($noun));
            }, $line);

            return $zero ? null : $text;
        }, (array) ($plan['features'] ?? []))));
    }
}
