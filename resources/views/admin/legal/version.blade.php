@extends('admin.layout')
@section('title', $page->title.' v'.$row->version)
@section('content')
<p class="ad-crumbs"><a href="{{ route('admin.legal') }}">Legal &amp; policies</a> / <a href="{{ route('admin.legal.edit', $page->id) }}">{{ $page->title }}</a> / Version {{ $row->version }}</p>
<div class="ad-head">
    <div><h1>{{ $row->title }} · v{{ $row->version }}</h1><p>Published {{ $row->published_at->toDayDateTimeString() }} by {{ $row->published_by }} · effective {{ $row->effective_at?->toFormattedDateString() ?? '—' }}@if ($row->change_summary) · {{ $row->change_summary }}@endif</p></div>
    <div class="ad-actions">
        @if ($row->version !== $page->version)
            <form method="POST" action="{{ route('admin.legal.restore', [$page->id, $row->version]) }}">@csrf<button class="ad-btn" type="submit">Load as draft</button></form>
        @else <span class="ad-badge ok">Live version</span> @endif
    </div>
</div>
<p class="ad-muted">Placeholders show today's details. The text is exactly as published.</p>
<section class="ad-card ad-prose">{{ $html }}</section>
@endsection
