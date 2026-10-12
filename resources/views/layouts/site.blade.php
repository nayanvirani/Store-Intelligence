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
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        :root { --ink:#0f172a; --muted:#475569; --soft:#f1f5f9; --line:#e2e8f0; --brand:#0d9488; --brand2:#1e3a8a; --gold:#f59e0b; --bg:#ffffff; }
        * { box-sizing: border-box; }
        body { margin:0; font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif; color:var(--ink); background:var(--bg); line-height:1.6; -webkit-font-smoothing: antialiased; }
        a { color: var(--brand2); }
        .wrap { width: min(1120px, 100% - 40px); margin: 0 auto; }
        header.top { border-bottom:1px solid var(--line); background:#fff; position:sticky; top:0; z-index:10; }
        header.top .wrap { display:flex; align-items:center; justify-content:space-between; height:68px; gap:16px; }
        .logo { display:flex; align-items:center; gap:10px; font-weight:800; font-size:18px; color:var(--ink); text-decoration:none; }
        .logo img { width:34px; height:34px; }
        nav.main { display:flex; gap:22px; align-items:center; }
        nav.main a { color:var(--muted); text-decoration:none; font-weight:500; font-size:15px; }
        nav.main a:hover { color:var(--ink); }
        .btn { display:inline-flex; align-items:center; justify-content:center; gap:8px; padding:11px 20px; border-radius:10px; font-weight:600; text-decoration:none; border:1px solid var(--line); color:var(--ink); background:#fff; cursor:pointer; font-size:15px; }
        .btn.primary { background:linear-gradient(135deg,var(--brand),var(--brand2)); color:#fff; border:0; }
        .btn.primary:hover { filter:brightness(1.08); }
        main { min-height: 60vh; }
        h1 { font-size: clamp(36px, 5vw, 58px); line-height:1.08; letter-spacing:-0.02em; margin:0 0 18px; }
        h2 { font-size: clamp(26px, 3vw, 36px); line-height:1.2; letter-spacing:-0.01em; margin:0 0 12px; }
        h3 { font-size: 19px; margin: 0 0 6px; }
        .lead { font-size: 19px; color: var(--muted); max-width: 680px; }
        .eyebrow { display:inline-block; font-size:13px; font-weight:700; letter-spacing:.08em; text-transform:uppercase; color:var(--brand); margin-bottom:12px; }
        .section { padding: 72px 0; }
        .section.alt { background: var(--soft); }
        .grid { display:grid; gap:20px; }
        .grid.c2 { grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); }
        .grid.c3 { grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); }
        .grid.c4 { grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); }
        .card { background:#fff; border:1px solid var(--line); border-radius:16px; padding:24px; }
        .card p { color: var(--muted); margin: 0; }
        .muted { color: var(--muted); }
        .small { font-size: 14px; }
        .badge { display:inline-block; background:#ecfdf5; color:#047857; border-radius:999px; padding:3px 10px; font-size:12px; font-weight:700; }
        .alert { padding:14px 16px; border-radius:10px; margin-bottom:16px; }
        .alert.ok { background:#ecfdf5; color:#065f46; } .alert.bad { background:#fef2f2; color:#991b1b; }
        input, select, textarea { width:100%; padding:11px 12px; border:1px solid #cbd5e1; border-radius:10px; font: inherit; }
        textarea { min-height: 140px; }
        label.fld { display:flex; flex-direction:column; gap:6px; font-weight:600; font-size:14px; }
        .err { color:#b91c1c; font-weight:500; font-size:13px; }
        footer.bottom { border-top:1px solid var(--line); padding: 40px 0; color: var(--muted); font-size: 14px; }
        footer.bottom .wrap { display:flex; flex-wrap:wrap; justify-content:space-between; gap:20px; }
        footer.bottom a { color: var(--muted); text-decoration:none; margin-right:16px; }
        @media (max-width: 720px) { nav.main a.hide-sm { display:none; } .section { padding: 48px 0; } }
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
            <div>
                <a href="{{ route('site.pricing') }}">Pricing</a>
                <a href="{{ route('site.contact') }}">Contact</a>
                @foreach (array_filter(\App\Support\Legal::pages(), fn ($p) => $p['footer']) as $p)
                    <a href="{{ \App\Support\Legal::url($p['slug']) }}">{{ $p['title'] }}</a>
                @endforeach
            </div>
        </div>
    </footer>
</body>
</html>
