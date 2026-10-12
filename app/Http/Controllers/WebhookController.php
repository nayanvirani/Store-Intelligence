<?php

namespace App\Http\Controllers;

use App\Jobs\RefreshResource;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Store;
use App\Models\Variant;
use App\Models\WebhookReceipt;
use App\Services\Shopify\Billing;
use App\Services\Sync\CatalogSync;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Shopify webhooks: verified (HMAC middleware), recorded once, answered quickly. Catalog and order
 * changes queue a re-read of the resource from Shopify, so one code path keeps the data consistent.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, Billing $billing, CatalogSync $catalog): Response
    {
        $topic = (string) $request->header('X-Shopify-Topic');
        $shop = (string) $request->header('X-Shopify-Shop-Domain');
        $webhookId = (string) $request->header('X-Shopify-Webhook-Id');

        try {
            $receipt = WebhookReceipt::create(['webhook_id' => $webhookId, 'shop_domain' => $shop, 'topic' => $topic]);
        } catch (UniqueConstraintViolationException) {
            return response()->noContent(); // Already processed; Shopify retries are idempotent.
        }

        $store = Store::where('shop_domain', $shop)->first();
        $payload = $request->json()->all();
        $live = $store && $store->isInstalled();

        match ($topic) {
            'app/uninstalled' => $this->uninstalled($store),
            'app/scopes_update' => $store?->forceFill(['scopes' => implode(',', $payload['current'] ?? [])])->save(),
            'app_subscriptions/update' => $store && $billing->applyWebhook($store, $payload),
            'products/create', 'products/update' => $live && $this->refresh($store, 'product', $payload['id'] ?? null),
            'products/delete' => $live && isset($payload['id']) && $catalog->markDeleted($store, (int) $payload['id']),
            'inventory_levels/update', 'inventory_items/update' => $live && $this->inventoryChanged($store, $payload),
            'orders/create', 'orders/updated', 'orders/cancelled', 'orders/edited' => $live && $this->refresh($store, 'order', $payload['id'] ?? $payload['order_edit']['order_id'] ?? null),
            'refunds/create' => $live && $this->refresh($store, 'order', $payload['order_id'] ?? null),
            'shop/redact' => $this->redactShop($store),
            'customers/redact' => $this->redactCustomer($store, $payload),
            'customers/data_request' => AuditLog::record("compliance.{$topic}", $store, [
                'customer_id' => $payload['customer']['id'] ?? null,
                'note' => 'Store Intelligence holds only the Shopify customer ID on this customer\'s orders (no names, emails or addresses).',
            ]),
            default => null,
        };

        $receipt->forceFill(['processed_at' => now()])->save();

        return response()->noContent();
    }

    private function refresh(Store $store, string $type, mixed $id): void
    {
        if (is_numeric($id) && (int) $id > 0) {
            RefreshResource::dispatch($store->id, $type, (int) $id);
        }
    }

    /** Stock or cost changed: re-read the product the inventory item belongs to. */
    private function inventoryChanged(Store $store, array $payload): void
    {
        $itemId = $payload['inventory_item_id'] ?? $payload['id'] ?? null;
        if (! is_numeric($itemId)) {
            return;
        }
        $productId = Variant::where('store_id', $store->id)->where('inventory_item_id', (int) $itemId)->first()?->product?->shopify_id;
        $this->refresh($store, 'product', $productId);
    }

    private function redactCustomer(?Store $store, array $payload): void
    {
        $id = (int) ($payload['customer']['id'] ?? 0);
        if ($store && $id > 0) {
            Order::where('store_id', $store->id)->where('customer_id', $id)->update(['customer_id' => null]);
        }
        AuditLog::record('compliance.customers/redact', $store, ['customer_id' => $id ?: null]);
    }

    private function uninstalled(?Store $store): void
    {
        if ($store === null) {
            return;
        }

        $store->forceFill([
            'access_token' => null,
            'refresh_token' => null,
            'access_token_expires_at' => null,
            'plan' => null,
            'uninstalled_at' => now(),
        ])->save();

        $store->subscriptions()->where('status', 'ACTIVE')->update(['status' => 'CANCELLED', 'cancelled_at' => now()]);
        $store->syncRuns()->whereIn('status', ['queued', 'running'])->update(['status' => 'failed', 'error' => 'The store uninstalled the app.', 'finished_at' => now()]);

        AuditLog::record('store.uninstalled', $store);
    }

    private function redactShop(?Store $store): void
    {
        if ($store === null) {
            return;
        }

        AuditLog::record('compliance.shop_redact', null, ['shop_domain' => $store->shop_domain]);
        $store->delete();
    }
}
