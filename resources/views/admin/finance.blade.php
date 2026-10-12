@extends('admin.layout')
@section('title', 'Finance')
@section('content')
@php($m = $month)
@php($money = fn ($v) => ($v < 0 ? '−' : '').'$'.number_format(abs((float) $v), 2))
@php($isCurrent = $m['month'] === now()->format('Y-m'))
<div class="ad-head">
    <div>
        <h1>Finance</h1>
        <p>Monthly earnings, expenses and profit. Amounts in {{ $currency }}.</p>
    </div>
    <form method="GET" action="{{ route('admin.finance') }}" class="fn-month">
        <a class="ad-btn" href="{{ route('admin.finance', ['month' => \Carbon\Carbon::createFromFormat('!Y-m', $m['month'])->subMonth()->format('Y-m')]) }}" aria-label="Previous month">←</a>
        <input type="month" name="month" value="{{ $m['month'] }}" onchange="this.form.submit()" aria-label="Month">
        <a class="ad-btn" href="{{ route('admin.finance', ['month' => \Carbon\Carbon::createFromFormat('!Y-m', $m['month'])->addMonth()->format('Y-m')]) }}" aria-label="Next month">→</a>
        @unless ($isCurrent)<a class="ad-btn" href="{{ route('admin.finance') }}">This month</a>@endunless
    </form>
</div>

<div class="ad-kpis">
    <div><small>Revenue · {{ $m['label'] }}</small><b>{{ $money($m['revenue']) }}</b><span>{{ $m['subscribers'] }} paying {{ \Illuminate\Support\Str::plural('store', $m['subscribers']) }}{{ $m['income'] ? ' + '.$money($m['income']).' other income' : '' }}</span></div>
    <div><small>Expenses</small><b>{{ $money($m['expenses']) }}</b><span>{{ $m['railway']['connected'] ? 'Railway, ' : '' }}fixed costs, fees and entries</span></div>
    <div class="{{ $m['profit'] < 0 ? 'alert' : '' }}"><small>Profit</small><b style="color:{{ $m['profit'] < 0 ? 'var(--bad)' : 'var(--ok)' }}">{{ $money($m['profit']) }}</b><span>Revenue − expenses</span></div>
    <div><small>Profit margin</small><b>{{ $m['margin'] === null ? '—' : $m['margin'].'%' }}</b><span>{{ $isCurrent ? 'Month so far' : 'Whole month' }}</span></div>
</div>

