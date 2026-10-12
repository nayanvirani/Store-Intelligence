@extends('admin.layout')
@section('title', $store->shop_domain)
@section('content')
@php
    $canManage = \App\Support\AdminRoles::can(auth()->user(), 'stores.manage');
    $e = $store->entitlements ?? [];
    $planKey = $store->effectivePlan();
    $subscribed = $store->plan;
    $planModules = (array) ($plans[$planKey]['includes'] ?? []);
    $money = fn ($v) => $v === null ? 'Unlimited' : '$'.number_format((float) $v);
@endphp
<p class="ad-crumbs"><a href="{{ route('admin.stores') }}">Stores</a> / {{ $store->shop_domain }}</p>
<div class="ad-head">
    <div>
        <h1>{{ $store->name ?? $store->shop_domain }}</h1>
        <p>{{ $store->shop_domain }} · @include('admin._plan') {!! $store->isInstalled() ? '<span class="ad-badge ok"><i class="ad-dot"></i>Installed</span>' : '<span class="ad-badge bad">Uninstalled</span>' !!}</p>
    </div>
    <div class="ad-actions">
        <a class="ad-btn" href="{{ $store->adminUrl() }}" target="_blank" rel="noopener">Open Shopify admin ↗</a>
        <a class="ad-btn" href="https://{{ $store->shop_domain }}" target="_blank" rel="noopener">Storefront ↗</a>
    </div>
</div>

<nav class="ad-tabs">
    @foreach (['overview' => 'Overview', 'access' => 'Plan & access', 'activity' => 'Activity'] as $k => $l)
        <a href="{{ route('admin.store', ['store' => $store->id, 'tab' => $k]) }}" @if ($tab === $k) aria-current="page" @endif>{{ $l }}</a>
    @endforeach
</nav>

