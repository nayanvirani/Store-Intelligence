@extends('admin.layout')
@section('title', 'Plans & features')
@section('content')
@php
    $canManage = \App\Support\AdminRoles::can(auth()->user(), 'plans.manage');
    $catalog = \App\Support\Modules::all();
@endphp
<div class="ad-head">
    <div><h1>Plans &amp; features</h1><p>Prices, which features each plan includes and its usage limits. Changes reach every store on the plan right away: its app, storefront, checkout and discounts.</p></div>
    @if ($canManage)<div class="ad-actions"><a class="ad-btn" href="{{ route('site.pricing') }}" target="_blank" rel="noopener">View pricing page ↗</a><a class="ad-btn primary" href="{{ route('admin.plans.create') }}">Add plan</a></div>@endif
</div>
<div class="ad-note warn">
    Shopify charges what's set for each plan under <b>Managed Pricing</b> in the Partner Dashboard. When you change a price here, change it there too:
    this page sets what merchants see and what each plan unlocks.
    {{ $customized }} {{ \Illuminate\Support\Str::plural('store', $customized) }} also {{ $customized === 1 ? 'has' : 'have' }} special access set per store (Stores → Plan &amp; access).
</div>

<div class="ad-plans">
    @foreach ($plans as $plan)
        <div class="ad-plan {{ $plan->is_active ? '' : 'off' }}">
            <h3>{{ $plan->name }}
                <span>
                    @if ($plan->badge)<span class="ad-badge accent">{{ $plan->badge }}</span>@endif
                    @unless ($plan->is_active)<span class="ad-badge">Off</span>@endunless
                    @unless ($plan->is_public)<span class="ad-badge warn">Hidden</span>@endunless
                </span>
            </h3>
            <div class="price">{{ $plan->price > 0 ? '$'.number_format($plan->price, 2) : 'Free' }} @if ($plan->price > 0)<small>/ month</small>@endif</div>
            <div class="ad-muted">{{ $plan->description ?: '—' }}</div>
            <div class="ad-muted ad-small">
                {{ count(array_intersect($plan->modules ?? [], array_keys($catalog))) }} of {{ count($catalog) }} features
                · {{ $storeCounts[$plan->key] ?? 0 }} {{ \Illuminate\Support\Str::plural('store', $storeCounts[$plan->key] ?? 0) }}
                @if ($plan->support_label) · {{ $plan->support_label }} support @endif
            </div>
            <div class="ad-muted ad-small">Shopify name: <code>{{ $plan->shopify_name }}</code> · key <code>{{ $plan->key }}</code></div>
            @if ($canManage)<div><a class="ad-btn small" href="{{ route('admin.plans.edit', $plan->id) }}">Edit plan</a></div>@endif
        </div>
    @endforeach
</div>

@if ($upgrades->isNotEmpty())
    <section class="ad-card flush">
        <header><h2>What drives upgrades</h2><span class="ad-muted">Locked features and limits merchants clicked “Upgrade” from · last 90 days</span></header>
        <table class="ad-table">
            <thead><tr><th>Feature or limit</th><th class="num">Upgrade clicks</th><th class="num">Upgraded within 7 days</th><th class="num">Conversion</th></tr></thead>
            <tbody>
                @foreach ($upgrades as $row)
                    <tr>
                        <td>{{ $row->label }}</td>
                        <td class="num">{{ number_format($row->clicks) }}</td>
                        <td class="num">{{ number_format($row->converted) }}</td>
                        <td class="num">{{ $row->clicks ? round($row->converted / $row->clicks * 100) : 0 }}%</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </section>
@endif

