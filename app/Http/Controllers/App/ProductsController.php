<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\OrderLine;
use App\Models\Store;
use App\Models\Variant;
use App\Services\Sync\Costs;
use App\Support\Spa\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Products & costs (Final MVP F15): every variant with its cost, missing costs first by sales impact,
 * and inline cost entry. Costs entered here are kept in the app; Shopify's cost is never changed.
 */
class ProductsController extends Controller
{
    public const FILTERS = ['missing' => 'Missing cost', 'all' => 'All variants', 'app' => 'Cost set in app'];

    public function index(Request $request, Store $store): Page
    {
        $filter = array_key_exists((string) $request->query('filter'), self::FILTERS) ? (string) $request->query('filter') : 'missing';
        $q = trim((string) $request->query('q'));

        // Units sold in the last 30 days, to sort by sales impact.
        $sold = OrderLine::query()->join('orders', 'orders.id', '=', 'order_lines.order_id')
            ->where('order_lines.store_id', $store->id)->whereNull('orders.cancelled_at')
            ->where('orders.processed_at', '>=', now()->subDays(30))
            ->groupBy('order_lines.variant_shopify_id')
            ->select('order_lines.variant_shopify_id', DB::raw('sum(order_lines.quantity) as units'), DB::raw('sum(order_lines.price * order_lines.quantity - order_lines.discount) as revenue'));

        $variants = Variant::query()->with('product:id,title,image_url,status,shopify_id')
            ->where('variants.store_id', $store->id)->whereNull('variants.deleted_at')
            ->leftJoinSub($sold, 'sold', 'sold.variant_shopify_id', '=', 'variants.shopify_id')
            ->when($filter === 'missing', fn ($w) => $w->whereNull('variants.app_cost')->whereNull('variants.shopify_cost'))
            ->when($filter === 'app', fn ($w) => $w->whereNotNull('variants.app_cost'))
            ->when($q !== '', fn ($w) => $w->where(fn ($w) => $w->where('variants.sku', 'like', "%{$q}%")
                ->orWhereHas('product', fn ($p) => $p->where('title', 'like', "%{$q}%"))))
            ->select('variants.*', DB::raw('coalesce(sold.units, 0) as units_30d'), DB::raw('coalesce(sold.revenue, 0) as revenue_30d'))
            ->orderByDesc('revenue_30d')->orderBy('variants.id')
            ->paginate(50)->withQueryString();

        $total = Variant::where('store_id', $store->id)->whereNull('deleted_at')->count();
        $withCost = Variant::where('store_id', $store->id)->whereNull('deleted_at')->where(fn ($w) => $w->whereNotNull('app_cost')->orWhereNotNull('shopify_cost'))->count();

        return page('products/index', [
            'filter' => $filter,
            'filters' => self::FILTERS,
            'q' => $q,
            'currency' => $store->currency ?: 'USD',
            'coverage' => ['total' => $total, 'with_cost' => $withCost, 'percent' => $total ? round($withCost / $total * 100, 1) : null],
            'variants' => [
                'data' => collect($variants->items())->map(fn (Variant $v) => [
                    'id' => $v->id,
                    'product_id' => $v->product_id,
                    'product' => $v->product?->title,
                    'image' => $v->product?->image_url,
                    'status' => $v->product?->status,
                    'title' => $v->title === 'Default Title' ? null : $v->title,
                    'sku' => $v->sku,
                    'price' => $v->price,
                    'shopify_cost' => $v->shopify_cost,
                    'app_cost' => $v->app_cost,
                    'cost' => $v->effectiveCost(),
                    'source' => $v->costSource(),
                    'margin' => $v->effectiveCost() !== null && (float) $v->price > 0 ? round(((float) $v->price - $v->effectiveCost()) / (float) $v->price * 100, 1) : null,
                    'inventory' => $v->inventory_tracked ? $v->inventory_quantity : null,
                    'units_30d' => (int) $v->units_30d,
                    'revenue_30d' => round((float) $v->revenue_30d, 2),
                    'shopify_url' => $v->product ? $store->adminUrl('products/'.$v->product->shopify_id) : null,
                ])->all(),
                'page' => $variants->currentPage(),
                'last_page' => $variants->lastPage(),
                'total' => $variants->total(),
            ],
        ]);
    }

