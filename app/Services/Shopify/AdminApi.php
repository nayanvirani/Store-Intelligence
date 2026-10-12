<?php

namespace App\Services\Shopify;

use App\Models\Store;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AdminApi
{
    public function __construct(private readonly TokenExchange $tokens) {}

    public function graphql(Store $store, string $query, array $variables = []): array
    {
        if ($store->needsTokenRefresh()) {
            $this->tokens->refresh($store);
        }

        $version = config('shopify.api_version');

        $response = Http::withHeaders(['X-Shopify-Access-Token' => $store->access_token])
            ->acceptJson()
            ->retry(3, 500, fn ($e, $request) => $e instanceof RequestException && $e->response->status() === 429, throw: false)
            ->post("https://{$store->shop_domain}/admin/api/{$version}/graphql.json", [
                'query' => $query,
                'variables' => (object) $variables,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("Admin API request failed for {$store->shop_domain}: HTTP {$response->status()} ".mb_substr((string) $response->body(), 0, 500));
        }

        $body = $response->json();

        if (! empty($body['errors'])) {
            $codes = array_filter(array_map(fn ($e) => $e['extensions']['code'] ?? null, (array) $body['errors']));
            if (in_array('THROTTLED', $codes, true)) {
                throw new ThrottledException('Shopify API rate limit reached for '.$store->shop_domain);
            }
            if (in_array('ACCESS_DENIED', $codes, true)) {
                throw new AccessDeniedException('Admin API access denied: '.json_encode($body['errors']));
            }
            throw new RuntimeException('Admin API errors: '.json_encode($body['errors']));
        }

        return $body['data'] ?? [];
    }
}
