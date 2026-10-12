<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="shopify-api-key" content="{{ config('shopify.api_key') }}">
    <script src="https://cdn.shopify.com/shopifycloud/app-bridge.js"></script>
</head>
<body>
    <script>
        shopify.idToken().then((token) => {
            const url = new URL(window.location.href);
            url.searchParams.set('id_token', token);
            url.searchParams.set('bounced', '1');
            window.location.replace(url.toString());
        });
    </script>
</body>
</html>
