@extends('admin.layout')
@section('title', $page->exists ? $page->title : 'New legal page')
@section('content')
@php
    $title = old('title', $page->draft_title ?? $page->title);
    $body = old('body', $page->draft_body ?? $page->body);
@endphp
<p class="ad-crumbs"><a href="{{ route('admin.legal') }}">Legal &amp; policies</a> / {{ $page->exists ? $page->title : 'New page' }}</p>
<div class="ad-head">
    <div>
        <h1>{{ $page->exists ? $page->title : 'New legal page' }}</h1>
        @if ($page->exists)
            <p>
                @if ($page->is_published)<a href="{{ $page->url() }}" target="_blank" rel="noopener">{{ str_replace(rtrim(config('app.url'), '/'), '', $page->url()) }} ↗</a> · @endif
                Version {{ $page->version ?: '—' }} · effective {{ $page->effective_at?->toFormattedDateString() ?? '—' }}
            </p>
        @endif
    </div>
</div>

@if ($page->exists && $page->hasDraft())
    <div class="ad-note warn">You're editing a draft. The live page still shows version {{ $page->version }} until you publish.</div>
@endif

<form method="POST" action="{{ $page->exists ? route('admin.legal.update', $page->id) : route('admin.legal.store') }}" class="ad-form" id="legal-form">
    @csrf
    <div class="ad-legal">
        <div>
            <section class="ad-card">
                <div class="ad-fields">
                    @unless ($page->exists)
                        <label>Address<span class="ad-input-prefix"><span>/legal/</span><input name="slug" value="{{ old('slug') }}" required pattern="[a-z0-9]+(-[a-z0-9]+)*" placeholder="shipping-policy"></span><small>Lowercase letters, numbers and dashes. Can't change later.</small></label>
                    @endunless
                    <label>Title<input name="title" value="{{ $title }}" required maxlength="120"></label>
                    <label>Summary<input name="summary" value="{{ old('summary', $page->summary) }}" maxlength="300"><small>Shown on the Legal page and in search results.</small></label>
                </div>
                <label style="margin-top:14px">Text (Markdown)
                    <textarea name="body" class="ad-code" rows="34" required>{{ $body }}</textarea>
                    <small><code>## Heading</code> starts a section (and a table-of-contents entry) · <code>**bold**</code> · <code>- item</code> · <code>[text](https://…)</code> · tables with <code>| a | b |</code></small>
                </label>
            </section>
        </div>

        <aside>
            @if ($page->exists)
                <section class="ad-card">
                    <h2>Publish</h2>
                    <label>Effective date<input type="date" name="effective_at" value="{{ old('effective_at', now()->toDateString()) }}"></label>
                    <label style="margin-top:12px">What changed (for the record)<input name="change_summary" maxlength="500" placeholder="e.g. Added refund window"></label>
                    <label class="ad-check" style="margin-top:12px"><input type="checkbox" name="ask_review" value="1"><span>Ask merchants to review this update<small>Shows a notice in the app with a link; each store's acknowledgement is recorded. Use for material changes to Terms, Privacy, DPA or Billing.</small></span></label>
                    <div class="ad-stack" style="margin-top:14px">
                        <button class="ad-btn primary" type="submit" name="intent" value="publish" onclick="return confirm('Publish this version? It replaces the live page.')">Publish</button>
                        <button class="ad-btn" type="submit" name="intent" value="draft">Save draft</button>
                        <button class="ad-btn" type="submit" formaction="{{ route('admin.legal.preview', $page->id) }}" formtarget="_blank" formnovalidate>Preview ↗</button>
                    </div>
                </section>
            @else
                <section class="ad-card">
                    <h2>Create</h2>
                    <p class="ad-muted">The page is saved as a draft and stays hidden until you publish it.</p>
                    <button class="ad-btn primary" type="submit">Create draft</button>
                </section>
            @endif

            <section class="ad-card">
                <h2>Visibility</h2>
                <label class="ad-check"><input type="checkbox" name="is_published" value="1" @checked($page->is_published || ($core ?? false)) @disabled($core ?? false)><span>Public<small>{{ ($core ?? false) ? 'Required: the App Store listing and the app link here.' : 'Hidden pages return "not found".' }}</small></span></label>
                <label class="ad-check" style="margin-top:10px"><input type="checkbox" name="show_in_footer" value="1" @checked(old('show_in_footer', $page->show_in_footer))><span>Link in the website footer</span></label>
                <label style="margin-top:12px">Order<input type="number" name="position" min="0" max="1000" value="{{ old('position', $page->position) }}"></label>
            </section>

            <section class="ad-card">
                <h2>Placeholders</h2>
                <p class="ad-muted">Filled from <a href="{{ route('admin.legal') }}">your details</a> when the page is shown.</p>
                <dl class="ad-placeholders">
                    @foreach (\App\Support\Legal::PLACEHOLDERS as $key => $label)<dt><code>{{ str_repeat('{', 2).$key.str_repeat('}', 2) }}</code></dt><dd>{{ $label }}</dd>@endforeach
                </dl>
            </section>

            @if ($page->exists)
                @if ($page->hasDraft() || $hasDefault)
                    <section class="ad-card">
                        <h2>Start over</h2>
                        <div class="ad-stack">
                            @if ($page->hasDraft())<button class="ad-btn" type="submit" name="intent" value="discard" formnovalidate onclick="return confirm('Discard the draft?')">Discard draft</button>@endif
                            @if ($hasDefault)<button class="ad-btn" type="submit" name="intent" value="default" formnovalidate onclick="return confirm('Load the original text as a draft?')">Load original text</button>@endif
                        </div>
                    </section>
                @endif
                <section class="ad-card flush">
                    <header><h2>Versions</h2></header>
                    <table class="ad-table">
                        <tbody>
                            @forelse ($page->versions as $v)
                                <tr><td><a class="ad-strong" href="{{ route('admin.legal.version', [$page->id, $v->version]) }}">v{{ $v->version }}</a> · {{ $v->published_at->toFormattedDateString() }}<div class="ad-muted">{{ $v->change_summary ?: 'No note' }} · {{ $v->published_by }}</div></td></tr>
                            @empty <tr><td class="ad-empty">Not published yet.</td></tr> @endforelse
                        </tbody>
                    </table>
                </section>
            @endif
        </aside>
    </div>
</form>
@endsection