<form method="POST" action="{{ route('admin.plans.matrix') }}">
    @csrf
    <section class="ad-card flush">
        <header><h2>Access by plan</h2><span class="ad-muted">Tick what each plan includes. Indented features also need the one above them.</span></header>
        <div class="ad-scroll">
            <table class="ad-table ad-matrix">
                <thead><tr><th>Feature</th>@foreach ($plans as $plan)<th>{{ $plan->name }}</th>@endforeach</tr></thead>
                <tbody>
                    @foreach (\App\Support\Modules::grouped() as $group => $modules)
                        <tr class="group"><td colspan="{{ count($plans) + 1 }}">{{ $group }}</td></tr>
                        @foreach ($modules as $key => [$label, , $help, $parent])
                            <tr class="{{ $parent ? 'child' : '' }}">
                                <td><b>{{ $label }}</b><small>{{ $help }}</small></td>
                                @foreach ($plans as $plan)
                                    <td><input type="checkbox" name="grid[{{ $plan->key }}][{{ $key }}]" value="1" @checked(in_array($key, $plan->modules ?? [], true)) @disabled(! $canManage) aria-label="{{ $label }} on {{ $plan->name }}"></td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                    @foreach (\App\Services\Usage::GROUPS as $limitGroup => $meters)
                        <tr class="group"><td colspan="{{ count($plans) + 1 }}">Limits · {{ $limitGroup }} <span class="ad-muted" style="text-transform:none;letter-spacing:0;font-weight:500">· empty = unlimited, 0 = none</span></td></tr>
                        @foreach ($meters as $meter)
                            @php($label = \App\Services\Usage::METERS[$meter])
                            <tr>
                                <td><b>{{ $label }}</b>@if (isset(\App\Services\Usage::HELP[$meter]))<small>{{ \App\Services\Usage::HELP[$meter] }}</small>@endif</td>
                                @foreach ($plans as $plan)
                                    @php($value = ($plan->limits ?? [])[$meter] ?? null)
                                    @php($feature = \App\Services\Usage::FEATURE[$meter] ?? null)
                                    @php($off = $feature && ! in_array($feature, $plan->modules ?? [], true))
                                    <td><input class="ad-limit" type="number" min="0" name="limits[{{ $plan->key }}][{{ $meter }}]" value="{{ $value }}" placeholder="{{ $off ? 'Off' : '∞' }}" data-plan="{{ $plan->key }}" data-feature="{{ $feature }}" @disabled(! $canManage || $off) title="{{ $off ? 'Not included: tick '.\App\Support\Modules::label($feature).' for '.$plan->name.' above' : '' }}" aria-label="{{ $label }} on {{ $plan->name }}"></td>
                                @endforeach
                            </tr>
                        @endforeach
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
    <script>
        // A limit only applies while its module is ticked for that plan.
        document.addEventListener('change', (e) => {
            const box = e.target.closest('input[type=checkbox][name^="grid["]');
            if (!box) return;
            const [, plan, feature] = box.name.match(/^grid\[([^\]]+)\]\[([^\]]+)\]$/) || [];
            document.querySelectorAll(`input.ad-limit[data-plan="${plan}"][data-feature="${feature}"]`).forEach((input) => {
                input.disabled = !box.checked;
                input.placeholder = box.checked ? '∞' : 'Off';
            });
        });
    </script>
    @if ($canManage)<div class="ad-savebar"><span class="ad-muted">Saving updates every store on these plans.</span><button class="ad-btn primary" type="submit">Save access</button></div>@endif
</form>

@if ($canManage)
    <details class="ad-card ad-collapse">
        <summary><h2>Feature names &amp; pricing page visibility</h2><span class="ad-muted">How each feature is named in the app, on the pricing page and in Billing</span></summary>
        <form method="POST" action="{{ route('admin.plans.catalog') }}">
            @csrf
            <table class="ad-table">
                <thead><tr><th>Feature</th><th>Name</th><th>Description</th><th>On pricing page</th></tr></thead>
                <tbody>
                    @foreach ($catalog as $key => [$label, $group, $help, $parent, $public])
                        <tr>
                            <td class="ad-muted ad-small">{{ $group }}<br><code>{{ $key }}</code></td>
                            <td><input name="catalog[{{ $key }}][label]" value="{{ $label }}" maxlength="80"></td>
                            <td><input name="catalog[{{ $key }}][help]" value="{{ $help }}" maxlength="200"></td>
                            <td style="text-align:center"><input type="checkbox" name="catalog[{{ $key }}][public]" value="1" @checked($public) aria-label="Show {{ $label }} on the pricing page"></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="ad-actions" style="margin-top:12px"><button class="ad-btn primary" type="submit">Save names</button></div>
        </form>
    </details>

    <details class="ad-card ad-collapse">
        <summary><h2>Pricing page text</h2><span class="ad-muted">Headline, message and notes on the public pricing page and the in-app plan picker</span></summary>
        <form method="POST" action="{{ route('admin.plans.pricing') }}" class="ad-form">
            @csrf
            <div class="ad-fields">
                @foreach (\App\Support\PricingPage::FIELDS as $key => [$label])
                    <label>{{ $label }}<input name="{{ $key }}" value="{{ old($key, $pricingPage[$key]) }}" maxlength="300"></label>
                @endforeach
            </div>
            <div class="ad-actions" style="margin-top:12px"><button class="ad-btn primary" type="submit">Save pricing page</button></div>
        </form>
    </details>
@endif
@endsection
