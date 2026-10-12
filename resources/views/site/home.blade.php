@extends('layouts.site')
@push('head')
<style>
    .hero { background: var(--soft); border-bottom: 1px solid #E8ECF1; }
    .hero .wrap { display: grid; grid-template-columns: repeat(auto-fit, minmax(340px, 1fr)); gap: 48px; align-items: center; padding: 72px 0; }
    .hero-copy { display: flex; flex-direction: column; gap: 20px; align-items: flex-start; }
    .hero-copy h1 { margin: 0; }
    .hero-copy .lead { margin: 0; max-width: 520px; }
    .hero-copy .ticks { flex-direction: row; flex-wrap: wrap; gap: 8px 22px; color: var(--ink2); font-size: 14px; }
    .demo { display: flex; flex-direction: column; gap: 16px; padding: 22px; border-radius: 18px; background: var(--night); color: #fff; box-shadow: 0 30px 60px -30px rgba(15, 27, 45, .45); }
    .demo-top { display: flex; justify-content: space-between; align-items: center; gap: 12px; color: #9FB0C6; font-size: 13px; }
    .demo-top b { padding: 3px 8px; border-radius: 999px; background: #134E4A; color: #7EE2D4; font-size: 12px; }
    .demo-big { font: 600 40px/1 var(--mono); letter-spacing: -.03em; }
    .demo-row { display: grid; grid-template-columns: 120px minmax(0, 1fr) 84px; gap: 10px; align-items: center; font-size: 13px; }
    .demo-row span:first-child { color: #C6D2E1; }
    .demo-row div { height: 8px; border-radius: 999px; background: #22324A; overflow: hidden; }
    .demo-row i { display: block; height: 100%; border-radius: inherit; }
    .demo-row span:last-child { text-align: right; font-family: var(--mono); }
    .demo-alert { display: flex; gap: 10px; align-items: flex-start; padding: 12px; border-radius: 12px; background: #1B2A40; color: #E5EDF6; font-size: 13px; }
    .demo-alert::before { content: ""; flex: none; width: 8px; height: 8px; margin-top: 6px; border-radius: 50%; background: #F59E0B; }
    .pillar { display: flex; flex-direction: column; gap: 12px; }
    .pillar .tag { align-self: flex-start; padding: 4px 10px; border-radius: 999px; font-size: 12px; font-weight: 700; letter-spacing: .04em; text-transform: uppercase; }
    .pillar h3 { margin: 0; font-size: 22px; }
    .pillar .ticks { margin-top: 6px; font-size: 15px; }
    .alerts { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 10px; }
    .alerts li { display: flex; gap: 12px; align-items: flex-start; padding: 14px 16px; border: 1px solid #22324A; border-radius: 12px; background: #17263C; }
    .alerts li > i { flex: none; width: 9px; height: 9px; margin-top: 8px; border-radius: 50%; }
    .alerts b { display: block; font-weight: 600; }
    .alerts span { color: #9FB0C6; font-size: 14px; }
    .cta .wrap { display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 24px; padding: 64px 0; }
    .cta h2 { margin: 0; font-size: 32px; }
    .cta p { margin: 6px 0 0; color: var(--ink2); font-size: 17px; }
    @media (max-width: 480px) { .demo-row { grid-template-columns: 96px minmax(0, 1fr) 74px; } .demo-big { font-size: 32px; } }
</style>
@endpush
@section('content')
<section class="hero">
    <div class="wrap">
        <div class="hero-copy">
            <span class="pill">Profit · Protection · Marketing for Shopify</span>
            <h1>Know what your store really earns.</h1>
            <p class="lead">Your real profit after product costs, fees, shipping and ad spend — every day, automatically. Plus alerts the moment something starts leaking money.</p>
            <div style="display:flex;flex-wrap:wrap;gap:12px">
                <a class="btn primary lg" href="{{ config('shopify.install_url') }}">Install free on Shopify</a>
                <a class="btn lg" href="{{ route('site.pricing') }}">See pricing</a>
            </div>
            <ul class="ticks"><li>Free plan</li><li>Read-only access</li><li>Set up in 5 minutes</li></ul>
        </div>
        <div class="demo" aria-label="Example profit snapshot">
            <div class="demo-top"><span>Profit after ads · last 30 days</span><b>↑ 18%</b></div>
            <span class="demo-big">$12,640</span>
            <div style="display:flex;flex-direction:column;gap:9px">
                @foreach ([['Net sales', '$48,250', 100, '#94A3B8'], ['Product costs', '− $17,800', 37, '#F97316'], ['Fees & shipping', '− $8,610', 18, '#F59E0B'], ['Ad spend', '− $9,200', 19, '#60A5FA'], ['Profit after ads', '$12,640', 26, '#5EEAD4']] as [$label, $value, $w, $color])
                    <div class="demo-row"><span>{{ $label }}</span><div><i style="width:{{ $w }}%;background:{{ $color }}"></i></div><span>{{ $value }}</span></div>
                @endforeach
            </div>
            <div class="demo-alert"><span><strong>Ads are running on a sold-out product.</strong> Vitamin C Serum 60 ml has 0 in stock.</span></div>
            <span style="color:#7C8DA4;font-size:12px">Example figures</span>
        </div>
    </div>
</section>

<section class="section" id="features">
    <div class="wrap">
        <span class="eyebrow">What you get</span>
        <h2>Three jobs, one app</h2>
        <div class="grid c3" style="margin-top:32px">
            @foreach ([
                ['Profit', '#E2F1EF', '#0B5550', 'Your real profit, daily', 'Product costs, payment fees, shipping and ad spend taken out — automatically.', ['Profit by product, order and channel', 'Break-even ROAS for every product', 'Monthly Business Report on the 1st']],
                ['Protect', '#FFF1DB', '#7A4A00', 'Alerts that save money', 'Price mistakes, best sellers running out and broken landing pages, caught early.', ['Store Health Score from 0 to 100', 'Inventory days-of-stock alerts', 'One prioritised Action Center']],
                ['Grow', '#E3EBFA', '#1E3A8A', 'Ads and social, together', 'Google and Meta results next to profit, and a month of social posts planned for you.', ['Campaign health and creative leaderboard', 'AI 30-day social calendar', 'Orders and revenue from social']],
            ] as [$tag, $tint, $ink, $title, $text, $points])
                <article class="card pillar">
                    <span class="tag" style="background:{{ $tint }};color:{{ $ink }}">{{ $tag }}</span>
                    <h3>{{ $title }}</h3>
                    <p>{{ $text }}</p>
                    <ul class="ticks">@foreach ($points as $pt)<li>{{ $pt }}</li>@endforeach</ul>
                </article>
            @endforeach
        </div>
    </div>
</section>

<section class="section dark">
    <div class="wrap grid c2" style="align-items:center">
        <div>
            <span class="eyebrow" style="color:#5EEAD4">Money-leak alerts</span>
            <h2>Catch problems before they cost you</h2>
            <p style="margin:0;color:#C6D2E1;font-size:17px">Alerts combine your ads, inventory and catalog — so you hear about the expensive problems, not the noise. Every alert shows the evidence and the fix.</p>
        </div>
        <ul class="alerts">
            @foreach ([
                ['#F87171', 'Ads running on a sold-out product', 'Meta campaign "Fall Glow" is sending clicks to Vitamin C Serum 60 ml (0 in stock).'],
                ['#F59E0B', 'Price dropped below cost', 'Night Cream went from $48.00 to $12.00 — cost is $14.20.'],
                ['#F59E0B', 'Best seller running low', 'Hyaluronic Toner has 4 days of stock left at the current pace.'],
                ['#60A5FA', 'Landing page failing', '/products/clay-mask returned errors 3 times in a row.'],
            ] as [$dot, $title, $text])
                <li><i style="background:{{ $dot }}"></i><div><b>{{ $title }}</b><span>{{ $text }}</span></div></li>
            @endforeach
        </ul>
    </div>
</section>

@if ($plans)
<section class="section" id="pricing">
    <div class="wrap">
        <div style="text-align:center;display:flex;flex-direction:column;align-items:center;gap:8px">
            <h2 style="margin:0">{{ $pricing['headline'] }}</h2>
            <p class="lead" style="margin:0;font-size:17px">{{ $pricing['lead'] }}</p>
        </div>
        @include('site._plans')
    </div>
</section>
@endif

<section class="section alt cta" style="padding:0;border-top:1px solid #E8ECF1">
    <div class="wrap">
        <div style="max-width:620px">
            <h2>See your real profit in five minutes.</h2>
            <p>Install, let it import your orders, and your profit snapshot is ready. No spreadsheets.</p>
        </div>
        <a class="btn primary lg" href="{{ config('shopify.install_url') }}">Install free on Shopify</a>
    </div>
</section>
@endsection
