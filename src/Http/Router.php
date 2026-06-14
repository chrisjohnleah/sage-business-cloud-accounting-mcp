<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http;

use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\AuthorizationServer;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\Metadata;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\OAuthStore;

/**
 * Synchronous routing for every endpoint except the MCP proxy: OAuth discovery
 * metadata, dynamic client registration, authorize, the Sage callback, and the
 * token endpoint. The MCP endpoint itself is handled in the server wiring
 * (bearer check here, then an async proxy to the internal MCP transport).
 *
 * Returns HttpResult so it can be unit-tested without ReactPHP.
 */
final class Router
{
    public function __construct(
        private readonly AuthorizationServer $authServer,
        private readonly OAuthStore $store,
        private readonly string $origin,
        private readonly string $mcpPath = '/mcp',
    ) {
    }

    /**
     * Route a non-MCP request.
     *
     * @param  array<string, mixed>  $query
     * @param  array<string, mixed>  $body
     */
    public function route(string $method, string $path, array $query, array $body): HttpResult
    {
        $now = time();

        if ($method === 'GET' && str_starts_with($path, '/.well-known/oauth-protected-resource')) {
            return HttpResult::json(Metadata::protectedResource($this->origin, $this->mcpPath));
        }

        if ($method === 'GET' && str_starts_with($path, '/.well-known/oauth-authorization-server')) {
            return HttpResult::json(Metadata::authorizationServer($this->origin));
        }

        return match (true) {
            $method === 'POST' && $path === '/register' => $this->authServer->register($body, $now),
            $method === 'GET' && $path === '/authorize' => $this->authServer->authorize($query, $now),
            $method === 'GET' && $path === '/sage/callback' => $this->authServer->sageCallback($query, $now),
            $method === 'POST' && $path === '/token' => $this->authServer->token($body, $now),
            $method === 'GET' && $path === '/' => $this->infoPage(),
            default => HttpResult::json(['error' => 'not_found', 'error_description' => "No route for {$method} {$path}."], 404),
        };
    }

    /**
     * Bearer check for the MCP endpoint. Returns a 401 (with the RFC 9728
     * WWW-Authenticate pointer) when the token is missing or invalid, or null
     * when the request is authorized and may proceed to the MCP proxy.
     */
    public function guardMcp(?string $authorizationHeader): ?HttpResult
    {
        $token = self::bearerToken($authorizationHeader);

        if ($token !== null && $this->store->validateAccessToken($token, time())) {
            return null;
        }

        return HttpResult::json(
            ['error' => 'invalid_token', 'error_description' => 'A valid OAuth bearer token is required.'],
            401,
            ['WWW-Authenticate' => 'Bearer resource_metadata="'.$this->origin.'/.well-known/oauth-protected-resource"'],
        );
    }

    public static function bearerToken(?string $authorizationHeader): ?string
    {
        if ($authorizationHeader === null) {
            return null;
        }

        if (preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $matches) !== 1) {
            return null;
        }

        $token = trim($matches[1]);

        return $token === '' ? null : $token;
    }

    private function infoPage(): HttpResult
    {
        $html = '<!doctype html><meta charset="utf-8"><title>Sage MCP</title>'
            .'<body style="font-family:system-ui,sans-serif;margin:3rem;color:#1a1a1a">'
            .'<h1>Sage Accounting MCP</h1>'
            .'<p>OAuth-protected MCP server. Point your MCP client at <code>'.htmlspecialchars($this->origin.$this->mcpPath, ENT_QUOTES).'</code>.</p>'
            .'</body>';

        return HttpResult::html($html);
    }
}
