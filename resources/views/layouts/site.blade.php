<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Store Intelligence | Real profit, store protection & AI marketing for Shopify')</title>
    <meta name="description" content="@yield('description', 'See your real profit after costs, fees, shipping and ad spend. Catch costly store problems automatically. Plan a month of social media in minutes.')">
    <meta property="og:site_name" content="Store Intelligence">
    <meta property="og:title" content="@yield('title', 'Store Intelligence')">
    @include('partials.favicons')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Geist:wght@400;500;600;700;800&family=Geist+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root { --ink:#0F1B2D; --ink2:#4A5568; --muted:#5B6678; --soft:#F3F6F9; --line:#E3E7ED; --brand:#0B6E67; --brand-hover:#084F4A; --brand-soft:#E2F1EF; --night:#0F1B2D; --bg:#ffffff; --mono:"Geist Mono", ui-monospace, Menlo, monospace; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: Geist, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color:var(--ink); background:var(--bg); line-height:1.6; -webkit-font-smoothing: antialiased; }
        a { color: var(--brand); }
        a:hover { color: var(--brand-hover); }
        .wrap { width: min(1160px, 100% - 48px); margin: 0 auto; }
        header.top { border-bottom:1px solid #E8ECF1; background:rgba(255,255,255,.92); backdrop-filter: blur(8px); position:sticky; top:0; z-index:10; }
        header.top .wrap { display:flex; align-items:center; justify-content:space-between; height:68px; gap:16px; }
        .logo { display:flex; align-items:center; gap:10px; font-weight:700; font-size:18px; letter-spacing:-.01em; color:var(--ink); text-decoration:none; }
        .logo img { width:32px; height:32px; }
        nav.main { display:flex; gap:24px; align-items:center; }
        nav.main a:not(.btn) { color:var(--ink2); text-decoration:none; font-weight:500; font-size:15px; }
        nav.main a:not(.btn):hover { color:var(--ink); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:12px 20px; border-radius:11px; font-weight:600; text-decoration:none; border:1px solid #D5DBE3; color:var(--ink); background:#fff; cursor:pointer; font: 600 15px/1.2 inherit; }
        .btn:hover { background:#F6F8FA; color:var(--ink); }
        .btn.primary { background:var(--brand); border-color:var(--brand); color:#fff; }
        .btn.primary:hover { background:var(--brand-hover); border-color:var(--brand-hover); color:#fff; }
        .btn.lg { padding:14px 24px; }
        main { min-height: 60vh; }
        h1 { font-size: clamp(38px, 5.4vw, 56px); line-height:1.04; letter-spacing:-0.035em; font-weight:800; margin:0 0 18px; }
        h2 { font-size: clamp(28px, 3.4vw, 38px); line-height:1.15; letter-spacing:-0.025em; margin:0 0 12px; }
        h3 { font-size: 20px; letter-spacing:-.015em; margin: 0 0 6px; }
        .lead { font-size: 19px; color: var(--ink2); max-width: 640px; }
        .eyebrow { display:inline-block; font-size:13px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--brand); margin-bottom:10px; }
        .pill { display:inline-block; padding:5px 12px; border-radius:999px; background:var(--brand-soft); color:#0B5550; font-size:13px; font-weight:600; }
        .section { padding: 80px 0; }
        .section.alt { background: var(--soft); }
        .section.dark { background: var(--night); color:#fff; }
        .grid { display:grid; gap:20px; }
        .grid.c2 { grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap:40px; }
        .grid.c3 { grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); }
        .grid.c4 { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap:16px; }
        .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:26px; }
        .card p { color: var(--ink2); margin: 0; }
        .ticks { list-style:none; margin:0; padding:0; display:flex; flex-direction:column; gap:8px; }
        .ticks li { display:flex; gap:8px; }
        .ticks li::before { content:""; flex:none; width:18px; height:18px; margin-top:3px; background: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%230B6E67' stroke-width='2.4' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpath d='m5 12 5 5L20 7'/%3E%3C/svg%3E") no-repeat center / contain; }
        .muted { color: var(--muted); }
        .small { font-size: 14px; }
        .num { font-family: var(--mono); font-variant-numeric: tabular-nums; }
        .badge { display:inline-block; background:var(--brand-soft); color:#0B5550; border-radius:999px; padding:3px 10px; font-size:12px; font-weight:700; }
        .alert { padding:14px 16px; border-radius:10px; margin-bottom:16px; }
        .alert.ok { background:var(--brand-soft); color:#0B5550; } .alert.bad { background:#FDECEA; color:#7A1A12; }
        input, select, textarea { width:100%; padding:11px 12px; border:1px solid #C9D1DC; border-radius:10px; font: inherit; color: var(--ink); background:#fff; }
        input:focus, select:focus, textarea:focus { outline:2px solid rgba(11,110,103,.35); border-color:var(--brand); }
        textarea { min-height: 140px; }
        label.fld { display:flex; flex-direction:column; gap:6px; font-weight:600; font-size:14px; }
        .err { color:#B42318; font-weight:500; font-size:13px; }
        footer.bottom { border-top:1px solid #E8ECF1; padding: 32px 0; color: var(--muted); font-size: 14px; }
        footer.bottom .wrap { display:flex; flex-wrap:wrap; justify-content:space-between; gap:16px 20px; }
        footer.bottom nav { display:flex; flex-wrap:wrap; gap:8px 18px; }
        footer.bottom a { color: var(--muted); text-decoration:none; }
        footer.bottom a:hover { color: var(--ink); }
        @media (max-width: 720px) { nav.main a.hide-sm { display:none; } .section { padding: 56px 0; } .wrap { width: calc(100% - 32px); } }
    </style>
    @stack('head')
</head>
<body>
    <header class="top">
        <div class="wrap">
            <a class="logo" href="{{ route('site.home') }}"><img src="/brand/si-icon.svg" alt="">Store Intelligence</a>
            <nav class="main" aria-label="Main">
                <a class="hide-sm" href="{{ route('site.home') }}#features">Features</a>
                <a class="hide-sm" href="{{ route('site.pricing') }}">Pricing</a>
                <a class="hide-sm" href="{{ route('site.contact') }}">Contact</a>
                <a class="btn primary" href="{{ config('shopify.install_url') }}">Install on Shopify</a>
            </nav>
        </div>
    </header>
    <main>@yield('content')</main>
    <footer class="bottom">
        <div class="wrap">
            <div>© {{ date('Y') }} {{ \App\Support\Legal::details()['trading_name'] ?: 'Store Intelligence' }}. Built for Shopify merchants.</div>
            <nav aria-label="Footer">
                <a href="{{ route('site.pricing') }}">Pricing</a>
                <a href="{{ route('site.contact') }}">Contact</a>
                @foreach (array_filter(\App\Support\Legal::pages(), fn ($p) => $p['footer']) as $p)
                    <a href="{{ \App\Support\Legal::url($p['slug']) }}">{{ $p['title'] }}</a>
                @endforeach
            </nav>
        </div>
    </footer>
</body>
</html>
