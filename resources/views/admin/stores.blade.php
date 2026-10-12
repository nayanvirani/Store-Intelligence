@extends('admin.layout')
@section('title', 'Stores')
@section('content')
<div class="ad-head">
    <div><h1>Stores</h1><p>Every store that installed Store Intelligence. Open one to change its plan, modules or limits.</p></div>
</div>
<form method="GET" class="ad-filters">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search domain or name">
    <select name="status">
        @foreach (['' => 'All stores', 'installed' => 'Installed', 'uninstalled' => 'Uninstalled', 'custom' => 'Special access'] as $k => $l)<option value="{{ $k }}" @selected(request('status', '') === $k)>{{ $l }}</option>@endforeach
    </select>
    <select name="plan">
        <option value="">Any plan</option>
        @foreach ($plans as $k => $name)<option value="{{ $k }}" @selected(request('plan') === $k)>{{ $name }}</option>@endforeach
        <option value="none" @selected(request('plan') === 'none')>No subscription</option>
    </select>
    <button class="ad-btn" type="submit">Filter</button>
    @if (request()->hasAny(['q', 'status', 'plan']))<a href="{{ route('admin.stores') }}" class="ad-small">Clear</a>@endif
</form>
<section class="ad-card flush">
    <div class="ad-scroll">
        <table class="ad-table">
            <thead><tr><th>Store</th><th>Plan & access</th><th>Status</th><th class="num">Orders</th><th>Last sync</th><th>Installed</th></tr></thead>
            <tbody>
                @forelse ($stores as $s)
                    <tr>
                        <td><a class="ad-strong" href="{{ route('admin.store', $s->id) }}">{{ $s->name ?? '—' }}</a><div class="ad-muted">{{ $s->shop_domain }}{{ $s->shopify_plan ? ' · '.$s->shopify_plan : '' }}</div></td>
                        <td>@include('admin._plan', ['store' => $s])</td>
                        <td>{!! $s->isInstalled() ? '<span class="ad-badge ok"><i class="ad-dot"></i>Installed</span>' : '<span class="ad-badge bad">Uninstalled</span>' !!}</td>
                        <td class="num">{{ number_format($orders[$s->id] ?? 0) }}</td>
                        <td>@if (isset($failedSync[$s->id]))<span class="ad-badge bad">Failed</span>@elseif (! empty($lastSync[$s->id])){{ \Illuminate\Support\Carbon::parse($lastSync[$s->id])->diffForHumans() }}@else<span class="ad-muted">—</span>@endif</td>
                        <td>{{ $s->installed_at?->toFormattedDateString() ?? '—' }}</td>
                    </tr>
                @empty <tr><td colspan="6" class="ad-empty">No stores match.</td></tr> @endforelse
            </tbody>
        </table>
    </div>
</section>
<div class="ad-pager"><span>{{ $stores->total() }} {{ \Illuminate\Support\Str::plural('store', $stores->total()) }}</span>{{ $stores->links('admin._pager') }}</div>
@endsection