<div class="ad-grid">
    <section class="ad-card">
        <header><h2>{{ $m['label'] }} breakdown</h2></header>
        <table class="ad-table fn-table">
            <tbody>
                <tr class="fn-head"><th colspan="2">Revenue</th></tr>
                <tr><td>Subscriptions <span class="ad-muted">· {{ $m['subscribers'] }} paying, estimated from plans</span></td><td class="num">{{ $money($m['subscriptions']) }}</td></tr>
                <tr><td>Other income <span class="ad-muted">· manual entries</span></td><td class="num">{{ $money($m['income']) }}</td></tr>
                <tr class="fn-total"><td>Total revenue</td><td class="num">{{ $money($m['revenue']) }}</td></tr>

                <tr class="fn-head"><th colspan="2">Expenses</th></tr>
                @if ($m['railway']['connected'])
                    @foreach ($m['railway']['invoices'] as $inv)
                        <tr><td>Railway · {{ $m['railway']['project'] }} <span class="ad-muted">· {{ $inv['share'] === null ? 'no usage' : round($inv['share'] * 100, 1).'%' }} of the {{ \Carbon\Carbon::parse($inv['date'])->format('M j') }} invoice ({{ $money($inv['charged']) }}{{ $inv['charged'] != $inv['total'] ? ' after credits' : '' }})</span></td><td class="num">{{ $money($inv['counted']) }}</td></tr>
                    @endforeach
                    @if ($m['railway']['estimate'])
                        @php($est = $m['railway']['estimate'])
                        <tr><td>Railway · {{ $m['railway']['project'] }}, current period <span class="ad-badge warn">Estimate</span> <span class="ad-muted">· {{ $est['share'] === null ? 'no usage yet' : round($est['share'] * 100, 1).'%' }} of the {{ $money($est['bill']) }} bill due {{ \Carbon\Carbon::parse($est['due'])->format('M j') }}</span></td><td class="num">{{ $money($est['amount']) }}</td></tr>
                    @endif
                    @if (! $m['railway']['invoices'] && ! $m['railway']['estimate'])
                        <tr><td>Railway <span class="ad-muted">· no invoice this month</span></td><td class="num">{{ $money(0) }}</td></tr>
                    @endif
                @else
                    <tr><td>Railway <span class="ad-muted">· <a href="#railway">connect Railway</a> to add hosting costs automatically</span></td><td class="num">—</td></tr>
                @endif
                @foreach ($m['recurring'] as $r)
                    <tr><td>{{ $r['name'] }} <span class="ad-muted">· monthly</span></td><td class="num">{{ $money($r['amount']) }}</td></tr>
                @endforeach
                @foreach ($m['fees'] as $f)
                    <tr><td>{{ $f['name'] }} <span class="ad-muted">· {{ rtrim(rtrim(number_format($f['percent'], 3), '0'), '.') }}% of subscriptions</span></td><td class="num">{{ $money($f['amount']) }}</td></tr>
                @endforeach
                <tr><td>Manual expenses <span class="ad-muted">· {{ $m['entries']->where('kind', 'expense')->count() }} {{ \Illuminate\Support\Str::plural('entry', $m['entries']->where('kind', 'expense')->count()) }}</span></td><td class="num">{{ $money($m['manual']) }}</td></tr>
                <tr class="fn-total"><td>Total expenses</td><td class="num">{{ $money($m['expenses']) }}</td></tr>

                <tr class="fn-profit {{ $m['profit'] < 0 ? 'loss' : '' }}"><td>Profit</td><td class="num">{{ $money($m['profit']) }}</td></tr>
            </tbody>
        </table>
        @if ($m['skipped'])<p class="ad-muted" style="margin:0 0 8px">Not counted while Railway is connected (it would count twice): {{ implode(', ', array_column($m['skipped'], 'name')) }}. Remove {{ count($m['skipped']) === 1 ? 'it' : 'them' }} from Monthly costs below.</p>@endif
        <p class="ad-muted" style="margin:0">Subscription revenue counts each store with a paid plan once per month it was billable (after the free trial, until cancelled), at the plan it was on last that month. Development, partner and staff stores aren't charged by Shopify, so they aren't counted, nor are test stores, test charges or complimentary plans. Shopify bills every 30 days, so compare with your Shopify Partners payouts and add any difference as income or expense.</p>
    </section>

    <section class="ad-card">
        <header><h2>Add expense or income</h2></header>
        <form method="POST" action="{{ route('admin.finance.store') }}" class="ad-form">
            @csrf
            <div class="ad-fields">
                <label>Type
                    <select name="kind"><option value="expense" @selected(old('kind', 'expense') === 'expense')>Expense</option><option value="income" @selected(old('kind') === 'income')>Income</option></select>
                </label>
                <label>Date<input type="date" name="date" required value="{{ old('date', $isCurrent ? now()->toDateString() : $m['month'].'-01') }}"></label>
                <label style="grid-column:1/-1">Description<input type="text" name="description" required maxlength="200" value="{{ old('description') }}" placeholder="e.g. Domain renewal, Freelance designer, Ads"></label>
                <label>Category<input type="text" name="category" maxlength="60" list="fn-categories" value="{{ old('category') }}" placeholder="Optional"></label>
                <label>Amount ({{ $currency }})<input type="number" name="amount" required min="0.01" step="0.01" value="{{ old('amount') }}" placeholder="0.00"></label>
            </div>
            <datalist id="fn-categories">@foreach ($categories as $c)<option value="{{ $c }}">@endforeach</datalist>
            <div style="margin-top:14px"><button class="ad-btn primary" type="submit">Add entry</button></div>
        </form>
    </section>
</div>

