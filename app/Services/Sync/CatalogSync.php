<?php

namespace App\Services\Sync;

use App\Models\Product;
use App\Models\Store;
use App\Models\Variant;
use App\Services\Shopify\AdminApi;

/**
 * Products, variants, unit costs and inventory from Shopify (Final MVP F7). A full sync pages through
 * the catalog; webhooks refresh single products. Every change to a variant's effective cost is kept as a
 * cost snapshot so past profit never changes.
 */
class CatalogSync
{
    public const PAGE_SIZE = 50;

    private const PRODUCT_FIELDS = <<<'GQL'
        id title handle status vendor productType isGiftCard createdAt updatedAt
        featuredMedia { preview { image { url } } }
        variants(first: 100) {
          nodes {
            id title sku price compareAtPrice position inventoryQuantity updatedAt
            inventoryItem { id tracked requiresShipping unitCost { amount } }
          }
        }
        GQL;

    public function __construct(private readonly AdminApi $api) {}

    /**
     * One page of the catalog.
     *
     * @return array{count: int, cursor: ?string, seen: list<int>}
     */
    public function page(Store $store, ?string $cursor): array
    {
        $data = $this->api->graphql($store, 'query ($first: Int!, $after: String) {
            products(first: $first, after: $after, sortKey: ID) {
              nodes { '.self::PRODUCT_FIELDS.' }
              pageInfo { hasNextPage endCursor }
            }
        }', ['first' => self::PAGE_SIZE, 'after' => $cursor]);

        $seen = [];
        foreach ($data['products']['nodes'] ?? [] as $node) {
            $seen[] = $this->upsert($store, $node)->shopify_id;
        }
        $info = $data['products']['pageInfo'] ?? [];

        return ['count' => count($seen), 'cursor' => ($info['hasNextPage'] ?? false) ? $info['endCursor'] : null, 'seen' => $seen];
    }

    /** Refreshes one product (webhooks). A product Shopify no longer has is marked deleted. */
    public function refresh(Store $store, int $productId): ?Product
    {
        $data = $this->api->graphql($store, 'query ($id: ID!) { product(id: $id) { '.self::PRODUCT_FIELDS.' } }', ['id' => Gid::make('Product', $productId)]);
        if (empty($data['product'])) {
            $this->markDeleted($store, $productId);

            return null;
        }

        return $this->upsert($store, $data['product']);
    }

    public function markDeleted(Store $store, int $productId): void
    {
        $product = Product::where('store_id', $store->id)->where('shopify_id', $productId)->first();
        if ($product && $product->deleted_at === null) {
            $product->forceFill(['deleted_at' => now()])->save();
            $product->variants()->whereNull('deleted_at')->update(['deleted_at' => now()]);
        }
    }

    public function upsert(Store $store, array $node): Product
    {
        $product = Product::updateOrCreate(
            ['store_id' => $store->id, 'shopify_id' => Gid::id($node['id'])],
            [
                'title' => mb_substr((string) $node['title'], 0, 255),
                'handle' => $node['handle'] ?? null,
                'status' => $node['status'] ?? null,
                'vendor' => $node['vendor'] ?? null,
                'product_type' => $node['productType'] ?? null,
                'is_gift_card' => (bool) ($node['isGiftCard'] ?? false),
                'image_url' => $node['featuredMedia']['preview']['image']['url'] ?? null,
                'shopify_created_at' => $node['createdAt'] ?? null,
                'shopify_updated_at' => $node['updatedAt'] ?? null,
                'deleted_at' => null,
            ],
        );

        $kept = [];
        foreach ($node['variants']['nodes'] ?? [] as $v) {
            $kept[] = $this->upsertVariant($store, $product, $v)->id;
        }
        // Variants removed in Shopify.
        $product->variants()->whereNotIn('id', $kept ?: [0])->whereNull('deleted_at')->update(['deleted_at' => now()]);

        return $product;
    }

    private function upsertVariant(Store $store, Product $product, array $v): Variant
    {
        $item = $v['inventoryItem'] ?? [];
        $variant = Variant::firstOrNew(['store_id' => $store->id, 'shopify_id' => Gid::id($v['id'])]);
        $before = $variant->exists ? $variant->effectiveCost() : null;
        $wasNew = ! $variant->exists;

        $variant->fill([
            'product_id' => $product->id,
            'inventory_item_id' => isset($item['id']) ? Gid::id($item['id']) : null,
            'title' => $v['title'] ?? null,
            'sku' => ($v['sku'] ?? '') !== '' ? $v['sku'] : null,
            'price' => $v['price'] ?? null,
            'compare_at_price' => $v['compareAtPrice'] ?? null,
            'shopify_cost' => isset($item['unitCost']['amount']) ? (float) $item['unitCost']['amount'] : null,
            'inventory_quantity' => $v['inventoryQuantity'] ?? null,
            'inventory_tracked' => (bool) ($item['tracked'] ?? true),
            'requires_shipping' => (bool) ($item['requiresShipping'] ?? true),
            'position' => (int) ($v['position'] ?? 1),
            'shopify_updated_at' => $v['updatedAt'] ?? null,
            'deleted_at' => null,
        ])->save();

        $after = $variant->effectiveCost();
        if ($wasNew || $before !== $after) {
            Costs::snapshot($variant, $after, $variant->costSource() ?? 'shopify', $wasNew ? ($product->shopify_created_at ?? now()) : now());
            if ($before === null) {
                Costs::backfill($variant);
            }
        }

        return $variant;
    }
}
