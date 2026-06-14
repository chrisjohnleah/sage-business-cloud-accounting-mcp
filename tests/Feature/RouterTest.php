<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Data\Business;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\AuthorizationServer;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\OAuthStore;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\SageBridgeContract;
use ChrisJohnLeah\SageAccounting\Mcp\Http\Router;

/**
 * @return array{0: Router, 1: OAuthStore}
 */
function makeRouter(): array
{
    $store = new OAuthStore(sys_get_temp_dir().'/sage-mcp-router-'.uniqid().'/oauth.json');
    $bridge = new class () implements SageBridgeContract {
        public function authorizationUrl(string $redirectUri, string $state): string
        {
            return 'https://sage.test/auth?state='.$state;
        }

        public function exchange(string $redirectUri, string $code): ?Business
        {
            return null;
        }
    };

    $router = new Router(
        new AuthorizationServer($store, $bridge, 'http://127.0.0.1:8765'),
        $store,
        'http://127.0.0.1:8765',
        '/mcp',
    );

    return [$router, $store];
}

it('serves protected-resource metadata', function () {
    [$router] = makeRouter();
    $result = $router->route('GET', '/.well-known/oauth-protected-resource', [], []);
    $body = json_decode($result->body, true);

    expect($result->status)->toBe(200)
        ->and($body['resource'])->toBe('http://127.0.0.1:8765/mcp')
        ->and($body['authorization_servers'])->toBe(['http://127.0.0.1:8765']);
});

it('serves authorization-server metadata', function () {
    [$router] = makeRouter();
    $result = $router->route('GET', '/.well-known/oauth-authorization-server', [], []);

    expect($result->status)->toBe(200)
        ->and(json_decode($result->body, true)['token_endpoint'])->toBe('http://127.0.0.1:8765/token');
});

it('404s unknown routes', function () {
    [$router] = makeRouter();
    expect($router->route('GET', '/nope', [], [])->status)->toBe(404);
});

it('guards the mcp endpoint with 401 + WWW-Authenticate when unauthenticated', function () {
    [$router] = makeRouter();
    $guard = $router->guardMcp(null);

    expect($guard)->not->toBeNull()
        ->and($guard->status)->toBe(401)
        ->and($guard->headers)->toHaveKey('WWW-Authenticate');
});

it('allows the mcp endpoint with a valid bearer token', function () {
    [$router, $store] = makeRouter();
    $tokens = $store->issueTokens('c', 3600, time());

    expect($router->guardMcp('Bearer '.$tokens['access_token']))->toBeNull();
});

it('parses bearer tokens case-insensitively', function () {
    expect(Router::bearerToken('Bearer abc123'))->toBe('abc123')
        ->and(Router::bearerToken('bearer xyz'))->toBe('xyz')
        ->and(Router::bearerToken('Basic foo'))->toBeNull()
        ->and(Router::bearerToken(null))->toBeNull();
});