<section class="ad-card flush">
    <header><h2>Entries in {{ $m['label'] }}</h2></header>
    @if ($m['entries']->isEmpty())
        <p class="ad-muted" style="padding:0 18px 18px">No manual entries this month.</p>
    @else
        <table class="ad-table">
            <thead><tr><th>Date</th><th>Type</th><th>Description</th><th>Category</th><th class="num">Amount</th><th></th></tr></thead>
            <tbody>
                @foreach ($m['entries'] as $e)
                    <tr>
                        <td>{{ $e->date->format('M j, Y') }}</td>
                        <td><span class="ad-badge {{ $e->kind === 'income' ? 'ok' : 'bad' }}">{{ ucfirst($e->kind) }}</span></td>
                        <td>{{ $e->description }}</td>
                        <td>{{ $e->category ?: '—' }}</td>
                        <td class="num">{{ $e->kind === 'income' ? '+' : '−' }}{{ $money($e->amount) }}</td>
                        <td class="num">
                            <details class="fn-edit">
                                <summary class="ad-btn">Edit</summary>
                                <form method="POST" action="{{ route('admin.finance.update', $e) }}" class="fn-edit-form">
                                    @csrf
                                    <select name="kind"><option value="expense" @selected($e->kind === 'expense')>Expense</option><option value="income" @selected($e->kind === 'income')>Income</option></select>
                                    <input type="date" name="date" value="{{ $e->date->toDateString() }}" required>
                                    <input type="text" name="description" value="{{ $e->description }}" maxlength="200" required>
                                    <input type="text" name="category" value="{{ $e->category }}" maxlength="60" list="fn-categories" placeholder="Category">
                                    <input type="number" name="amount" value="{{ $e->amount }}" min="0.01" step="0.01" required>
                                    <button class="ad-btn primary" type="submit">Save</button>
                                </form>
                            </details>
                            <form method="POST" action="{{ route('admin.finance.destroy', $e) }}" onsubmit="return confirm('Delete this entry?')" style="display:inline">@csrf<button class="ad-btn" type="submit">Delete</button></form>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
</section>

<section class="ad-card" id="railway">
    <header>
        <div><h2>Railway hosting</h2><p class="ad-muted" style="margin:4px 0 0">This project's hosting cost, from Railway's API. Railway bills the whole workspace, so only this project's part of each bill is counted: its share of the workspace's CPU, memory, network and volume usage.</p></div>
        @if ($railway['ok'])
            <form method="POST" action="{{ route('admin.finance.railway.refresh') }}">@csrf<button class="ad-btn" type="submit">Refresh</button></form>
        @endif
    </header>
    @if ($railway['ok'])
        <div class="ad-kpis" style="margin-bottom:12px">
            <div><small>Project</small><b style="font-size:18px">{{ $railway['project'] }}</b><span>Workspace {{ $railway['workspace'] }} · {{ ucfirst(strtolower($railway['plan'])) }} plan</span></div>
            <div><small>Current period</small><b style="font-size:18px">{{ \Carbon\Carbon::parse($railway['period']['start'])->format('M j') }} – {{ \Carbon\Carbon::parse($railway['period']['end'])->format('M j') }}</b><span>Next invoice on {{ \Carbon\Carbon::parse($railway['period']['end'])->format('M j') }}</span></div>
            <div><small>{{ $railway['project'] }} share so far</small><b style="font-size:18px">{{ $railway['share_now'] === null ? '—' : round($railway['share_now'] * 100, 1).'%' }}</b><span>of the workspace's usage ({{ $money($railway['usage']) }} so far)</span></div>
        </div>
        @if ($railway['invoices'])
            <table class="ad-table">
                <thead><tr><th>Workspace invoice</th><th>Status</th><th class="num">Charged</th><th class="num">{{ $railway['project'] }} share</th><th class="num">{{ $railway['project'] }} cost</th><th></th></tr></thead>
                <tbody>
                    @foreach ($railway['invoices'] as $inv)
                        <tr><td>{{ \Carbon\Carbon::parse($inv['date'])->format('M j, Y') }}</td><td><span class="ad-badge {{ $inv['status'] === 'paid' ? 'ok' : 'warn' }}">{{ ucfirst($inv['status']) }}</span></td><td class="num">{{ $money($inv['charged']) }}{{ $inv['charged'] != $inv['total'] ? ' of '.$money($inv['total']) : '' }}</td><td class="num">{{ $inv['share'] === null ? '—' : round($inv['share'] * 100, 1).'%' }}</td><td class="num">{{ $money(round($inv['charged'] * ($inv['share'] ?? 0), 2)) }}</td><td class="num">@if ($inv['url'])<a href="{{ $inv['url'] }}" target="_blank" rel="noopener">View</a>@endif</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif
        <p class="ad-muted" style="margin:0 0 12px">Updated {{ \Carbon\Carbon::parse($railway['fetched_at'])->diffForHumans() }}. "Charged" is what Railway billed after credits. The share is this project's part of the workspace's usage in the month before each invoice; "—" means no usage was recorded then.</p>
    @elseif ($railway['error'] !== 'not_connected')
        <p class="ad-badge bad" style="margin-bottom:12px">{{ $railway['error'] }}</p>
    @endif
    <form method="POST" action="{{ route('admin.finance.railway') }}" class="ad-form">
        @csrf
        <div class="ad-fields">
            <label>Railway token<input type="password" name="token" autocomplete="off" placeholder="{{ $railwaySettings['token'] ? ($railwaySettings['from_env'] ? 'Set by the RAILWAY_API_TOKEN variable' : 'Saved · paste a new one to replace it') : 'Paste a token' }}"><small>A workspace token from railway.com/account/tokens. Saved encrypted.</small></label>
            <label>Project
                @if (! empty($railway['projects']))
                    <select name="project_id">@foreach ($railway['projects'] as $pid => $pname)<option value="{{ $pid }}" @selected($pid === ($railway['project_id'] ?? $railwaySettings['project_id']))>{{ $pname }}</option>@endforeach</select>
                @else
                    <input type="text" name="project_id" value="{{ $railwaySettings['project_id'] }}" placeholder="This app's project (detected)">
                @endif
                <small>The project whose cost is counted. This app's own project is detected automatically.</small></label>
        </div>
        <div class="ad-inline" style="margin-top:12px;display:flex;gap:8px">
            <button class="ad-btn primary" type="submit">{{ $railwaySettings['token'] ? 'Save' : 'Connect Railway' }}</button>
            @if ($railwaySettings['token'] && ! $railwaySettings['from_env'])<button class="ad-btn" type="submit" name="disconnect" value="1" onclick="return confirm('Disconnect Railway?')">Disconnect</button>@endif
        </div>
    </form>
