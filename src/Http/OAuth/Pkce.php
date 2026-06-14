<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth;

/**
 * PKCE (RFC 7636) helpers. Claude Code always uses the S256 method.
 */
final class Pkce
{
    /**
     * Verify a code_verifier against the stored code_challenge.
     */
    public static function verify(string $verifier, string $challenge, string $method): bool
    {
        if ($verifier === '' || $challenge === '') {
            return false;
        }

        $computed = match (strtoupper($method)) {
            'S256' => self::base64Url(hash('sha256', $verifier, true)),
            'PLAIN', '' => $verifier,
            default => null,
        };

        return $computed !== null && hash_equals($challenge, $computed);
    }

    /**
     * URL-safe, unpadded base64 (base64url).
     */
    public static function base64Url(string $binary): string
    {
        return rtrim(strtr(base64_encode($binary), '+/', '-_'), '=');
    }
}
