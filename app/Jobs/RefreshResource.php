<?php

namespace App\Jobs;

use App\Models\Store;
use App\Services\Shopify\ThrottledException;
use App\Services\Sync\CatalogSync;
use App\Services\Sync\OrderSync;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Re-reads one product or order from Shopify after a webhook. Repeated webhooks for the same
 * resource within a short window are handled once.
 */
class RefreshResource implements ShouldQueue, ShouldBeUnique
{
    use Queueable;

    public int $tries = 5;

    public int $uniqueFor = 30;

    public function __construct(public int $storeId, public string $type, public int $id) {}

    public function uniqueId(): string
    {
        return "{$this->storeId}:{$this->type}:{$this->id}";
    }

    public function handle(CatalogSync $catalog, OrderSync $orders): void
    {
        $store = Store::find($this->storeId);
        if ($store === null || ! $store->isInstalled()) {
            return;
        }

        try {
            match ($this->type) {
                'product' => $catalog->refresh($store, $this->id),
                'order' => $orders->refresh($store, $this->id),
            };
        } catch (ThrottledException) {
            $this->release(15);
        }
    }

    public function backoff(): array
    {
        return [10, 30, 60, 120];
    }
}
