{{-- Standalone error page: no menus, database or app code, so it renders even when something is down. --}}
@php($e = [
    403 => ['Access denied', 'You don\'t have access to this page', 'If you think you should, ask the store owner or contact us.'],
    404 => ['Page not found', 'We couldn\'t find that page', 'It may have moved, or the link may be wrong.'],
    419 => ['Session expired', 'Your session expired', 'Please go back, refresh the page and try again.'],
    429 => ['Too many requests', 'Slow down a little', 'Too many requests in a short time. Please wait a minute and try again.'],
    500 => ['Server error', 'Something went wrong on our side', 'We\'ve been notified. Please try again in a moment.'],
    503 => ['Maintenance', 'Back in a moment', 'Store Intelligence is being updated. Please try again in a few minutes.'],
][$status] ?? ['Error', 'Something went wrong', 'Please try again.'])
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>{{ $e[0] }} · Store Intelligence</title>
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; display: grid; min-height: 100vh; place-items: center; padding: 32px 16px; background: #f8fafc; color: #0f172a; font: 16px/1.55 Inter, system-ui, -apple-system, sans-serif; }
        main { width: min(560px, 100%); text-align: center; }
        .code { display: inline-block; padding: 7px 14px; border-radius: 999px; background: #ccfbf1; color: #0f766e; font: 700 12px/1 system-ui, sans-serif; letter-spacing: .12em; text-transform: uppercase; }
        h1 { margin: 16px 0 10px; font-size: clamp(32px, 7vw, 48px); line-height: 1.1; }
        p { margin: 0 auto; color: #475569; }
        .ctas { margin-top: 28px; }
        .btn { display: inline-block; padding: 12px 22px; border-radius: 10px; background: linear-gradient(135deg, #0d9488, #1e3a8a); color: #fff; font-weight: 700; text-decoration: none; }
    </style>
</head>
<body>
    <main>
        <a href="/"><img src="/brand/si-logo.svg" alt="Store Intelligence" width="220" height="47" style="margin-bottom:40px"></a>
        <div><span class="code">{{ $status }} · {{ $e[0] }}</span></div>
        <h1>{{ $e[1] }}</h1>
        <p>{{ $e[2] }}</p>
        <div class="ctas"><a class="btn" href="/">Go to homepage</a></div>
    </main>
</body>
</html>