    /** One product: last-30-day results, variants with cost, margin and days of stock, and cost history. */
    public function show(Store $store, int $product, \App\Services\Profit\Breakdowns $breakdowns): Page
    {
        $product = \App\Models\Product::where('store_id', $store->id)->with(['variants' => fn ($q) => $q->whereNull('deleted_at')->orderBy('position')])->findOrFail($product);
        $tz = $store->timezone ?: 'UTC';
        $now = $breakdowns->products($store, now($tz)->subDays(29), now($tz))->firstWhere('product_shopify_id', $product->shopify_id);
        $prev = $breakdowns->products($store, now($tz)->subDays(59), now($tz)->subDays(30))->firstWhere('product_shopify_id', $product->shopify_id);
        $sold14 = \App\Services\Profit\Breakdowns::unitsSold($store, 14);
        $sold30 = \App\Services\Profit\Breakdowns::unitsSold($store, 30);
        $ids = $product->variants->pluck('id');

        $history = \App\Models\CostSnapshot::whereIn('variant_id', $ids)->orderByDesc('effective_from')->orderByDesc('id')->limit(30)->get()
            ->map(fn ($c) => ['variant_id' => $c->variant_id, 'cost' => $c->cost === null ? null : (float) $c->cost, 'source' => $c->source, 'at' => $c->effective_from?->toIso8601String()]);
        $changes = class_exists(\App\Models\VariantChange::class)
            ? \App\Models\VariantChange::whereIn('variant_id', $ids)->orderByDesc('observed_at')->limit(30)->get()
                ->map(fn ($c) => ['variant_id' => $c->variant_id, 'field' => $c->field, 'before' => $c->before, 'after' => $c->after, 'at' => $c->observed_at?->toIso8601String()])->all()
            : [];
        $round = fn ($row) => $row ? array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, $row) : null;

        return page('products/show', [
            'currency' => $store->currency ?: 'USD',
            'product' => [
                'id' => $product->id, 'title' => $product->title, 'image' => $product->image_url, 'status' => $product->status,
                'vendor' => $product->vendor, 'type' => $product->product_type,
                'shopify_url' => $store->adminUrl('products/'.$product->shopify_id),
            ],
            'stats' => $round($now),
            'previous' => $round($prev),
            'variants' => $product->variants->map(fn (Variant $v) => [
                'id' => $v->id,
                'title' => $v->title === 'Default Title' ? 'Default' : $v->title,
                'sku' => $v->sku,
                'price' => $v->price === null ? null : (float) $v->price,
                'compare_at_price' => $v->compare_at_price === null ? null : (float) $v->compare_at_price,
                'cost' => $v->effectiveCost(),
                'app_cost' => $v->app_cost === null ? null : (float) $v->app_cost,
                'shopify_cost' => $v->shopify_cost === null ? null : (float) $v->shopify_cost,
                'source' => $v->costSource(),
                'margin' => $v->effectiveCost() !== null && (float) $v->price > 0 ? round(((float) $v->price - $v->effectiveCost()) / (float) $v->price * 100, 1) : null,
                'inventory' => $v->inventory_tracked ? $v->inventory_quantity : null,
                'sold_30d' => (int) ($sold30[$v->shopify_id] ?? 0),
                'days_of_stock_14' => $v->inventory_tracked ? \App\Services\Profit\Breakdowns::daysOfStock($v->inventory_quantity, $sold14[$v->shopify_id] ?? 0, 14) : null,
                'days_of_stock_30' => $v->inventory_tracked ? \App\Services\Profit\Breakdowns::daysOfStock($v->inventory_quantity, $sold30[$v->shopify_id] ?? 0, 30) : null,
            ])->all(),
            'costHistory' => $history->all(),
            'changes' => $changes,
        ]);
    }

    /** Product insights (F33): ranked lists with their rules. */
    public function insights(Store $store, \App\Services\Profit\ProductInsights $insights): Page
    {
        if (! $store->planIncludes('product_insights')) {
            return page('products/insights', ['locked' => 'product_insights', 'currency' => $store->currency ?: 'USD']);
        }
        $lists = $insights->lists($store);
        $meta = $lists['_meta'];
        unset($lists['_meta']);

        return page('products/insights', ['locked' => null, 'currency' => $store->currency ?: 'USD', 'lists' => $lists, 'averageMargin' => $meta['average_margin']]);
    }

    public function updateCost(Request $request, Store $store, int $variant): RedirectResponse
    {
        $variant = Variant::where('store_id', $store->id)->findOrFail($variant);
        $data = $request->validate(['cost' => ['nullable', 'numeric', 'min:0', 'max:9999999']]);
        $cost = isset($data['cost']) && $data['cost'] !== '' ? round((float) $data['cost'], 4) : null;

        $before = $variant->app_cost;
        Costs::setAppCost($variant, $cost);
        AuditLog::record('cost.updated', $store, ['variant' => $variant->sku ?: $variant->shopify_id, 'from' => $before, 'to' => $cost], $variant);

        if ($request->input('back') === 'product') {
            return redirect()->to(app_route('app.products.show', ['product' => $variant->product_id, 'notice' => 'cost_saved']));
        }

        // Back to the same list (filter, search and page are sent with the form).
        return redirect()->to(app_route('app.products.index', array_filter([
            'filter' => $request->input('filter'), 'q' => $request->input('q'), 'page' => $request->input('page'), 'notice' => 'cost_saved',
        ])));
    }
}
