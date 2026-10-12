@extends('layouts.site')
@section('title', $page->title.' | '.(\App\Support\Legal::details()['trading_name'] ?: 'Store Intelligence'))
@push('head')
<style>
    .legal { display:grid; grid-template-columns: 240px 1fr; gap: 40px; }
    .legal aside { position: sticky; top: 90px; align-self: start; font-size: 14px; }
    .legal aside ul, .legal aside ol { list-style: none; padding: 0; margin: 0 0 20px; }
    .legal aside li { margin: 6px 0; }
    .legal aside a { color: var(--muted); text-decoration: none; }
    .legal aside a[aria-current] { color: var(--ink); font-weight: 700; }
    .legal-body h2 { font-size: 22px; margin-top: 34px; }
    .legal-body p, .legal-body li { color: #334155; }
    .legal-table { overflow-x: auto; }
    .legal-body table { border-collapse: collapse; width: 100%; font-size: 14px; }
    .legal-body th, .legal-body td { border: 1px solid var(--line); padding: 8px 10px; text-align: left; vertical-align: top; }
    @media (max-width: 860px) { .legal { grid-template-columns: 1fr; } .legal aside { position: static; } }
</style>
@endpush
@section('content')
<section class="section" style="padding-top:64px">
    <div class="wrap">
        <p class="small"><a href="{{ route('site.legal.index') }}">Legal</a> / {{ $page->title }}</p>
        <h1 style="font-size:clamp(30px,4vw,44px)">{{ $page->title }}</h1>
        <p class="muted small">Effective {{ $effective ?? '—' }} · Version {{ $page->version }}</p>
        <div class="legal" style="margin-top:28px">
            <aside>
                <ul>@foreach ($pages as $p)<li><a href="{{ \App\Support\Legal::url($p['slug']) }}" @if ($p['slug'] === $page->slug) aria-current="page" @endif>{{ $p['title'] }}</a></li>@endforeach</ul>
                @if (count($toc))
                    <ol>@foreach ($toc as [$id, $text])<li><a href="#{{ $id }}">{{ preg_replace('/^\d+\.\s*/', '', $text) }}</a></li>@endforeach</ol>
                @endif
            </aside>
            <article class="legal-body">{{ $html }}</article>
        </div>
    </div>
</section>
@endsection
