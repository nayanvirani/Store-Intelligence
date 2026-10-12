<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\CostRule;
use App\Models\Store;
use App\Support\Spa\Page;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Settings → Costs & fees (Final MVP F3): payment fees, shipping, handling, fixed monthly costs and
 * other ad spend. Every change recalculates profit (it is computed from these rules on demand).
 */
class CostRulesController extends Controller
{
    /** Common payment fee presets (editable after adding). */
    public const PRESETS = [
        ['name' => 'Shopify Payments (US online)', 'percent' => 2.9, 'fixed' => 0.30, 'gateway' => 'shopify_payments'],
        ['name' => 'PayPal (US)', 'percent' => 3.49, 'fixed' => 0.49, 'gateway' => 'paypal'],
        ['name' => 'Stripe (standard)', 'percent' => 2.9, 'fixed' => 0.30, 'gateway' => 'stripe'],
    ];

    public function index(Store $store): Page
    {
        $gateways = $store->orders()->whereNotNull('payment_gateway')->where('processed_at', '>=', now()->subDays(90))
            ->distinct()->orderBy('payment_gateway')->limit(20)->pluck('payment_gateway');

        return page('settings/costs', [
            'currency' => $store->currency ?: 'USD',
            'types' => CostRule::TYPES,
            'rules' => $store->costRules()->orderBy('type')->orderBy('id')->get()->map(fn (CostRule $r) => [
                'id' => $r->id, 'type' => $r->type, 'name' => $r->name, 'config' => $r->config, 'active' => $r->active,
                'summary' => $r->describe($store->currency ?: 'USD'),
            ]),
            'presets' => self::PRESETS,
            'gateways' => $gateways,
        ]);
    }

    public function store(Request $request, Store $store): RedirectResponse
    {
        $rule = new CostRule(['store_id' => $store->id, 'active' => true]);
        $this->fill($rule, $request);
        $rule->save();
        AuditLog::record('cost_rule.created', $store, ['type' => $rule->type, 'name' => $rule->name], $rule);

        return redirect()->to(app_route('app.settings.costs', ['notice' => 'saved']));
    }

    public function update(Request $request, Store $store, int $rule): RedirectResponse
    {
        $rule = CostRule::where('store_id', $store->id)->findOrFail($rule);
        $this->fill($rule, $request);
        $rule->active = $request->boolean('active', true);
        $rule->save();
        AuditLog::record('cost_rule.updated', $store, ['type' => $rule->type, 'name' => $rule->name, 'active' => $rule->active], $rule);

        return redirect()->to(app_route('app.settings.costs', ['notice' => 'saved']));
    }

    public function destroy(Store $store, int $rule): RedirectResponse
    {
        $rule = CostRule::where('store_id', $store->id)->findOrFail($rule);
        AuditLog::record('cost_rule.deleted', $store, ['type' => $rule->type, 'name' => $rule->name]);
        $rule->delete();

        return redirect()->to(app_route('app.settings.costs', ['notice' => 'deleted']));
    }

    private function fill(CostRule $rule, Request $request): void
    {
        $type = $rule->exists ? $rule->type : (string) $request->input('type');
        $base = $request->validate([
            'type' => [$rule->exists ? 'nullable' : 'required', 'in:'.implode(',', array_keys(CostRule::TYPES))],
            'name' => ['required', 'string', 'max:120'],
        ]);
        $config = match ($type) {
            'payment_fee' => $request->validate([
                'config.percent' => ['required', 'numeric', 'min:0', 'max:30'],
                'config.fixed' => ['required', 'numeric', 'min:0', 'max:100'],
                'config.gateway' => ['nullable', 'string', 'max:60'],
            ])['config'],
            'shipping' => $request->validate([
                'config.amount' => ['required', 'numeric', 'min:0', 'max:100000'],
                'config.basis' => ['required', 'in:order,item'],
            ])['config'],
            'handling', 'fixed_cost' => $request->validate(['config.amount' => ['required', 'numeric', 'min:0', 'max:10000000']])['config'],
            'ad_spend' => $request->validate([
                'config.amount' => ['required', 'numeric', 'min:0', 'max:10000000'],
                'config.channel' => ['nullable', 'string', 'max:60'],
            ])['config'],
        };

        $rule->fill(['type' => $type, 'name' => $base['name'], 'config' => array_map(fn ($v) => is_numeric($v) ? (float) $v : $v, $config)]);
    }
}
