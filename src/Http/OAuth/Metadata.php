<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth;

/**
 * OAuth discovery documents the MCP client (Claude Code) fetches:
 * Protected Resource Metadata (RFC 9728) and Authorization Server Metadata
 * (RFC 8414). The MCP endpoint is the protected resource; this server is its own
 * authorization server, so both live on the same origin.
 */
final class Metadata
{
    public const SCOPE = 'sage';

    /**
     * RFC 9728 — served at /.well-known/oauth-protected-resource (and the
     * path-suffixed variant). `resource` is the canonical MCP endpoint URL.
     *
     * @return array<string, mixed>
     */
    public static function protectedResource(string $origin, string $mcpPath): array
    {
        return [
            'resource' => $origin.$mcpPath,
            'authorization_servers' => [$origin],
            'scopes_supported' => [self::SCOPE],
            'bearer_methods_supported' => ['header'],
        ];
    }

    /**
     * RFC 8414 — served at /.well-known/oauth-authorization-server. Advertises a
     * public client (token_endpoint_auth_method "none") with PKCE S256 and DCR.
     *
     * @return array<string, mixed>
     */
    public static function authorizationServer(string $origin): array
    {
        return [
            'issuer' => $origin,
            'authorization_endpoint' => $origin.'/authorize',
            'token_endpoint' => $origin.'/token',
            'registration_endpoint' => $origin.'/register',
            'response_types_supported' => ['code'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => ['S256'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'scopes_supported' => [self::SCOPE],
        ];
    }
}