@if ($tab === 'overview')
    <div class="ad-kpis">
        <div><small>Plan</small><b style="font-size:20px">{{ $planKey ? ($plans[$planKey]['name'] ?? $planKey) : 'None' }}</b><span>{{ $store->compPlan() ? 'Complimentary'.(! empty($e['plan_until']) ? ' until '.\Illuminate\Support\Carbon::parse($e['plan_until'])->toFormattedDateString() : '') : ($subscription ? ($subscription->status ?? '').' subscription' : 'No subscription') }}</span></div>
        <div><small>Features</small><b style="font-size:20px">{{ count(array_filter(array_keys(\App\Support\Modules::ALL), fn ($k) => $store->planIncludes($k))) }} / {{ count(\App\Support\Modules::ALL) }}</b><span>{{ $usageNow->filter(fn ($m) => $m['limit'] !== null && $m['used'] >= $m['limit'])->count() }} limits reached</span></div>
        <div><small>Orders synced</small><b style="font-size:20px">{{ number_format($data['orders']) }}</b><span>Oldest {{ $data['oldest_order'] ? \Illuminate\Support\Carbon::parse($data['oldest_order'])->toFormattedDateString() : '—' }}</span></div>
        <div class="{{ $data['missing_costs'] ? 'alert' : '' }}"><small>Products</small><b style="font-size:20px">{{ number_format($data['products']) }}</b><span>{{ number_format($data['variants']) }} variants · {{ number_format($data['missing_costs']) }} missing cost</span></div>
    </div>
    <div class="ad-grid">
        <section class="ad-card">
            <h2>Store</h2>
            <dl class="ad-kv">
                <dt>Installed</dt><dd>{{ $store->installed_at?->toDayDateTimeString() ?? '—' }}</dd>
                @if ($store->uninstalled_at)<dt>Uninstalled</dt><dd class="ad-bad">{{ $store->uninstalled_at->toDayDateTimeString() }}</dd>@endif
                <dt>Shopify plan</dt><dd>{{ $store->shopify_plan ?? '—' }}</dd>
                <dt>Subscription</dt><dd>{{ $subscription ? ($subscription->name ?? $subscription->plan).' · '.$subscription->status.($subscription->test ? ' · test charge' : '') : '—' }}</dd>
                <dt>Currency · timezone</dt><dd>{{ $store->currency }} · {{ $store->timezone }}</dd>
                <dt>Country</dt><dd>{{ $store->country ?? '—' }}</dd>
                <dt>Onboarding</dt><dd>{{ $store->onboarding_completed_at ? 'Completed '.$store->onboarding_completed_at->toFormattedDateString() : 'Not finished' }}</dd>
                <dt>Cost rules</dt><dd>{{ $data['cost_rules'] }}</dd>
                <dt>Terms & policies</dt><dd>
                    @php($acks = (array) ($store->legal_acks ?? []))
                    @if (isset($acks['terms']))
                        Terms v{{ $acks['terms']['version'] }}, Privacy v{{ $acks['privacy']['version'] ?? '—' }}
                        <div class="ad-muted">{{ ($acks['terms']['via'] ?? '') === 'install' ? 'Accepted at install' : 'Last reviewed in the app' }} {{ \Illuminate\Support\Carbon::parse($acks['terms']['at'])->toFormattedDateString() }}{{ ! empty($acks['terms']['by']) ? ' by '.$acks['terms']['by'] : '' }}</div>
                    @else <span class="ad-muted">Installed before versions were recorded</span> @endif
                </dd>
            </dl>
        </section>
        <section class="ad-card">
            <h2>Health</h2>
            <dl class="ad-kv">
                @foreach (['products' => 'Product sync', 'orders' => 'Order sync'] as $kind => $label)
                    @php($st = $syncStatus[$kind])
                    <dt>{{ $label }}</dt><dd>{!! $st['running'] ? '<span class="ad-badge">Running</span>' : ($st['status'] === 'failed' ? '<span class="ad-badge bad">Failed</span>' : ($st['status'] === 'done' ? '<span class="ad-badge ok">OK</span>' : '<span class="ad-badge warn">Never</span>')) !!} <span class="ad-muted">{{ $st['last_success_at']?->diffForHumans() }}</span></dd>
                @endforeach
                <dt>Full order history</dt><dd>@include('admin._yesno', ['value' => $store->hasScope('read_all_orders')])</dd>
                <dt>Customer IDs on orders</dt><dd>@include('admin._yesno', ['value' => $store->capability('orders_customer') !== false])</dd>
                <dt>Visit source on orders</dt><dd>@include('admin._yesno', ['value' => $store->capability('orders_journey') !== false])</dd>
                <dt>Missing scopes</dt><dd>{{ implode(', ', $store->missingScopes()) ?: 'None' }}</dd>
                <dt>Capabilities checked</dt><dd>{{ $store->capabilities_checked_at?->diffForHumans() ?? 'never' }}</dd>
            </dl>
        </section>
    </div>
    @if ($canManage)
        <section class="ad-card">
            <header><h2>Support actions</h2><span class="ad-muted">Each one is recorded in the audit log.</span></header>
            <div class="ad-actions">
                @foreach (['refresh-store' => 'Refresh store details', 'sync-billing' => 'Sync subscription from Shopify', 'apply-access' => 'Re-apply plan and limits', 'sync-products' => 'Sync products now', 'sync-orders' => 'Sync orders now'] as $action => $label)
                    <form method="POST" action="{{ route('admin.store.action', ['store' => $store->id, 'action' => $action]) }}">@csrf<button class="ad-btn" type="submit">{{ $label }}</button></form>
                @endforeach
            </div>
        </section>
    @endif
    <div class="ad-grid">
        <section class="ad-card flush">
            <header><h2>Sync runs</h2><a class="ad-small" href="{{ route('admin.sync') }}">All stores</a></header>
            <table class="ad-table"><thead><tr><th>Run</th><th>Kind</th><th>Status</th><th class="num">Records</th><th>Started</th></tr></thead><tbody>
                @forelse ($syncRuns as $r)<tr><td>#{{ $r->id }} <span class="ad-muted">{{ $r->trigger }}</span></td><td>{{ $r->kind }}</td><td><span class="ad-badge {{ $r->status === 'failed' ? 'bad' : ($r->status === 'done' ? 'ok' : '') }}">{{ $r->status }}</span>@if ($r->error)<div class="ad-bad ad-small">{{ \Illuminate\Support\Str::limit($r->error, 160) }}</div>@endif</td><td class="num">{{ number_format($r->records) }}</td><td>{{ ($r->started_at ?? $r->created_at)->diffForHumans() }}</td></tr>@empty<tr><td colspan="5" class="ad-empty">No syncs yet</td></tr>@endforelse
            </tbody></table>
        </section>
    </div>
    <div class="ad-grid">
        <section class="ad-card flush">
            <header><h2>Staff</h2></header>
            <table class="ad-table"><thead><tr><th>Person</th><th>Role</th><th>Last active</th></tr></thead><tbody>
                @forelse ($users as $u)<tr><td>{{ $u->displayName() }}<div class="ad-muted">{{ $u->email }}</div></td><td>{{ ucfirst($u->role) }}{{ $u->disabled_at ? ' · removed' : '' }}</td><td>{{ $u->last_active_at?->diffForHumans() ?? '—' }}</td></tr>@empty<tr><td colspan="3" class="ad-empty">None</td></tr>@endforelse
            </tbody></table>
        </section>
        <section class="ad-card">
            <h2>Support tickets</h2>
            <ul class="ad-list">
                @forelse ($tickets as $t)<li><a href="{{ route('admin.ticket', $t->id) }}">{{ $t->reference() }} · {{ $t->subject }}</a><span class="ad-badge">{{ $t->status }}</span></li>@empty<li class="ad-muted">None.</li>@endforelse
            </ul>
        </section>
    </div>
