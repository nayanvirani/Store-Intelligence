<?php

namespace App\Services\Shopify;

use App\Exceptions\InvalidSessionToken;

/**
 * Verifies App Bridge session tokens (HS256 JWTs signed with the app secret).
 *
 * @see https://shopify.dev/docs/apps/build/authentication-authorization/session-tokens
 */
class SessionToken
{
    private const LEEWAY_SECONDS = 10;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $apiSecret,
    ) {}

    /**
     * @return array{iss: string, dest: string, aud: string, sub?: string, exp: int, nbf: int, iat: int, jti: string, sid?: string}
     */
    public function decode(string $token): array
    {
        $parts = explode('.', $token);

        if (count($parts) !== 3) {
            throw new InvalidSessionToken('Malformed token.');
        }

        [$header64, $payload64, $signature64] = $parts;

        $header = json_decode(self::base64UrlDecode($header64), true);

        if (($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidSessionToken('Unsupported algorithm.');
        }

        $expected = hash_hmac('sha256', "{$header64}.{$payload64}", $this->apiSecret, true);

        if (! hash_equals($expected, self::base64UrlDecode($signature64))) {
            throw new InvalidSessionToken('Invalid signature.');
        }

        $claims = json_decode(self::base64UrlDecode($payload64), true);

        if (! is_array($claims)) {
            throw new InvalidSessionToken('Invalid payload.');
        }

        $now = time();

        if (($claims['exp'] ?? 0) < $now - self::LEEWAY_SECONDS || ($claims['nbf'] ?? PHP_INT_MAX) > $now + self::LEEWAY_SECONDS) {
            throw new InvalidSessionToken('Token expired or not yet valid.');
        }

        if (($claims['aud'] ?? null) !== $this->apiKey) {
            throw new InvalidSessionToken('Invalid audience.');
        }

        $destHost = parse_url($claims['dest'] ?? '', PHP_URL_HOST);
        $issHost = parse_url($claims['iss'] ?? '', PHP_URL_HOST);

        if (! $destHost || $destHost !== $issHost || ! ShopDomain::isValid($destHost)) {
            throw new InvalidSessionToken('Invalid shop.');
        }

        return $claims;
    }

    public static function shopFromClaims(array $claims): string
    {
        return parse_url($claims['dest'], PHP_URL_HOST);
    }

    private static function base64UrlDecode(string $value): string
    {
        return (string) base64_decode(strtr($value, '-_', '+/'), true);
    }
}
