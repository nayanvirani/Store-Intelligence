@extends('admin.layout')
@section('title', 'Email providers')
@section('content')
@php
    $states = ['ok' => ['ok', 'Ready'], 'limited' => ['warn', 'Limit reached'], 'failing' => ['bad', 'Failing'], 'off' => ['', 'Off']];
    $usage = function ($sent, $limit) {
        $pct = $limit ? min(100, round($sent / $limit * 100)) : 0;
        return '<div class="ad-meter'.($pct >= 100 ? ' full' : ($pct >= 80 ? ' high' : '')).'"><i style="width:'.$pct.'%"></i></div><small>'.number_format($sent).' / '.($limit ? number_format($limit) : '∞').'</small>';
    };
@endphp
<div class="ad-head">
    <div><h1>Email providers</h1><p>The services emails go out through. Add several: when one reaches its limit or fails, the next takes over automatically.</p></div>
    <div class="ad-actions"><a class="ad-btn" href="{{ route('admin.email.log') }}">Delivery log</a><a class="ad-btn primary" href="{{ route('admin.email.create') }}">Add provider</a></div>
</div>

@unless ($settings['enabled'])
    <div class="ad-note warn"><b>Sending is switched off.</b> Emails wait in the queue until you switch it on below.</div>
@endunless
@if ($providers->isEmpty())
    <div class="ad-note">No providers yet. Add one (Brevo, Resend, Mailjet, SMTP2GO… all have free plans), then add more so sending carries on when one reaches its daily limit.</div>
@elseif (! $next && $settings['enabled'])
    <div class="ad-note warn"><b>No provider can send right now</b>: all have reached their limit, are resting after an error, or are switched off. Emails wait and go out when one is available.</div>
@endif

<div class="ad-kpis">
    <div><small>Sent today</small><b>{{ number_format($stats['sent']) }}</b><span>Across all providers</span></div>
    <div class="{{ $stats['failed'] ? 'alert' : '' }}"><small>Failed attempts today</small><b>{{ number_format($stats['failed']) }}</b><span><a href="{{ route('admin.email.log', ['status' => 'failed']) }}">See why</a></span></div>
    <div><small>Waiting to send</small><b>{{ number_format($stats['waiting']) }}</b><span>Emails in the queue</span></div>
    <div><small>Left today</small><b>{{ $stats['unlimited'] ? '∞' : number_format($stats['capacity']) }}</b><span>{{ $next ? 'Next email goes through '.$next->name : 'No provider available' }}</span></div>
</div>

<section class="ad-card flush">
    <header><h2>Providers</h2><span class="ad-muted">{{ $settings['strategy'] === 'balance' ? 'Spread by allowance left' : 'Tried top to bottom' }} · limits reset daily and monthly (UTC)</span></header>
    <div class="ad-scroll">
        <table class="ad-table">
            <thead><tr><th>Order</th><th>Provider</th><th>Status</th><th>Today</th><th>This month</th><th>Last sent</th><th></th></tr></thead>
            <tbody>
                @forelse ($providers as $i => $p)
                    @php([$tone, $label] = $states[$p->state()])
                    <tr>
                        <td class="ad-order">
                            <form method="POST" action="{{ route('admin.email.action', [$p->id, 'up']) }}">@csrf<button class="ad-icon-btn" @disabled($i === 0) aria-label="Move up">↑</button></form>
                            <form method="POST" action="{{ route('admin.email.action', [$p->id, 'down']) }}">@csrf<button class="ad-icon-btn" @disabled($loop->last) aria-label="Move down">↓</button></form>
                        </td>
                        <td>
                            <a class="ad-strong" href="{{ route('admin.email.edit', $p->id) }}">{{ $p->name }}</a>
                            <div class="ad-muted">{{ $p->label() }} · {{ $p->from_email ?: ($settings['from_email'] ?: 'no sender set') }}</div>
                        </td>
                        <td>
                            <span class="ad-badge {{ $tone }}">{{ $label }}</span>
                            @if ($p->paused() && $p->is_active)<div class="ad-muted ad-small">Resting until {{ $p->paused_until->diffForHumans() }}</div>@endif
                            @if ($p->last_error && $p->state() !== 'ok')<div class="ad-muted ad-small ad-clip" title="{{ $p->last_error }}">{{ \Illuminate\Support\Str::limit($p->last_error, 90) }}</div>@endif
                        </td>
                        <td class="ad-usage">{!! $usage($p->sent_today, $p->daily_limit) !!}</td>
                        <td class="ad-usage">{!! $usage($p->sent_month, $p->monthly_limit) !!}</td>
                        <td>{{ $p->last_sent_at?->diffForHumans() ?? '—' }}</td>
                        <td class="ad-row-actions">
                            <details class="ad-menu">
                                <summary class="ad-btn">Test</summary>
                                <form method="POST" action="{{ route('admin.email.test', $p->id) }}" class="ad-pop">
                                    @csrf
                                    <label>Send a test to<input type="email" name="to" required value="{{ auth()->user()->email }}"></label>
                                    <button class="ad-btn primary" type="submit">Send test</button>
                                </form>
                            </details>
                            @if ($p->is_active && in_array($p->state(), ['limited', 'failing'], true) && $p->paused())
                                <form method="POST" action="{{ route('admin.email.action', [$p->id, 'resume']) }}">@csrf<button class="ad-btn">Resume</button></form>
                            @endif
                            <form method="POST" action="{{ route('admin.email.action', [$p->id, 'toggle']) }}">@csrf<button class="ad-btn">{{ $p->is_active ? 'Switch off' : 'Switch on' }}</button></form>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="ad-empty">No providers yet. <a href="{{ route('admin.email.create') }}">Add your first one</a>.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</section>

<form method="POST" action="{{ route('admin.email.settings') }}" class="ad-form">
    @csrf
    <section class="ad-card">
        <h2>Sending</h2>
        <label class="ad-check"><input type="checkbox" name="enabled" value="1" @checked($settings['enabled'])><span>Send emails<small>Off: workflow emails wait in the queue (up to 3 days) instead of going out.</small></span></label>
        <p class="ad-group-title">How providers are chosen</p>
        <div class="ad-fields">
            @foreach (\App\Support\EmailSettings::STRATEGIES as $key => [$label, $help])
                <label class="ad-check ad-choice"><input type="radio" name="strategy" value="{{ $key }}" @checked($settings['strategy'] === $key)><span>{{ $label }}<small>{{ $help }}</small></span></label>
            @endforeach
        </div>
        <p class="ad-group-title">Default sender</p>
        <div class="ad-fields">
            <label>From email<input type="email" name="from_email" value="{{ old('from_email', $settings['from_email']) }}" placeholder="hello@example.com"><small>Must be verified with each provider (or set per provider). Use your own domain, not Gmail, for good delivery.</small></label>
            <label>From name<input name="from_name" value="{{ old('from_name', $settings['from_name']) }}" maxlength="120"><small>Workflow emails use the store's name instead.</small></label>
            <label>Reply-to (optional)<input type="email" name="reply_to" value="{{ old('reply_to', $settings['reply_to']) }}"><small>Workflow emails reply to the store's email.</small></label>
        </div>
        <div class="ad-actions" style="margin-top:16px"><button class="ad-btn primary" type="submit">Save settings</button></div>
    </section>
</form>
@endsection
