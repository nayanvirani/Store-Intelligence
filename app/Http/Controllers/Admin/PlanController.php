<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Plan;
use App\Models\Store;
use App\Services\Usage;
use App\Support\Modules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Plans and features: prices, usage limits, which features each plan includes and what the
 * pricing pages say. Plans are never deleted (stores may be on them): turn them off.
 * Every change reaches the stores on the plan straight away.
 */
class PlanController extends Controller
{
    public function index(): View
    {
        $plans = Plan::orderBy('position')->orderBy('id')->get();
        $stores = Store::whereNotNull('installed_at')->whereNull('uninstalled_at')->get();

        $upgrades = \App\Models\UpgradeEvent::where('created_at', '>=', now()->subDays(90))
            ->selectRaw('feature, count(*) as clicks, count(converted_at) as converted')->groupBy('feature')->orderByDesc('clicks')->get()
            ->each(fn ($row) => $row->label = \App\Models\UpgradeEvent::label($row->feature));

        return view('admin.plans.index', [
            'upgrades' => $upgrades,
            'pricingPage' => \App\Support\PricingPage::get(),
            'plans' => $plans,
            'storeCounts' => $stores->groupBy(fn ($s) => $s->effectivePlan() ?? 'none')->map->count(),
            'customized' => $stores->filter(fn ($s) => ! empty($s->entitlements))->count(),
        ]);
    }

    public function create(): View
    {
        return view('admin.plans.edit', ['plan' => new Plan(['modules' => array_keys(Modules::ALL), 'limits' => [], 'features' => [], 'is_public' => true, 'is_active' => true, 'position' => Plan::max('position') + 1])]);
    }

    public function edit(int $plan): View
    {
        return view('admin.plans.edit', ['plan' => Plan::findOrFail($plan)]);
    }

    public function store(Request $request): RedirectResponse
    {
        $plan = new Plan;
        $this->fill($plan, $request, true)->save();
        AuditLog::record('admin.plan_created', null, ['plan' => $plan->key, 'by' => $request->user()->email]);

        return redirect()->route('admin.plans')->with('status', "Plan “{$plan->name}” created. Add a plan with the same name to Managed Pricing in the Partner Dashboard so Shopify can charge it.");
    }

    public function update(Request $request, int $plan): RedirectResponse
    {
        $plan = Plan::findOrFail($plan);
        $before = $plan->only(['price', 'modules', 'limits', 'is_active', 'is_public']);
        $this->fill($plan, $request, false)->save();
        AuditLog::record('admin.plan_updated', null, ['plan' => $plan->key, 'before' => $before, 'after' => $plan->only(array_keys($before)), 'by' => $request->user()->email]);
        $this->applyToStores();

        return redirect()->route('admin.plans')->with('status', "Plan “{$plan->name}” saved.");
    }

    /** The plan × feature (and plan × limit) grid on the Plans page. */
    public function matrix(Request $request): RedirectResponse
    {
        $grid = (array) $request->input('grid', []);
        $limitsIn = $request->has('limits') ? (array) $request->input('limits', []) : null;
        $request->validate(['limits.*.*' => ['nullable', 'integer', 'min:0', 'max:100000000']]);
        foreach (Plan::all() as $plan) {
            $changes = [];
            $modules = array_values(array_intersect(array_keys(Modules::ALL), array_keys(array_filter((array) ($grid[$plan->key] ?? [])))));
            if ($modules !== array_values($plan->modules ?? [])) {
                $changes['modules'] = $modules;
            }
            if ($limitsIn !== null) {
                $limits = [];
                foreach (array_keys(Usage::METERS) as $meter) {
                    // Limits of modules that are off aren't sent (the field is disabled): keep them.
                    if (! array_key_exists($meter, (array) ($limitsIn[$plan->key] ?? []))) {
                        $limits[$meter] = ($plan->limits ?? [])[$meter] ?? null;

                        continue;
                    }
                    $value = $limitsIn[$plan->key][$meter];
                    $limits[$meter] = $value === null || $value === '' ? null : (int) $value;
                }
                if ($limits != ($plan->limits ?? [])) {
                    $changes['limits'] = $limits;
                }
            }
            if ($changes) {
                $plan->forceFill($changes)->save();
                AuditLog::record('admin.plan_access_changed', null, ['plan' => $plan->key] + $changes + ['by' => $request->user()->email]);
            }
        }

        $this->applyToStores();

        return redirect()->route('admin.plans')->with('status', 'Features saved. Stores on these plans are updated now.');
    }