@endif

@if ($tab === 'access')
    <div class="ad-note">
        What this store gets: its subscription plan (or a complimentary plan you set here), with features and limits adjusted below.
        Changes apply at once; anything over a lower limit is paused, never deleted.
    </div>
    <form method="POST" action="{{ route('admin.store.access', $store->id) }}" class="ad-form">
        @csrf
        <fieldset @disabled(! $canManage) style="border:0;padding:0;margin:0;display:contents">
        <section class="ad-card">
            <header><h2>Plan</h2></header>
            <div class="ad-fields">
                <div class="ad-field">Subscription (from Shopify)
                    <div>{{ $subscribed ? ($plans[$subscribed]['name'] ?? $subscribed) : 'None' }}{{ $store->isTestShop() && ! $subscribed ? ' · test store ('.($plans[config('shopify.test_shop_plan')]['name'] ?? '').')' : '' }}</div>
                    <small>Merchants change this in Shopify. A complimentary plan below overrides it while it lasts.</small>
                </div>
                <label>Complimentary plan
                    <select name="plan">
                        <option value="">None: use the subscription</option>
                        @foreach ($plans as $k => $p)<option value="{{ $k }}" @selected(($e['plan'] ?? '') === $k)>{{ $p['name'] }} (${{ number_format($p['price'], 2) }}/mo)</option>@endforeach
                    </select>
                    <small>Free access to a plan without charging the store.</small>
                </label>
                <label>Until (optional)
                    <input type="date" name="plan_until" value="{{ $e['plan_until'] ?? '' }}" min="{{ now()->toDateString() }}">
                    <small>Leave empty to keep it until you remove it.</small>
                </label>
            </div>
        </section>

        <section class="ad-card">
            <header><h2>Features</h2><span class="ad-muted">Plan default follows {{ $planKey ? ($plans[$planKey]['name'] ?? $planKey) : 'no plan' }}; On or Off overrides it for this store only.</span></header>
            @foreach (\App\Support\Modules::grouped() as $group => $modules)
                <p class="ad-group-title">{{ $group }}</p>
                <div class="ad-modules">
                    @foreach ($modules as $key => [$label, , $help, $parent])
                        @php($state = in_array($key, $e['modules_on'] ?? [], true) ? 'on' : (in_array($key, $e['modules_off'] ?? [], true) ? 'off' : 'default'))
                        <div class="ad-module {{ $parent ? 'child' : '' }}">
                            <span><b>{{ $label }}</b><span class="ad-muted">{{ $help }}</span></span>
                            <span>{!! $store->planIncludes($key) ? '<span class="ad-badge ok">Has access</span>' : '<span class="ad-badge">No access</span>' !!}</span>
                            <span class="ad-tri" role="radiogroup" aria-label="{{ $label }}">
                                <label><input type="radio" name="modules[{{ $key }}]" value="default" @checked($state === 'default')><span>Plan default · {{ in_array($key, $planModules, true) ? 'on' : 'off' }}</span></label>
                                <label><input type="radio" name="modules[{{ $key }}]" value="on" @checked($state === 'on')><span class="on">On</span></label>
                                <label><input type="radio" name="modules[{{ $key }}]" value="off" @checked($state === 'off')><span class="off">Off</span></label>
                            </span>
                        </div>
                    @endforeach
                </div>
            @endforeach
        </section>

        <section class="ad-card flush">
            <header><h2>Limits</h2><span class="ad-muted">Blank plan limit means unlimited.</span></header>
            <div class="ad-scroll">
                <table class="ad-table">
                    <thead><tr><th>Limit</th><th class="num">Using now</th><th class="num">Plan limit</th><th>For this store</th></tr></thead>
                    <tbody>
                        @php($planLimits = (array) ($plans[$planKey]['limits'] ?? []))
                        @foreach ($meters as $meter => $label)
                            @php($has = array_key_exists($meter, $e['limits'] ?? []))
                            @php($mode = ! $has ? 'default' : (($e['limits'][$meter] ?? null) === null ? 'unlimited' : 'value'))
                            <tr>
                                <td>{{ $label }}</td>
                                <td class="num">{{ number_format($usageNow[$meter]['used'] ?? 0) }}</td>
                                <td class="num">{{ array_key_exists($meter, $planLimits) && $planLimits[$meter] !== null ? number_format($planLimits[$meter]) : 'Unlimited' }}</td>
                                <td>
                                    <span class="ad-actions">
                                        <select name="limits[{{ $meter }}][mode]">
                                            <option value="default" @selected($mode === 'default')>Plan limit</option>
                                            <option value="value" @selected($mode === 'value')>Custom</option>
                                            <option value="unlimited" @selected($mode === 'unlimited')>Unlimited</option>
                                        </select>
                                        <input type="number" name="limits[{{ $meter }}][value]" min="0" style="width:120px" value="{{ $mode === 'value' ? $e['limits'][$meter] : '' }}" placeholder="Custom">
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>

        <section class="ad-card">
            <label>Internal note
                <textarea name="note" rows="3" maxlength="1000" placeholder="Why this store has special access (only the team sees this).">{{ $e['note'] ?? '' }}</textarea>
            </label>
        </section>
        </fieldset>
        @if ($canManage)
            <div class="ad-savebar">
                <a class="ad-btn" href="{{ route('admin.store', ['store' => $store->id, 'tab' => 'access']) }}">Discard</a>
                <button class="ad-btn primary" type="submit">Save access</button>
            </div>
        @else
            <p class="ad-muted">Your role can view access but not change it.</p>
        @endif
    </form>
