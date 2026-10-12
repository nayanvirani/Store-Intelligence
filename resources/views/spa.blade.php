<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="shopify-api-key" content="{{ config('shopify.api_key') }}">
    <title>Store Intelligence</title>
    @include('partials.favicons')
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
    <script src="https://cdn.shopify.com/shopifycloud/polaris.js"></script>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{-- The same styles as the Blade pages, so both look alike while modules move to React. --}}
    @foreach (['css/app-brand.css', 'css/app-base.css'] as $css)
        <link rel="stylesheet" href="{{ asset($css) }}?v={{ @filemtime(public_path($css)) }}">
    @endforeach
    @vite('main.jsx', 'spa')
    <script>
        window.OO_PAGE = @json($page);
        window.OO_ROUTES = @json(\App\Support\Spa\Shared::routes());
    </script>
</head>
<body>
    @include('app._appnav')
    {{-- The React admin (resources/app) renders here; later pages load as JSON without reloading. --}}
    <div id="root"></div>
    @if ($notice = \App\Support\Notices::get(request('notice')))
        <script>
            shopify.toast.show(@json($notice[0]), { isError: @json($notice[1]) });
            (() => { const u = new URL(location.href); u.searchParams.delete('notice'); history.replaceState(null, '', u); })();
        </script>
    @endif
</body>
</html>