</section>

<form method="POST" action="{{ route('admin.finance.settings') }}" class="ad-card" data-fn-settings>
    @csrf
    <header>
        <div><h2>Other monthly costs and fees</h2><p class="ad-muted" style="margin:4px 0 0">Fixed costs charged every month between the months you set (domain, email, tools). Railway comes from its API above. Fees are a percentage of subscription revenue, e.g. Shopify's processing fee.</p></div>
    </header>
    <h3 class="fn-sub">Fixed monthly costs</h3>
    <div class="fn-rows" data-rows="recurring">
        <div class="fn-row fn-labels"><span>Name</span><span>Amount / month</span><span>From</span><span>Until (optional)</span><span></span></div>
        @foreach ($settings['recurring'] as $i => $r)
            <div class="fn-row">
                <input type="text" name="recurring[{{ $i }}][name]" value="{{ $r['name'] }}" maxlength="80" placeholder="e.g. Railway">
                <input type="number" name="recurring[{{ $i }}][amount]" value="{{ $r['amount'] }}" min="0" step="0.01">
                <input type="month" name="recurring[{{ $i }}][from]" value="{{ $r['from'] }}">
                <input type="month" name="recurring[{{ $i }}][until]" value="{{ $r['until'] }}">
                <button type="button" class="ad-btn" data-remove aria-label="Remove">✕</button>
            </div>
        @endforeach
    </div>
    <button type="button" class="ad-btn" data-add="recurring">+ Add monthly cost</button>

    <h3 class="fn-sub">Percentage fees</h3>
    <div class="fn-rows" data-rows="fees">
        <div class="fn-row fn-labels fn-fee"><span>Name</span><span>% of subscription revenue</span><span></span></div>
        @foreach ($settings['fees'] as $i => $f)
            <div class="fn-row fn-fee">
                <input type="text" name="fees[{{ $i }}][name]" value="{{ $f['name'] }}" maxlength="80">
                <input type="number" name="fees[{{ $i }}][percent]" value="{{ $f['percent'] }}" min="0" max="100" step="0.001">
                <button type="button" class="ad-btn" data-remove aria-label="Remove">✕</button>
            </div>
        @endforeach
    </div>
    <button type="button" class="ad-btn" data-add="fees">+ Add fee</button>
    <div class="ad-savebar"><button class="ad-btn primary" type="submit">Save costs and fees</button></div>
</form>

