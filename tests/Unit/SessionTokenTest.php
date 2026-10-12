<?php

namespace Tests\Unit;

use App\Exceptions\InvalidSessionToken;
use App\Services\Shopify\SessionToken;
use PHPUnit\Framework\TestCase;

class SessionTokenTest extends TestCase
{
    private const KEY = 'test-api-key';

    private const SECRET = 'test-api-secret';

    public function test_decodes_a_valid_token(): void
    {
        $claims = $this->verifier()->decode($this->token());

        $this->assertSame('demo.myshopify.com', SessionToken::shopFromClaims($claims));
        $this->assertSame('42', $claims['sub']);
    }

    public function test_rejects_a_bad_signature(): void
    {
        $this->expectException(InvalidSessionToken::class);

        $this->verifier()->decode($this->token(secret: 'wrong-secret'));
    }

    public function test_rejects_an_expired_token(): void
    {
        $this->expectException(InvalidSessionToken::class);

        $this->verifier()->decode($this->token(['exp' => time() - 120]));
    }

    public function test_rejects_a_token_for_another_app(): void
    {
        $this->expectException(InvalidSessionToken::class);

        $this->verifier()->decode($this->token(['aud' => 'other-app']));
    }

    public function test_rejects_mismatched_issuer_and_destination(): void
    {
        $this->expectException(InvalidSessionToken::class);

        $this->verifier()->decode($this->token(['iss' => 'https://evil.myshopify.com/admin']));
    }

    public function test_rejects_non_shopify_destination(): void
    {
        $this->expectException(InvalidSessionToken::class);

        $this->verifier()->decode($this->token(['iss' => 'https://evil.com/admin', 'dest' => 'https://evil.com']));
    }

    private function verifier(): SessionToken
    {
        return new SessionToken(self::KEY, self::SECRET);
    }

    private function token(array $overrides = [], string $secret = self::SECRET): string
    {
        $claims = array_merge([
            'iss' => 'https://demo.myshopify.com/admin',
            'dest' => 'https://demo.myshopify.com',
            'aud' => self::KEY,
            'sub' => '42',
            'exp' => time() + 60,
            'nbf' => time() - 1,
            'iat' => time() - 1,
            'jti' => 'abc',
            'sid' => 'def',
        ], $overrides);

        $encode = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $signingInput = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode($claims);
        $signature = rtrim(strtr(base64_encode(hash_hmac('sha256', $signingInput, $secret, true)), '+/', '-_'), '=');

        return "{$signingInput}.{$signature}";
    }
}
