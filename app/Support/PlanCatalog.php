<?php

namespace App\Support;

use App\Services\Usage;

/**
 * Plan cards and the plan comparison, built from the plans, features and limits edited in the
 * super admin. Used by the public pricing page and the in-app Billing page, so both always
 * match what each plan actually unlocks.
 */
class PlanCatalog
{
    /** @return list<array{key: string, name: string, price: float, description: ?string, badge: ?string, support: ?string, features: list<string>}> */
    public static function cards(): array
    {
        return collect(Plans::public())->map(fn ($p, $key) => [
            'key' => $key, 'name' => $p['name'], 'price' => (float) $p['price'], 'description' => $p['description'] ?? null,
            'badge' => $p['badge'] ?? null, 'support' => $p['support'] ?? null, 'features' => Plans::lines($p),
        ])->values()->all();
    }

    /**
     * Rows grouped like the catalog. Each value is true / false for a feature, a number or
     * "Unlimited" for a limit, or text for support.
     *
     * @return list<array{group: string, rows: list<array{label: string, help: ?string, values: array<string, bool|string>}>}>
     */
    public static function compare(): array
    {
        $plans = Plans::public();
        $includes = fn (array $plan, string $key) => collect(Modules::chain($key))->every(fn ($k) => in_array($k, $plan['includes'] ?? [], true));
        $groups = [];

        foreach (Usage::GROUPS as $group => $meters) {
            $groups[] = ['group' => 'Limits · '.$group, 'rows' => collect($meters)->map(fn ($meter) => [
                'label' => Usage::METERS[$meter], 'help' => Usage::HELP[$meter] ?? null,
                'values' => collect($plans)->map(function ($p) use ($meter, $includes) {
                    if (($feature = Usage::FEATURE[$meter] ?? null) && ! $includes($p, $feature)) {
                        return false;
                    }
                    $limit = ($p['limits'] ?? [])[$meter] ?? null;

                    return $limit === null ? 'Unlimited' : ((int) $limit === 0 ? false : number_format((int) $limit));
                })->all(),
            ])->all()];
        }

        $support = collect($plans)->map(fn ($p) => $p['support'] ?? null);
        foreach (Modules::grouped() as $group => $features) {
            // With support levels set per plan, the support row below says it better than a tick.
            $rows = collect($features)->filter(fn ($m, $key) => $m[4] && ! ($key === 'priority_support' && $support->filter()->isNotEmpty()))->map(fn ($m, $key) => [
                'label' => $m[0], 'help' => $m[2],
                'values' => collect($plans)->map(fn ($p) => $includes($p, $key))->all(),
            ])->values()->all();
            if ($rows !== []) {
                $groups[] = ['group' => $group, 'rows' => $rows];
            }
        }

        if ($support->filter()->isNotEmpty()) {
            $groups[] = ['group' => 'Support', 'rows' => [['label' => 'Support', 'help' => null, 'values' => $support->map(fn ($s) => $s ?: false)->all()]]];
        }

        return $groups;
    }
}
