@extends('admin.layout')
@section('title', 'Email delivery log')
@section('content')
<p class="ad-crumbs"><a href="{{ route('admin.email') }}">Email providers</a> / Delivery log</p>
<div class="ad-head"><div><h1>Delivery log</h1><p>Every attempt to send through a provider. A failed attempt is usually followed by a sent one through the next provider.</p></div></div>
<form class="ad-filters" method="GET">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search email or subject">
    <select name="status"><option value="">All results</option>@foreach (['sent' => 'Sent', 'failed' => 'Failed'] as $k => $v)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $v }}</option>@endforeach</select>
    <select name="provider"><option value="">All providers</option>@foreach ($providers as $id => $name)<option value="{{ $id }}" @selected((string) request('provider') === (string) $id)>{{ $name }}</option>@endforeach</select>
    <button class="ad-btn" type="submit">Filter</button>
    @if (request()->hasAny(['q', 'status', 'provider']))<a href="{{ route('admin.email.log') }}" class="ad-small">Clear</a>@endif
</form>
<section class="ad-card flush">
    <div class="ad-scroll">
        <table class="ad-table">
            <thead><tr><th>When</th><th>To</th><th>Subject</th><th>Provider</th><th>Result</th></tr></thead>
            <tbody>
                @forelse ($deliveries as $d)
                    <tr>
                        <td>{{ \Illuminate\Support\Carbon::parse($d->created_at)->diffForHumans() }}<div class="ad-muted">{{ $d->category }}</div></td>
                        <td>{{ $d->to_email }}</td>
                        <td>{{ \Illuminate\Support\Str::limit($d->subject, 60) }}</td>
                        <td>{{ $d->provider_name ?? '—' }}</td>
                        <td>
                            {!! $d->status === 'sent' ? '<span class="ad-badge ok">Sent</span>' : '<span class="ad-badge bad">Failed</span>' !!}
                            @if ($d->error)<div class="ad-muted ad-small ad-clip" title="{{ $d->error }}">{{ \Illuminate\Support\Str::limit($d->error, 120) }}</div>@endif
                        </td>
                    </tr>
                @empty <tr><td colspan="5" class="ad-empty">Nothing sent yet.</td></tr> @endforelse
            </tbody>
        </table>
    </div>
</section>
<div class="ad-pager"><span>{{ $deliveries->total() }} {{ \Illuminate\Support\Str::plural('attempt', $deliveries->total()) }}</span>{{ $deliveries->links('admin._pager') }}</div>
@endsection
