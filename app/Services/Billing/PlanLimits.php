<?php

namespace App\Services\Billing;

use App\Models\Store;

/**
 * Keeps a store within its plan after any change to it. Items over a limit are paused and
 * marked (never deleted) and come back on their own when the plan allows them again.
 * Each module registers its own rule as it is built (e.g. scheduled posts, monitored URLs).
 */
class PlanLimits
{
    /** @var list<callable(Store): int> */
    private static array $rules = [];

    /** A rule returns how many items it paused or resumed. */
    public static function register(callable $rule): void
    {
        self::$rules[] = $rule;
    }

    /**
     * @return int how many items changed
     */
    public function apply(Store $store): int
    {
        $changes = 0;
        foreach (self::$rules as $rule) {
            $changes += (int) $rule($store);
        }

        return $changes;
    }
}
