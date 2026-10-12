@extends('admin.layout')
@section('title', 'Dashboard')
@section('content')
@php($money = fn ($v) => '$'.number_format((float) $v, 2))
<div class="ad-head">
    <div><h1>Dashboard</h1><p>Installs, revenue and anything that needs attention.</p></div>
    <div class="ad-actions"><a class="ad-btn" href="{{ route('admin.stores') }}">All stores</a>@if (\App\Support\AdminRoles::can(auth()->user(), 'plans'))<a class="ad-btn dark" href="{{ route('admin.plans') }}">Plans & modules</a>@endif</div>
</div>

<div class="ad-kpis">
    <div><small>Installed stores</small><b>{{ number_format($kpis['installed']) }}</b><span>+{{ $kpis['new_30d'] }} new · −{{ $kpis['uninstalled_30d'] }} uninstalled (30 days)</span></div>
    <div><small>Monthly recurring revenue</small><b>{{ $money($kpis['mrr']) }}</b><span>{{ $kpis['paying'] }} paying {{ \Illuminate\Support\Str::plural('store', $kpis['paying']) }}</span></div>
    <div><small>Stores with special access</small><b>{{ $kpis['custom'] }}</b><span><a href="{{ route('admin.stores', ['status' => 'custom']) }}">Complimentary plans and overrides</a></span></div>
    <div><small>Upgrade clicks · 30 days</small><b>{{ number_format($kpis['upgrade_clicks_30d']) }}</b><span>{{ $kpis['upgrades_30d'] }} led to an upgrade · <a href="{{ route('admin.plans') }}">What drives them</a></span></div>
    <div class="{{ $kpis['open_tickets'] ? 'alert' : '' }}"><small>Open tickets</small><b>{{ $kpis['open_tickets'] }}</b><span><a href="{{ route('admin.tickets') }}">Support desk</a></span></div>
    <div class="{{ $kpis['failed_syncs_24h'] ? 'alert' : '' }}"><small>Failed syncs · 24 h</small><b>{{ $kpis['failed_syncs_24h'] }}</b><span>{{ $kpis['syncing'] }} running · <a href="{{ route('admin.sync') }}">Data sync</a></span></div>
    <div class="{{ $kpis['failed_jobs_24h'] ? 'alert' : '' }}"><small>Failed jobs · 24 h</small><b>{{ $kpis['failed_jobs_24h'] }}</b><span>{{ number_format($kpis['queue']) }} in the queue · <a href="{{ route('admin.failures') }}">View</a></span></div>
    <div class="{{ $kpis['new_messages'] ? 'alert' : '' }}"><small>Website messages</small><b>{{ $kpis['new_messages'] }}</b><span><a href="{{ route('admin.messages') }}">New contact-form messages</a></span></div>
    <div class="{{ $kpis['webhooks_unprocessed'] ? 'alert' : '' }}"><small>Unprocessed webhooks · 24 h</small><b>{{ $kpis['webhooks_unprocessed'] }}</b><span>Should stay at 0</span></div>
</div>

<div class="ad-grid-2">
    <div>
        <section class="ad-card">
            <header><h2>Installs · last 30 days</h2><span class="ad-muted">{{ $installsByDay->sum() }} total</span></header>
            @php($max = max(1, $installsByDay->max()))
            <div class="ad-bars">@foreach ($installsByDay as $day => $n)<span title="{{ \Illuminate\Support\Carbon::parse($day)->toFormattedDateString() }}: {{ $n }}"><i style="height:{{ $n / $max * 100 }}%"></i></span>@endforeach</div>
        </section>
        <section class="ad-card flush">
            <header><h2>Recent installs</h2><a class="ad-small" href="{{ route('admin.stores') }}">View all</a></header>
            <table class="ad-table">
                <thead><tr><th>Store</th><th>Plan</th><th>Installed</th></tr></thead>
                <tbody>
                    @forelse ($recentInstalls as $s)
                        <tr>
                            <td><a class="ad-strong" href="{{ route('admin.store', $s->id) }}">{{ $s->name ?? $s->shop_domain }}</a><div class="ad-muted">{{ $s->shop_domain }}</div></td>
                            <td>@include('admin._plan', ['store' => $s])</td>
                            <td>{{ $s->installed_at?->diffForHumans() }}@if ($s->uninstalled_at) <span class="ad-badge bad">Uninstalled</span>@endif</td>
                        </tr>
                    @empty <tr><td colspan="3" class="ad-empty">No installs yet.</td></tr> @endforelse
                </tbody>
            </table>
        </section>
    </div>
    <div>
        <section class="ad-card">
            <header><h2>Plan mix</h2>@if (\App\Support\AdminRoles::can(auth()->user(), 'plans'))<a class="ad-small" href="{{ route('admin.plans') }}">Edit plans</a>@endif</header>
            @php($mixTotal = max(1, collect($planMix)->sum('stores') + $noPlan))
            <div class="ad-mix">
                @foreach ($planMix as $key => $p)
                    <div>
                        <p><span>{{ $p['name'] }}</span><span class="ad-num"><b>{{ $p['stores'] }}</b> <span class="ad-muted">· {{ $money($p['mrr']) }}/mo</span></span></p>
                        <div class="ad-meter"><i style="width:{{ $p['stores'] / $mixTotal * 100 }}%"></i></div>
                    </div>
                @endforeach
                <div>
                    <p><span class="ad-muted">No plan chosen</span><b class="ad-num">{{ $noPlan }}</b></p>
                    <div class="ad-meter"><i style="width:{{ $noPlan / $mixTotal * 100 }}%;background:#8A95A5"></i></div>
                </div>
            </div>
        </section>
        <section class="ad-card">
            <header><h2>Tickets waiting for us</h2><a class="ad-small" href="{{ route('admin.tickets') }}">Support desk</a></header>
            <ul class="ad-list">
                @forelse ($waiting as $t)
                    <li><span><a href="{{ route('admin.ticket', $t->id) }}">{{ $t->subject }}</a><div class="ad-muted">{{ $t->store->shop_domain }} · {{ $t->last_reply_at?->diffForHumans() }}</div></span><span class="ad-badge {{ in_array($t->priority, ['urgent', 'high'], true) ? 'bad' : '' }}">{{ ucfirst($t->priority) }}</span></li>
                @empty <li class="ad-muted">Nothing waiting.</li> @endforelse
            </ul>
        </section>
    </div>
</div>
@endsection
