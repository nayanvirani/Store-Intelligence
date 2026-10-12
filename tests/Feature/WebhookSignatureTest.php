<?php

namespace Tests\Feature;

use Tests\TestCase;

class WebhookSignatureTest extends TestCase
{
    public function test_rejects_unsigned_webhooks(): void
    {
        config(['shopify.api_secret' => 'secret']);

        $this->postJson('/webhooks/shopify', ['id' => 1], ['X-Shopify-Topic' => 'app/uninstalled'])
            ->assertUnauthorized();
    }

    public function test_rejects_webhooks_signed_with_another_secret(): void
    {
        config(['shopify.api_secret' => 'secret']);
        $body = json_encode(['id' => 1]);

        $this->call('POST', '/webhooks/shopify', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $body, 'other', true)),
        ], $body)->assertUnauthorized();
    }
}
