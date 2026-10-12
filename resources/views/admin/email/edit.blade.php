@extends('admin.layout')
@section('title', $provider->exists ? $provider->name : 'Add '.$provider->label())
@section('content')
@php($d = \App\Services\Mail\Drivers::ALL[$provider->driver])
<p class="ad-crumbs"><a href="{{ route('admin.email') }}">Email providers</a> / {{ $provider->exists ? $provider->name : 'Add '.$d['label'] }}</p>
<div class="ad-head">
    <div><h1>{{ $provider->exists ? $provider->name : 'Add '.$d['label'] }}</h1><p>{{ $d['note'] }}@if ($d['url']) <a href="{{ $d['url'] }}" target="_blank" rel="noopener">Get your key ↗</a>@endif</p></div>
</div>
@if ($provider->exists && $provider->last_error)
    <div class="ad-note {{ $provider->state() === 'ok' ? '' : 'warn' }}"><b>Last error</b> ({{ $provider->last_error_at?->diffForHumans() }}): {{ $provider->last_error }}<br><span class="ad-small">Saving this form clears the pause so it's tried again.</span></div>
@endif

<form method="POST" action="{{ $provider->exists ? route('admin.email.update', $provider->id) : route('admin.email.store') }}" class="ad-form" autocomplete="off">
    @csrf
    <input type="hidden" name="driver" value="{{ $provider->driver }}">
    <section class="ad-card">
        <h2>Connection</h2>
        <div class="ad-fields">
            <label>Name<input name="name" value="{{ old('name', $provider->name) }}" required maxlength="80"><small>For you, e.g. "Brevo – main account".</small></label>
            @foreach ($d['fields'] as $key => [$label, $secret, $help])
                @php($saved = $provider->exists ? (string) (($provider->credentials ?? [])[$key] ?? '') : '')
                <label>{{ $label }}
                    @if ($secret)
                        <input type="password" name="credentials[{{ $key }}]" value="" @required(! $provider->exists) autocomplete="new-password" placeholder="{{ $saved !== '' ? 'Saved (ends in '.substr($saved, -4).') — leave empty to keep' : '' }}">
                    @else
                        <input name="credentials[{{ $key }}]" value="{{ old('credentials.'.$key, $saved ?: (\App\Services\Mail\Drivers::defaults($provider->driver)[$key] ?? '')) }}">
                    @endif
                    @if ($help)<small>{{ $help }}</small>@endif
                </label>
            @endforeach
        </div>
        <p class="ad-muted ad-small" style="margin-top:12px">Keys are stored encrypted and never shown again.</p>
    </section>

    <section class="ad-card">
        <h2>Sender</h2>
        <div class="ad-fields">
            <label>From email (optional)<input type="email" name="from_email" value="{{ old('from_email', $provider->from_email) }}" placeholder="{{ \App\Support\EmailSettings::get()['from_email'] ?: 'hello@example.com' }}"><small>Empty: the default sender. It must be a sender or domain you verified with {{ \Illuminate\Support\Str::before($d['label'], ' (') }}.</small></label>
            <label>From name (optional)<input name="from_name" value="{{ old('from_name', $provider->from_name) }}" maxlength="120"></label>
        </div>
    </section>

    <section class="ad-card">
        <h2>Limits &amp; order</h2>
        <div class="ad-fields">
            <label>Daily limit<input type="number" name="daily_limit" min="1" value="{{ old('daily_limit', $provider->daily_limit) }}" placeholder="No limit"><small>When reached, the next provider takes over until tomorrow (UTC).</small></label>
            <label>Monthly limit<input type="number" name="monthly_limit" min="1" value="{{ old('monthly_limit', $provider->monthly_limit) }}" placeholder="No limit"></label>
            <label>Priority<input type="number" name="priority" min="0" value="{{ old('priority', $provider->priority) }}"><small>Lower goes first.</small></label>
        </div>
        <label class="ad-check" style="margin-top:12px"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $provider->is_active))><span>Use this provider</span></label>
    </section>

    <div class="ad-savebar">
        @if ($provider->exists)
            <button class="ad-btn danger" type="submit" form="delete-provider">Remove</button>
        @endif
        <a class="ad-btn" href="{{ route('admin.email') }}">Cancel</a>
        <button class="ad-btn primary" type="submit">{{ $provider->exists ? 'Save' : 'Add provider' }}</button>
    </div>
</form>
@if ($provider->exists)
    <form id="delete-provider" method="POST" action="{{ route('admin.email.action', [$provider->id, 'delete']) }}" onsubmit="return confirm('Remove {{ e($provider->name) }}? Its key is deleted.')">@csrf</form>
@endif
@endsection
