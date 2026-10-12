<?php

namespace App\Jobs;

use App\Models\AuditLog;
use App\Models\SyncRun;
use App\Services\Shopify\ThrottledException;
use App\Services\Sync\CatalogSync;
use App\Services\Sync\OrderSync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Works through a sync run page by page. Each job runs for a short time budget, saves its cursor and
 * queues the next one, so long imports survive restarts and never block the queue.
 */
class RunSync implements ShouldQueue
{
    use Queueable;

    public const BUDGET_SECONDS = 45;

    public int $tries = 20;

    public int $timeout = 120;

    public function __construct(public int $runId) {}

    public function handle(CatalogSync $catalog, OrderSync $orders): void
    {
        $run = SyncRun::with('store')->find($this->runId);
        if ($run === null || ! in_array($run->status, ['queued', 'running'], true)) {
            return;
        }
        $store = $run->store;
        if ($store === null || ! $store->isInstalled()) {
            $run->forceFill(['status' => 'failed', 'error' => 'The store uninstalled the app.', 'finished_at' => now()])->save();

            return;
        }

        $run->forceFill(['status' => 'running', 'started_at' => $run->started_at ?? now()])->save();
        $deadline = microtime(true) + self::BUDGET_SECONDS;

        try {
            do {
                $page = $run->kind === 'products'
                    ? $catalog->page($store, $run->cursor)
                    : $orders->page($store, $run->since ?? $orders->initialSince($store), $run->cursor);
                $run->forceFill(['cursor' => $page['cursor'], 'pages' => $run->pages + 1, 'records' => $run->records + $page['count']])->save();
            } while ($page['cursor'] !== null && microtime(true) < $deadline);
        } catch (ThrottledException) {
            $this->release(10);

            return;
        } catch (Throwable $e) {
            report($e);
            if ($this->attempts() >= 3) {
                $run->forceFill(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 2000), 'finished_at' => now()])->save();
                AuditLog::record('sync.failed', $store, ['kind' => $run->kind, 'run' => $run->id]);

                return;
            }
            $this->release(30 * $this->attempts());

            return;
        }

        if ($run->cursor !== null) {
            self::dispatch($run->id);

            return;
        }

        $run->forceFill(['status' => 'done', 'finished_at' => now(), 'error' => null])->save();
        AuditLog::record('sync.finished', $store, ['kind' => $run->kind, 'records' => $run->records, 'run' => $run->id]);
    }
}
