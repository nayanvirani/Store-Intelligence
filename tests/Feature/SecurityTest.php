<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithShopify;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use InteractsWithShopify, RefreshDatabase;

    public function test_security_headers(): void
    {
        $this->setUpShopify();
        Http::fake(fn () => Http::response(['data' => []]));
        $store = $this->installedStore();
        $this->get('/admin/login')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $owner = $this->member($store, 'owner');
        $response = $this->get('/app', $this->as($owner));
        $response->assertOk();
        $this->assertFalse($response->headers->has('X-Frame-Options'), 'The embedded app must load inside Shopify admin.');
        $this->assertStringContainsString('frame-ancestors', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_public_site_pages(): void
    {
        foreach (['/', '/pricing', '/contact', '/privacy', '/terms', '/dpa', '/legal', '/legal/subprocessors'] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/')->assertSee('See your real profit.');
        $this->get('/pricing')->assertSee('Starter')->assertSee('$29');
        $this->get('/robots.txt')->assertOk()->assertSee('Disallow: /admin');
        $this->get('/nope')->assertNotFound()->assertSee('We couldn&#039;t find that page', false);
    }
}
