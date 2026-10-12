<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/** Platform email settings from the super admin: on/off, how providers are chosen, default sender. */
class EmailSettings
{
    private const KEY = 'email-settings:v1';

    public const STRATEGIES = [
        'priority' => ['In priority order', 'Use the first provider until it reaches its limit or fails, then the next.'],
        'balance' => ['Spread across providers', 'Send each email through the provider with the most of its free allowance left, so all of them are used.'],
    ];

    public static function get(): array
    {
        $saved = Cache::rememberForever(self::KEY, function () {
            try {
                $value = DB::table('platform_settings')->where('key', 'email')->value('value');
            } catch (Throwable) {
                return [];
            }

            return $value ? (array) json_decode($value, true) : [];
        });

        return array_merge(['enabled' => true, 'strategy' => 'priority', 'from_email' => '', 'from_name' => 'Store Intelligence', 'reply_to' => ''], $saved);
    }

    public static function save(array $values): void
    {
        DB::table('platform_settings')->updateOrInsert(['key' => 'email'], ['value' => json_encode($values), 'updated_at' => now(), 'created_at' => now()]);
        Cache::forget(self::KEY);
    }
}
