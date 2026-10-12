<?php

namespace App\Support;

use App\Models\FeatureFlag;
use App\Models\Store;
use Illuminate\Support\Facades\Cache;

/**
 * Feature flags, managed in the Internal Admin: on for every store, or only for some.
 */
class Features
{
    public static function enabled(string $key, ?Store $store = null): bool
    {
        $flag = Cache::remember("feature-flag:{$key}", 60, fn () => FeatureFlag::where('key', $key)->first()?->only(['enabled', 'store_ids']));
        if (! $flag) {
            return false;
        }

        return $flag['enabled'] || ($store && in_array($store->id, array_map('intval', $flag['store_ids'] ?? []), true));
    }

    public static function forget(string $key): void
    {
        Cache::forget("feature-flag:{$key}");
    }
}