    public function pricingPage(Request $request): RedirectResponse
    {
        $rules = collect(\App\Support\PricingPage::FIELDS)->map(fn () => ['nullable', 'string', 'max:300'])->all();
        \App\Support\PricingPage::save($request->validate($rules));
        AuditLog::record('admin.pricing_page_saved', null, ['by' => $request->user()->email]);

        return redirect()->route('admin.plans')->with('status', 'Pricing page text saved.');
    }

    /** Feature names, descriptions and which ones the pricing pages show. */
    public function catalog(Request $request): RedirectResponse
    {
        $data = $request->validate(['catalog' => ['array'], 'catalog.*.label' => ['nullable', 'string', 'max:80'], 'catalog.*.help' => ['nullable', 'string', 'max:200']]);
        $overrides = [];
        foreach (Modules::ALL as $key => [$label, , $help]) {
            $row = $data['catalog'][$key] ?? [];
            $overrides[$key] = array_filter([
                'label' => ($v = trim((string) ($row['label'] ?? ''))) !== '' && $v !== $label ? $v : null,
                'help' => ($v = trim((string) ($row['help'] ?? ''))) !== '' && $v !== $help ? $v : null,
                'public' => $request->boolean("catalog.{$key}.public") ? null : false,
            ], fn ($v) => $v !== null);
        }
        Modules::saveOverrides(array_filter($overrides));
        AuditLog::record('admin.feature_catalog_saved', null, ['by' => $request->user()->email]);

        return redirect()->route('admin.plans')->with('status', 'Feature names saved. The app, the pricing page and Billing use them now.');
    }

    /** Stores follow the plans right after an edit (and every few minutes anyway). */
    private function applyToStores(): void
    {
        defer(fn () => \Illuminate\Support\Facades\Artisan::call('si:apply-entitlements'));
    }

    private function fill(Plan $plan, Request $request, bool $creating): Plan
    {
        $data = $request->validate([
            'key' => $creating ? ['required', 'regex:/^[a-z0-9_]{2,40}$/', Rule::unique('plans', 'key')] : ['prohibited'],
            'name' => ['required', 'string', 'max:80'],
            'shopify_name' => ['required', 'string', 'max:120'],
            'price' => ['required', 'numeric', 'min:0', 'max:100000'],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'badge' => ['nullable', 'string', 'max:40'],
            'support_label' => ['nullable', 'string', 'max:80'],
            'modules' => ['array'], 'modules.*' => [Rule::in(array_keys(Modules::ALL))],
            'limits' => ['array'], 'limits.*' => ['nullable', 'integer', 'min:0'],
            'features' => ['nullable', 'string', 'max:4000'],
            'description' => ['nullable', 'string', 'max:300'],
            'position' => ['nullable', 'integer', 'min:0', 'max:1000'],
        ]);
        if ($creating) {
            $plan->key = $data['key'];
        }
        $limits = [];
        foreach (array_keys(Usage::METERS) as $meter) {
            $value = $data['limits'][$meter] ?? null;
            $limits[$meter] = $value === null || $value === '' ? null : (int) $value;
        }

        return $plan->fill([
            'name' => $data['name'], 'shopify_name' => $data['shopify_name'], 'price' => $data['price'],
            'trial_days' => (int) ($data['trial_days'] ?? 0), 'badge' => $data['badge'] ?? null, 'support_label' => $data['support_label'] ?? null,
            'modules' => array_values($data['modules'] ?? []), 'limits' => $limits,
            'features' => array_values(array_filter(array_map('trim', preg_split('/\R/', (string) ($data['features'] ?? ''))))),
            'description' => $data['description'] ?? null, 'position' => (int) ($data['position'] ?? $plan->position ?? 0),
            'is_public' => $request->boolean('is_public'), 'is_active' => $request->boolean('is_active'),
        ]);
    }
}
