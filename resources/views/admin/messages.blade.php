@extends('admin.layout')
@section('title', 'Website messages')
@section('content')
<div class="ad-head"><div><h1>Website messages</h1><p>Messages sent through the public contact form. Reply by email, then mark them done.</p></div></div>
<nav class="ad-tabs">
    @foreach (['new' => 'New', 'done' => 'Done', 'all' => 'All'] as $k => $label)
        <a href="{{ route('admin.messages', ['status' => $k]) }}" @if ($status === $k) aria-current="page" @endif>{{ $label }}@if ($k !== 'all') <span class="ad-muted">{{ $counts[$k] ?? 0 }}</span>@endif</a>
    @endforeach
</nav>
@forelse ($messages as $m)
    <section class="ad-card">
        <header>
            <h2>{{ $m->topic }} · {{ $m->name }}</h2>
            <span class="ad-muted">{{ $m->created_at->diffForHumans() }}</span>
        </header>
        <p><a href="mailto:{{ $m->email }}?subject={{ rawurlencode('Re: your message to Store Intelligence') }}">{{ $m->email }}</a>@if ($m->store_url) · {{ $m->store_url }}@endif @if ($m->company) · {{ $m->company }}@endif</p>
        <p style="white-space:pre-wrap">{{ $m->message }}</p>
        <form method="POST" action="{{ route('admin.messages.update', $m->id) }}">@csrf
            <input type="hidden" name="status" value="{{ $m->status === 'done' ? 'new' : 'done' }}">
            <button class="ad-btn" type="submit">{{ $m->status === 'done' ? 'Mark as new' : 'Mark done' }}</button>
        </form>
    </section>
@empty
    <section class="ad-card"><p class="ad-muted">No messages.</p></section>
@endforelse
{{ $messages->links('admin._pager') }}
@endsection
