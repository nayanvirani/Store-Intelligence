@extends('admin.layout')
@section('title', 'Data sync')
@section('content')
<div class="ad-head"><div><h1>Data sync</h1><p>Product and order imports for every store. Runs resume page by page and retry on their own.</p></div></div>
<div class="ad-kpis">
    @foreach (['queued' => 'Queued', 'running' => 'Running', 'done' => 'Finished', 'failed' => 'Failed'] as $k => $label)
        <div class="{{ $k === 'failed' && ($counts[$k] ?? 0) ? 'alert' : '' }}"><small>{{ $label }} · 24 h</small><b>{{ number_format($counts[$k] ?? 0) }}</b><span><a href="{{ route('admin.sync', ['status' => $k]) }}">Show</a></span></div>
    @endforeach
    <div><small>Jobs in the queue</small><b>{{ number_format($queue) }}</b><span>Should drain within minutes</span></div>
</div>
<form method="GET" class="ad-filters">
    <select name="status"><option value="">Any status</option>@foreach (['queued', 'running', 'done', 'failed'] as $s)<option value="{{ $s }}" @selected($status === $s)>{{ ucfirst($s) }}</option>@endforeach</select>
    <select name="kind"><option value="">Any kind</option>@foreach (['products', 'orders'] as $k)<option value="{{ $k }}" @selected(request('kind') === $k)>{{ ucfirst($k) }}</option>@endforeach</select>
    <button class="ad-btn" type="submit">Filter</button>
</form>
<section class="ad-card flush"><div class="ad-scroll">
<table class="ad-table">
    <thead><tr><th>Run</th><th>Store</th><th>Kind</th><th>Status</th><th class="num">Pages</th><th class="num">Records</th><th>Started</th><th>Finished</th></tr></thead>
    <tbody>
        @forelse ($runs as $r)
            <tr>
                <td>#{{ $r->id }}<div class="ad-muted ad-small">{{ $r->trigger }}</div></td>
                <td>@if ($r->store)<a href="{{ route('admin.store', $r->store_id) }}">{{ $r->store->shop_domain }}</a>@else — @endif</td>
                <td>{{ $r->kind }}@if ($r->since)<div class="ad-muted ad-small">since {{ $r->since->toFormattedDateString() }}</div>@endif</td>
                <td><span class="ad-badge {{ $r->status === 'failed' ? 'bad' : ($r->status === 'done' ? 'ok' : '') }}">{{ $r->status }}</span>@if ($r->error)<div class="ad-bad ad-small">{{ \Illuminate\Support\Str::limit($r->error, 160) }}</div>@endif</td>
                <td class="num">{{ $r->pages }}</td>
                <td class="num">{{ number_format($r->records) }}</td>
                <td>{{ $r->started_at?->diffForHumans() ?? '—' }}</td>
                <td>{{ $r->finished_at?->diffForHumans() ?? '—' }}</td>
            </tr>
        @empty <tr><td colspan="8" class="ad-empty">No sync runs.</td></tr> @endforelse
    </tbody>
</table>
</div></section>
{{ $runs->links('admin._pager') }}
@endsection
