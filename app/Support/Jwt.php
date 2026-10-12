<?php

namespace App\Support;

use RuntimeException;

/**
 * Minimal HS256 JSON Web Tokens, for Shopify's post-purchase extension: verifying the token
 * Shopify signs with the app's secret, and signing the changesets the extension applies.
 */
class Jwt
{
    public static function encode(array $payload, string $secret): string
    {
        $segments = [self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT'])), self::b64(json_encode($payload, JSON_UNESCAPED_SLASHES))];
        $segments[] = self::b64(hash_hmac('sha256', implode('.', $segments), $secret, true));

        return implode('.', $segments);
    }

    /**
     * @return array the verified payload
     *
     * @throws RuntimeException when the token is malformed, signed with another key or expired
     */
    public static function decode(string $token, string $secret, int $leeway = 60): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('Malformed token.');
        }
        [$head, $body, $sig] = $parts;
        $header = json_decode(self::unb64($head), true);
        if (($header['alg'] ?? null) !== 'HS256') {
            throw new RuntimeException('Unsupported token algorithm.');
        }
        if (! hash_equals(self::b64(hash_hmac('sha256', "{$head}.{$body}", $secret, true)), $sig)) {
            throw new RuntimeException('Invalid token signature.');
        }
        $payload = json_decode(self::unb64($body), true);
        if (! is_array($payload)) {
            throw new RuntimeException('Malformed token payload.');
        }
        if (isset($payload['exp']) && $payload['exp'] + $leeway < time()) {
            throw new RuntimeException('Expired token.');
        }

        return $payload;
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $text): string
    {
        return (string) base64_decode(strtr($text, '-_', '+/').str_repeat('=', (4 - strlen($text) % 4) % 4));
    }
}