@endif

@if ($tab === 'activity')
    <div class="ad-grid">
        <section class="ad-card flush">
            <header><h2>Audit log</h2><a class="ad-small" href="{{ route('admin.audit', ['store' => $store->shop_domain]) }}">Full log</a></header>
            <table class="ad-table"><thead><tr><th>Action</th><th>When</th></tr></thead><tbody>
                @forelse ($audit as $a)<tr><td><code>{{ $a->action }}</code></td><td>{{ $a->created_at->diffForHumans() }}</td></tr>@empty<tr><td colspan="2" class="ad-empty">None</td></tr>@endforelse
            </tbody></table>
        </section>
        <section class="ad-card flush">
            <header><h2>Recent webhooks</h2></header>
            <table class="ad-table"><thead><tr><th>Topic</th><th>Received</th><th>Processed</th></tr></thead><tbody>
                @forelse ($webhooks as $w)<tr><td>{{ $w->topic }}</td><td>{{ $w->created_at->diffForHumans() }}</td><td>{!! $w->processed_at ? '<span class="ad-badge ok">Yes</span>' : '<span class="ad-badge bad">No</span>' !!}</td></tr>@empty<tr><td colspan="3" class="ad-empty">None</td></tr>@endforelse
            </tbody></table>
        </section>
    </div>
    <section class="ad-card flush">
        <header><h2>Usage records</h2></header>
        <table class="ad-table"><thead><tr><th>Meter</th><th>Value</th><th>Period</th></tr></thead><tbody>
            @forelse ($usage as $u)<tr><td>{{ $u->meter ?? $u->metric ?? '—' }}</td><td>{{ $u->quantity ?? $u->count ?? $u->value ?? '—' }}</td><td>{{ $u->period_start ?? $u->created_at }}</td></tr>@empty<tr><td colspan="3" class="ad-empty">None</td></tr>@endforelse
        </tbody></table>
    </section>
@endif
@endsection
