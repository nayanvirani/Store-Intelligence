<div class="grid c4" style="margin-top:32px;align-items:stretch">
    @foreach ($plans as $plan)
        <article class="card" style="display:flex;flex-direction:column;gap:14px;padding:24px;{{ $plan['badge'] ? 'border:2px solid var(--brand)' : '' }}">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:8px"><h3 style="margin:0;font-size:18px">{{ $plan['name'] }}</h3>@if ($plan['badge'])<span class="badge">{{ $plan['badge'] }}</span>@endif</div>
            <div><span class="num" style="font-size:36px;font-weight:600;letter-spacing:-.03em">{{ $plan['price'] > 0 ? '$'.rtrim(rtrim(number_format($plan['price'], 2), '0'), '.') : '$0' }}</span><span class="muted"> {{ $plan['price'] > 0 ? '/ month' : 'forever' }}</span></div>
            @if ($plan['description'])<p style="font-size:15px">{{ $plan['description'] }}</p>@endif
            <ul class="ticks" style="font-size:14px;flex:1 1 auto">
                @foreach ($plan['features'] as $f)<li>{{ $f }}</li>@endforeach
            </ul>
            <a class="btn{{ $plan['badge'] ? ' primary' : '' }}" href="{{ config('shopify.install_url') }}">{{ $plan['price'] > 0 ? 'Choose '.$plan['name'] : 'Start free' }}</a>
        </article>
    @endforeach
</div>
<p class="muted small" style="margin-top:16px;text-align:center">{{ $pricing['billing_note'] }} {{ $pricing['trial_note'] }}</p>
