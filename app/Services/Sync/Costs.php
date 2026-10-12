<?php

namespace App\Services\Sync;

use App\Models\CostSnapshot;
use App\Models\Variant;
use Illuminate\Support\Carbon;

/**
 * Unit costs over time (Final MVP F12/F15). Profit for an order uses the cost that was valid when the
 * order was placed, so later cost changes never rewrite history.
 */
class Costs
{
    public static function snapshot(Variant $variant, ?float $cost, string $source, $effectiveFrom = null): void
    {
        CostSnapshot::create([
            'store_id' => $variant->store_id,
            'variant_id' => $variant->id,
            'cost' => $cost,
            'source' => $source,
            'effective_from' => $effectiveFrom ?? now(),
        ]);
    }

    /** The unit cost valid at a moment, or null when none was recorded. */
    public static function at(Variant $variant, Carbon $moment): ?float
    {
        $snapshot = CostSnapshot::where('variant_id', $variant->id)->where('effective_from', '<=', $moment)
            ->orderByDesc('effective_from')->orderByDesc('id')->first();
        if ($snapshot !== null) {
            return $snapshot->cost === null ? null : (float) $snapshot->cost;
        }
        // Orders older than the first snapshot use the earliest cost we know.
        $first = CostSnapshot::where('variant_id', $variant->id)->orderBy('effective_from')->orderBy('id')->first();

        return $first?->cost === null ? $variant->effectiveCost() : (float) $first->cost;
    }

    /** The merchant sets (or clears) a variant's cost in the app; Shopify's cost is never changed. */
    public static function setAppCost(Variant $variant, ?float $cost): void
    {
        $before = $variant->effectiveCost();
        $variant->forceFill(['app_cost' => $cost])->save();
        if ($before !== $variant->effectiveCost()) {
            self::snapshot($variant, $variant->effectiveCost(), $cost === null ? 'shopify' : 'app');
        }
        self::backfill($variant);
    }

    /** A cost recorded for a product that had none fills past orders without a cost; known costs stay. */
    public static function backfill(Variant $variant): void
    {
        if ($variant->effectiveCost() !== null) {
            \App\Models\OrderLine::where('store_id', $variant->store_id)->where('variant_shopify_id', $variant->shopify_id)
                ->whereNull('unit_cost')->update(['unit_cost' => $variant->effectiveCost()]);
        }
    }
}