<section class="ad-card flush">
    <header><h2>Last 12 months</h2></header>
    <table class="ad-table">
        <thead><tr><th>Month</th><th class="num">Revenue</th><th class="num">Expenses</th><th class="num">Profit</th><th class="num">Margin</th></tr></thead>
        <tbody>
            @foreach ($history as $h)
                <tr @if ($h['month'] === $m['month']) class="fn-current" @endif>
                    <td><a href="{{ route('admin.finance', ['month' => $h['month']]) }}">{{ $h['label'] }}</a></td>
                    <td class="num">{{ $money($h['revenue']) }}</td>
                    <td class="num">{{ $money($h['expenses']) }}</td>
                    <td class="num" style="color:{{ $h['profit'] < 0 ? 'var(--bad)' : 'var(--ok)' }};font-weight:650">{{ $money($h['profit']) }}</td>
                    <td class="num">{{ $h['margin'] === null ? '—' : $h['margin'].'%' }}</td>
                </tr>
            @endforeach
            <tr class="fn-total">
                <td>12-month total</td>
                <td class="num">{{ $money(array_sum(array_column($history, 'revenue'))) }}</td>
                <td class="num">{{ $money(array_sum(array_column($history, 'expenses'))) }}</td>
                <td class="num">{{ $money(array_sum(array_column($history, 'profit'))) }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</section>

<style>
    .fn-month { display: flex; align-items: center; gap: 8px; }
    .fn-month input { padding: 7px 10px; border: 1px solid #cfd1dc; border-radius: 9px; font: inherit; }
    .ad-table .num, .fn-table .num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
    .fn-table { margin-bottom: 12px; }
    .fn-table .fn-head th { background: var(--soft); text-transform: uppercase; letter-spacing: .04em; }
    .fn-table .fn-total td { font-weight: 650; background: var(--soft); }
    .fn-table .fn-profit td { font-size: 17px; font-weight: 750; color: var(--ok); background: var(--ok-soft); border-bottom: 0; }
    .fn-table .fn-profit.loss td { color: var(--bad); background: var(--bad-soft); }
    .ad-table tr.fn-total td { font-weight: 650; background: var(--soft); }
    .ad-table tr.fn-current td { background: var(--accent-soft); }
    .fn-sub { margin: 16px 0 8px; font-size: 13px; text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
    .fn-rows { display: grid; gap: 8px; margin-bottom: 10px; }
    .fn-row { display: grid; grid-template-columns: minmax(160px, 2fr) minmax(110px, 1fr) minmax(130px, 1fr) minmax(130px, 1fr) 44px; gap: 8px; align-items: center; }
    .fn-row.fn-fee { grid-template-columns: minmax(160px, 2fr) minmax(110px, 1fr) 44px; }
    .fn-row input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #cfd1dc; border-radius: 9px; font: inherit; }
    .fn-labels span { font-size: 12px; font-weight: 600; color: var(--muted); }
    .fn-edit { display: inline-block; text-align: left; }
    .fn-edit summary { list-style: none; }
    .fn-edit summary::-webkit-details-marker { display: none; }
    .fn-edit[open] { display: block; margin-bottom: 8px; }
    .fn-edit-form { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 6px; margin-top: 8px; min-width: 420px; }
    .fn-edit-form input, .fn-edit-form select { padding: 6px 8px; border: 1px solid #cfd1dc; border-radius: 8px; font: inherit; }
    @media (max-width: 760px) { .fn-row, .fn-row.fn-fee { grid-template-columns: 1fr 1fr; } .fn-labels { display: none; } .fn-edit-form { min-width: 0; grid-template-columns: 1fr; } }
</style>
<script>
    (() => {
        const form = document.querySelector('[data-fn-settings]');
        let n = 1000;
        const templates = {
            recurring: (i) => `<input type="text" name="recurring[${i}][name]" maxlength="80" placeholder="e.g. Domain, Email, Software"><input type="number" name="recurring[${i}][amount]" min="0" step="0.01" placeholder="0.00"><input type="month" name="recurring[${i}][from]" value="{{ now()->format('Y-m') }}"><input type="month" name="recurring[${i}][until]"><button type="button" class="ad-btn" data-remove aria-label="Remove">✕</button>`,
            fees: (i) => `<input type="text" name="fees[${i}][name]" maxlength="80" placeholder="e.g. Payment gateway"><input type="number" name="fees[${i}][percent]" min="0" max="100" step="0.001" placeholder="0"><button type="button" class="ad-btn" data-remove aria-label="Remove">✕</button>`,
        };
        form.addEventListener('click', (e) => {
            const add = e.target.closest('[data-add]');
            if (add) {
                const row = document.createElement('div');
                row.className = 'fn-row' + (add.dataset.add === 'fees' ? ' fn-fee' : '');
                row.innerHTML = templates[add.dataset.add](n++);
                form.querySelector(`[data-rows="${add.dataset.add}"]`).appendChild(row);
                row.querySelector('input').focus();
            }
            const remove = e.target.closest('[data-remove]');
            if (remove) remove.closest('.fn-row').remove();
        });
    })();
</script>
@endsection
