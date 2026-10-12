<div class="grid c4" style="margin-top:28px">
    @foreach ($plans as $plan)
        <div class="card" style="{{ $plan['badge'] ? 'border:2px solid var(--brand)' : '' }}">
            <div style="display:flex;justify-content:space-between;align-items:center"><h3 style="margin:0">{{ $plan['name'] }}</h3>@if ($plan['badge'])<span class="badge">{{ $plan['badge'] }}</span>@endif</div>
            <div style="font-size:34px;font-weight:800;margin:8px 0 2px">{{ $plan['price'] > 0 ? '$'.rtrim(rtrim(number_format($plan['price'], 2), '0'), '.') : 'Free' }}<span class="muted" style="font-size:15px;font-weight:500">{{ $plan['price'] > 0 ? ' / month' : '' }}</span></div>
            @if ($plan['description'])<p class="small">{{ $plan['description'] }}</p>@endif
            <ul style="padding-left:18px;margin:14px 0 0;color:var(--muted);font-size:14px">
                @foreach ($plan['features'] as $f)<li style="margin:4px 0">{{ $f }}</li>@endforeach
            </ul>
        </div>
    @endforeach
</div>
<p class="muted small" style="margin-top:14px">{{ $pricing['billing_note'] }} {{ $pricing['trial_note'] }}</p>
