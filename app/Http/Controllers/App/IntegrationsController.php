<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Store;
use App\Models\WebhookReceipt;
use App\Support\Spa\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Settings → Integrations (Final MVP F6: connected accounts and Shopify webhooks) and
 * Settings → Privacy (AI processing, export, policies).
 */
class IntegrationsController extends Controller
{
    /** Webhook topics the app subscribes to (shopify.app.toml). */
    public const TOPICS = [
        'app/uninstalled' => 'App uninstalled', 'app/scopes_update' => 'Permissions changed', 'app_subscriptions/update' => 'Plan changes',
        'products/create' => 'Products created', 'products/update' => 'Products updated', 'products/delete' => 'Products deleted',
        'inventory_levels/update' => 'Stock levels', 'inventory_items/update' => 'Product costs',
        'orders/create' => 'Orders created', 'orders/updated' => 'Orders updated', 'orders/cancelled' => 'Orders cancelled', 'refunds/create' => 'Refunds',
        'customers/data_request' => 'Customer data requests', 'customers/redact' => 'Customer data deletion', 'shop/redact' => 'Store data deletion',
    ];

    /** Connected accounts (F6). Availability is managed per provider with feature flags in the super admin. */
    public const PROVIDERS = [
        'google_ads' => ['Google Ads', 'Ads', 'Spend, clicks, conversions and ROAS by campaign.'],
        'meta_ads' => ['Meta Ads', 'Ads', 'Spend, reach, purchases and creatives from Facebook and Instagram ads.'],
        'ga4' => ['Google Analytics 4', 'Analytics', 'Sessions and traffic sources for conversion rate.'],
        'facebook' => ['Facebook Pages', 'Social', 'Publish posts and see page performance.'],
        'instagram' => ['Instagram', 'Social', 'Publish posts and see post performance.'],
        'pinterest' => ['Pinterest', 'Social', 'Create pins and see pin performance.'],
        'google_business' => ['Google Business Profile', 'Social', 'Publish updates and offers.'],
        'growvia' => ['Growvia', 'Apps', 'Conversion data and one-click fixes from Growvia.'],
    ];

    public function integrations(Store $store): Page
    {
        $receipts = WebhookReceipt::where('shop_domain', $store->shop_domain)->where('created_at', '>=', now()->subDays(30))
            ->selectRaw('topic, max(created_at) as last_at, count(*) as n, sum(case when processed_at is null then 1 else 0 end) as unprocessed')
            ->groupBy('topic')->get()->keyBy('topic');

        return page('settings/integrations', [
            'shopify' => ['connected' => $store->isInstalled() && ! $store->missingScopes(), 'shop' => $store->shop_domain],
            'providers' => collect(self::PROVIDERS)->map(fn ($p, $key) => [
                'key' => $key, 'name' => $p[0], 'group' => $p[1], 'help' => $p[2],
                'available' => \App\Support\Features::enabled('integration.'.$key, $store),
                'status' => 'not_connected',
            ])->values(),
            'webhooks' => collect(self::TOPICS)->map(fn ($label, $topic) => [
                'topic' => $topic, 'label' => $label,
                'received' => (int) ($receipts[$topic]->n ?? 0),
                'last_at' => isset($receipts[$topic]) ? Carbon::parse($receipts[$topic]->last_at) : null,
                'unprocessed' => (int) ($receipts[$topic]->unprocessed ?? 0),
            ])->values(),
        ]);
    }

    public function privacy(Store $store): Page
    {
        return page('settings/privacy', [
            'privacy' => collect(array_keys(Store::PRIVACY_DEFAULTS))->mapWithKeys(fn ($k) => [$k => $store->privacy($k)])->all(),
            'data' => ['orders' => $store->orders()->count(), 'products' => $store->products()->count()],
            'policies' => collect(\App\Support\Legal::pages())->map(fn ($p) => [
                'title' => $p['title'], 'url' => \App\Support\Legal::url($p['slug']), 'version' => $p['version'],
                'accepted' => $store->legal_acks[$p['slug']] ?? null,
            ])->values()->all(),
        ]);
    }

    public function updatePrivacy(Request $request, Store $store): RedirectResponse
    {
        $privacy = ['ai_enabled' => $request->boolean('ai_enabled')];
        $store->forceFill(['privacy' => $privacy])->save();
        AuditLog::record('settings.privacy_updated', $store, $privacy);

        return redirect()->to(app_route('app.settings.privacy', ['notice' => 'saved']));
    }

    /** Orders as CSV: what the app holds for this store (no customer personal data is stored). */
    public function export(Store $store): StreamedResponse
    {
        AuditLog::record('settings.data_exported', $store);

        return response()->streamDownload(function () use ($store) {
            $out = fopen('php://output', 'w');
            $columns = ['name', 'processed_at', 'cancelled_at', 'test', 'currency', 'financial_status', 'source_name', 'utm_source', 'utm_medium', 'utm_campaign',
                'gross_sales', 'total_discounts', 'subtotal', 'total_shipping', 'total_tax', 'total_price', 'total_refunded', 'units', 'payment_gateway'];
            fputcsv($out, $columns);
            $store->orders()->orderBy('processed_at')->select($columns)->chunk(2000, function ($rows) use ($out) {
                foreach ($rows as $row) {
                    fputcsv($out, array_map(fn ($v) => $v instanceof \DateTimeInterface ? $v->format('c') : $v, array_values($row->getAttributes())));
                }
            });
            fclose($out);
        }, 'store-intelligence-orders-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }
}
