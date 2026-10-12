<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyShopifyWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        $hmac = (string) $request->header('X-Shopify-Hmac-Sha256');
        $expected = base64_encode(hash_hmac('sha256', $request->getContent(), (string) config('shopify.api_secret'), true));

        if ($hmac === '' || ! hash_equals($expected, $hmac)) {
            return response('Unauthorized', 401);
        }

        return $next($request);
    }
}
