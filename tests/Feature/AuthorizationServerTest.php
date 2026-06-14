<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Data\Business;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\AuthorizationServer;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\OAuthStore;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\SageBridgeContract;

/**
 * @return array{0: AuthorizationServer, 1: OAuthStore, 2: object}
 */
function authServer(): array
{
    $store = new OAuthStore(sys_get_temp_dir().'/sage-mcp-as-'.uniqid().'/oauth.json');

    $bridge = new class () implements SageBridgeContract {
        /** @var list<string> */
        public array $exchanged = [];

        public function authorizationUrl(string $redirectUri, string $state): string
        {
            return 'https://sage.test/auth?redirect_uri='.rawurlencode($redirectUri).'&state='.$state;
        }

        public function exchange(string $redirectUri, string $code): ?Business
        {
            $this->exchanged[] = $code;

            return null;
        }
    };

    return [new AuthorizationServer($store, $bridge, 'http://127.0.0.1:8765'), $store, $bridge];
}

/**
 * @return array<string, mixed>
 */
function queryOf(string $location): array
{
    $query = parse_url($location, PHP_URL_QUERY);
    $params = [];

    if (is_string($query)) {
        parse_str($query, $params);
    }

    return $params;
}

it('registers a client and rejects metadata with no redirect_uris', function () {
    [$server] = authServer();

    $ok = $server->register(['redirect_uris' => ['http://127.0.0.1:5599/callback'], 'client_name' => 'Claude Code'], 1000);
    expect($ok->status)->toBe(201);
    $body = json_decode($ok->body, true);
    expect($body['client_id'])->not->toBe('')
        ->and($body['token_endpoint_auth_method'])->toBe('none');

    $bad = $server->register(['client_name' => 'x'], 1000);
    expect($bad->status)->toBe(400);
});

it('rejects authorize for an unknown client or unregistered redirect_uri', function () {
    [$server, $store] = authServer();
    $client = $store->registerClient(['http://127.0.0.1:5599/callback'], 'Claude', 1000);

    expect($server->authorize(['client_id' => 'nope'], 1000)->status)->toBe(400)
        ->and($server->authorize([
            'client_id' => $client['client_id'],
            'redirect_uri' => 'http://evil.test/cb',
            'response_type' => 'code',
            'code_challenge' => 'x',
        ], 1000)->status)->toBe(400);
});

it('runs the full authorize -> Sage -> token + refresh flow with PKCE', function () {
    [$server, $store, $bridge] = authServer();

    $verifier = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz-ABCD';
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $redirect = 'http://127.0.0.1:5599/callback';

    $client = $store->registerClient([$redirect], 'Claude', 1000);
    $clientId = $client['client_id'];

    // 1. authorize -> 302 to Sage, carrying our bridge state
    $auth = $server->authorize([
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
        'response_type' => 'code',
        'code_challenge' => $challenge,
        'code_challenge_method' => 'S256',
        'state' => 'claude-state',
        'scope' => 'sage',
    ], 1000);

    expect($auth->status)->toBe(302)
        ->and($auth->headers['Location'])->toContain('sage.test/auth');

    $bridgeState = queryOf($auth->headers['Location'])['state'];
    expect($bridgeState)->toBeString()->not->toBe('');

    // 2. Sage redirects back -> 302 to the client with our code + claude state
    $callback = $server->sageCallback(['code' => 'SAGE-CODE', 'state' => $bridgeState], 1001);

    expect($callback->status)->toBe(302)
        ->and($bridge->exchanged)->toBe(['SAGE-CODE']);

    $callbackParams = queryOf($callback->headers['Location']);
    expect($callbackParams['state'])->toBe('claude-state');
    $ourCode = $callbackParams['code'];

    // 3. token exchange with the correct verifier
    $token = $server->token([
        'grant_type' => 'authorization_code',
        'code' => $ourCode,
        'code_verifier' => $verifier,
        'client_id' => $clientId,
        'redirect_uri' => $redirect,
    ], 1002);

    expect($token->status)->toBe(200);
    $tokenBody = json_decode($token->body, true);
    expect($tokenBody['token_type'])->toBe('Bearer')
        ->and($tokenBody['access_token'])->toBeString()->not->toBe('')
        ->and($tokenBody['refresh_token'])->toBeString()->not->toBe('')
        ->and($store->validateAccessToken($tokenBody['access_token'], 1002))->toBeTrue();

    // 4. refresh works
    $refresh = $server->token(['grant_type' => 'refresh_token', 'refresh_token' => $tokenBody['refresh_token']], 1003);
    expect($refresh->status)->toBe(200)
        ->and(json_decode($refresh->body, true)['access_token'])->toBeString();
});

it('rejects the token exchange when PKCE fails or the code is reused', function () {
    [$server, $store] = authServer();
    $verifier = 'verifier-0123456789-abcdefghijklmnopqrstuvwxyz-ABCD';
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    $redirect = 'http://127.0.0.1:5599/callback';
    $clientId = $store->registerClient([$redirect], 'Claude', 1000)['client_id'];

    $auth = $server->authorize([
        'client_id' => $clientId, 'redirect_uri' => $redirect, 'response_type' => 'code',
        'code_challenge' => $challenge, 'code_challenge_method' => 'S256', 'state' => 's', 'scope' => 'sage',
    ], 1000);
    $bridgeState = queryOf($auth->headers['Location'])['state'];
    $ourCode = queryOf($server->sageCallback(['code' => 'SAGE', 'state' => $bridgeState], 1001)->headers['Location'])['code'];

    // wrong verifier
    expect($server->token([
        'grant_type' => 'authorization_code', 'code' => $ourCode, 'code_verifier' => 'WRONG', 'client_id' => $clientId, 'redirect_uri' => $redirect,
    ], 1002)->status)->toBe(400);

    // correct verifier but the code was already consumed by the failed attempt -> invalid_grant
    expect($server->token([
        'grant_type' => 'authorization_code', 'code' => $ourCode, 'code_verifier' => $verifier, 'client_id' => $clientId, 'redirect_uri' => $redirect,
    ], 1002)->status)->toBe(400);
});
