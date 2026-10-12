@extends('admin.layout')
@section('title', 'Failed jobs')
@section('content')
<div class="ad-head"><div><h1>Failed jobs</h1><p>Background jobs (syncs, emails, reports) that failed after all their retries. Retry one after fixing the cause.</p></div></div>
<section class="ad-card">
    <h2>Most common, 7 days</h2>
    @forelse ($byJob as $name => $n)<p><b>{{ $n }}×</b> {{ class_basename($name) }}</p>@empty<p class="ad-muted">No failures this week.</p>@endforelse
</section>
<section class="ad-card flush"><div class="ad-scroll">
<table class="ad-table">
    <thead><tr><th>Job</th><th>Queue</th><th>Error</th><th>Failed</th><th></th></tr></thead>
    <tbody>
        @forelse ($jobs as $j)
            <tr>
                <td>{{ class_basename($j->name) }}<div class="ad-muted ad-small">{{ \Illuminate\Support\Str::limit($j->uuid, 13) }}</div></td>
                <td>{{ $j->queue }}</td>
                <td class="ad-bad">{{ \Illuminate\Support\Str::limit($j->error, 180) }}</td>
                <td>{{ $j->failed_at->diffForHumans() }}</td>
                <td class="ad-actions">
                    <form method="POST" action="{{ route('admin.failures.retry', $j->id) }}">@csrf<button class="ad-btn" type="submit">Retry</button></form>
                    <form method="POST" action="{{ route('admin.failures.forget', $j->id) }}">@csrf<button class="ad-btn" type="submit">Remove</button></form>
                </td>
            </tr>
        @empty <tr><td colspan="5" class="ad-empty">No failed jobs.</td></tr> @endforelse
    </tbody>
</table>
</div></section>
{{ $jobs->links('admin._pager') }}
@endsection
