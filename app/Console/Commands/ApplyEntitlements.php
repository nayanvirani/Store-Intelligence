<?php

namespace App\Console\Commands;

use App\Models\Store;
use App\Services\Billing\Entitlements;
use Illuminate\Console\Command;

/**
 * Re-applies each store's plan, features and limits when they changed: plan edits in the
 * super admin, store overrides, a complimentary plan ending.
 */
class ApplyEntitlements extends Command
{
    protected $signature = 'si:apply-entitlements {--store= : One store id} {--force : Re-apply even when nothing changed}';

    protected $description = 'Apply plan features and limits where they changed';

    public function handle(Entitlements $entitlements): int
    {
        $applied = 0;
        Store::whereNotNull('installed_at')->whereNull('uninstalled_at')
            ->when($this->option('store'), fn ($q, $id) => $q->whereKey($id))
            ->lazyById(100)
            ->each(function (Store $store) use ($entitlements, &$applied) {
                $applied += $entitlements->apply($store, (bool) $this->option('force')) ? 1 : 0;
            });
        $this->info("Applied to {$applied} ".\Illuminate\Support\Str::plural('store', $applied).'.');

        return self::SUCCESS;
    }
}
