@extends('admin.layout')
@section('title', 'Legal & policies')
@section('content')
<div class="ad-head">
    <div><h1>Legal &amp; policies</h1><p>Your Terms, Privacy Policy and other policies: the details they quote, and the text of each page.</p></div>
    <div class="ad-actions"><a class="ad-btn" href="{{ route('site.legal.index') }}" target="_blank" rel="noopener">View public pages ↗</a><a class="ad-btn primary" href="{{ route('admin.legal.create') }}">Add page</a></div>
</div>

@if ($missing)
    <div class="ad-note warn"><b>Fill in before launch:</b> {{ implode(', ', $missing) }}. Until then the policies use general wording in their place.</div>
@endif

<form method="POST" action="{{ route('admin.legal.details') }}" class="ad-form">
    @csrf
    <section class="ad-card">
        <header><h2>Your details</h2><span class="ad-muted">Used in every policy through placeholders such as <code>@{{operator}}</code>.</span></header>
        <p class="ad-muted" style="margin-top:-4px">Store Intelligence is run by you as an individual developer, not a company, and the policies say so. No company registration is needed.</p>
        <div class="ad-fields">
            @foreach (\App\Support\Legal::DETAILS as $key => [$label, , $help, $required])
                <label><span>{{ $label }}@if ($required)<b class="ad-req" title="Needed before launch">*</b>@endif</span>
                    <input name="{{ $key }}" value="{{ old($key, $details[$key]) }}" @if (in_array($key, ['contact_email', 'privacy_email'], true)) type="email" @endif maxlength="300">
                    @if ($help)<small>{{ $help }}</small>@endif
                </label>
            @endforeach
        </div>
        <div class="ad-actions" style="margin-top:16px"><button class="ad-btn primary" type="submit">Save details</button></div>
    </section>
</form>

<section class="ad-card flush">
    <header><h2>Pages</h2><span class="ad-muted">{{ $installed }} installed {{ \Illuminate\Support\Str::plural('store', $installed) }}</span></header>
    <div class="ad-scroll">
        <table class="ad-table">
            <thead><tr><th>Page</th><th>Status</th><th class="num">Version</th><th>Effective</th><th>Merchant review</th><th>Last edited</th></tr></thead>
            <tbody>
                @foreach ($pages as $p)
                    <tr>
                        <td><a class="ad-strong" href="{{ route('admin.legal.edit', $p->id) }}">{{ $p->title }}</a><div class="ad-muted">{{ str_replace(rtrim(config('app.url'), '/'), '', $p->url()) }}</div></td>
                        <td>
                            {!! $p->is_published ? '<span class="ad-badge ok">Live</span>' : '<span class="ad-badge">Hidden</span>' !!}
                            @if ($p->hasDraft())<span class="ad-badge warn">Draft changes</span>@endif
                            @if ($p->show_in_footer && $p->is_published)<span class="ad-badge">Footer</span>@endif
                        </td>
                        <td class="num">{{ $p->version ?: '—' }}</td>
                        <td>{{ $p->effective_at?->toFormattedDateString() ?? '—' }}</td>
                        <td>
                            @if ($p->requires_review && $p->review_version)
                                {{ $acked($p) }} of {{ $installed }} reviewed v{{ $p->review_version }}
                            @else <span class="ad-muted">Not requested</span> @endif
                        </td>
                        <td>{{ $p->updated_at?->diffForHumans() }}<div class="ad-muted">{{ $p->updated_by }}</div></td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</section>
@endsection
