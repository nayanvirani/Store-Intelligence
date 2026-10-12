<?php

namespace App\Services\Sync;

use App\Jobs\RunSync;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\SyncRun;
use Illuminate\Support\Carbon;

/**
 * Starts and schedules syncs (Final MVP F7): a first import after install, a daily catalog pass and
 * an hourly order reconciliation that catches anything a webhook missed.
 */
class SyncManager
{
    public const KINDS = ['products', 'orders'];

    /** Starts a sync unless the same kind is already queued or running for the store. */
    public function start(Store $store, string $kind, string $trigger = 'manual', ?Carbon $since = null): SyncRun
    {
        $active = SyncRun::where('store_id', $store->id)->where('kind', $kind)->whereIn('status', ['queued', 'running'])
            ->where('updated_at', '>=', now()->subMinutes(30))->latest('id')->first();
        if ($active) {
            return $active;
        }

        $run = SyncRun::create([
            'store_id' => $store->id, 'kind' => $kind, 'trigger' => $trigger, 'status' => 'queued',
            'since' => $kind === 'orders' ? ($since ?? $this->ordersSince($store)) : null,
        ]);
        RunSync::dispatch($run->id);
        AuditLog::record('sync.started', $store, ['kind' => $kind, 'trigger' => $trigger, 'run' => $run->id]);

        return $run;
    }

    /** The first import, once per install. Products go first so orders can find their costs. */
    public function startInitial(Store $store): void
    {
        if (! SyncRun::where('store_id', $store->id)->where('created_at', '>=', $store->installed_at ?? now()->subYear())->exists()) {
            $this->start($store, 'products', 'initial');
            $this->start($store, 'orders', 'initial');
        }
    }

    /** Orders: since the last finished run (with an hour of overlap), or the full history window. */
    public function ordersSince(Store $store): Carbon
    {
        $last = SyncRun::where('store_id', $store->id)->where('kind', 'orders')->where('status', 'done')->latest('started_at')->first();

        return $last?->started_at ? $last->started_at->copy()->subHour() : app(OrderSync::class)->initialSince($store);
    }

    /** The scheduler's pass over installed stores. */
    public function scheduled(string $kind): int
    {
        $started = 0;
        Store::whereNotNull('installed_at')->whereNull('uninstalled_at')->whereNotNull('access_token')->lazyById(100)
            ->each(function (Store $store) use ($kind, &$started) {
                $this->start($store, $kind, 'scheduled');
                $started++;
            });

        return $started;
    }

    /** Status for the merchant and admin: last run of each kind. */
    public function status(Store $store): array
    {
        return collect(self::KINDS)->mapWithKeys(function ($kind) use ($store) {
            $last = SyncRun::where('store_id', $store->id)->where('kind', $kind)->latest('id')->first();
            $done = SyncRun::where('store_id', $store->id)->where('kind', $kind)->where('status', 'done')->latest('finished_at')->first();

            return [$kind => [
                'status' => $last?->status ?? 'never',
                'records' => $last?->records,
                'error' => $last?->status === 'failed' ? $last->error : null,
                'last_success_at' => $done?->finished_at,
                'running' => (bool) $last?->isActive(),
            ]];
        })->all();
    }
}
