<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\OAuthStore;

function oauthStorePath(): string
{
    return sys_get_temp_dir().'/sage-mcp-oauth-'.uniqid().'/oauth.json';
}

it('registers and reads back a client', function () {
    $store = new OAuthStore(oauthStorePath());

    $client = $store->registerClient(['http://127.0.0.1:5000/callback'], 'Claude Code', 1000);

    expect($client['client_id'])->not->toBe('')
        ->and($store->getClient($client['client_id'])['redirect_uris'])->toBe(['http://127.0.0.1:5000/callback'])
        ->and($store->getClient('does-not-exist'))->toBeNull();
});

it('issues a single-use, TTL-bound authorization code', function () {
    $store = new OAuthStore(oauthStorePath());
    $data = [
        'client_id' => 'c1',
        'redirect_uri' => 'http://127.0.0.1:5000/cb',
        'code_challenge' => 'ch',
        'code_challenge_method' => 'S256',
        'scope' => 'sage',
    ];

    $code = $store->createAuthCode($data, 60, 1000);
    $first = $store->consumeAuthCode($code, 1030);

    expect($first)->not->toBeNull()
        ->and($first['client_id'])->toBe('c1')
        ->and($first)->not->toHaveKey('expires_at')
        ->and($store->consumeAuthCode($code, 1031))->toBeNull();
});

it('expires authorization codes', function () {
    $store = new OAuthStore(oauthStorePath());
    $code = $store->createAuthCode(
        ['client_id' => 'c1', 'redirect_uri' => 'u', 'code_challenge' => 'ch', 'code_challenge_method' => 'S256', 'scope' => 's'],
        60,
        1000,
    );

    expect($store->consumeAuthCode($code, 5000))->toBeNull();
});

it('issues + validates access tokens and rotates refresh tokens', function () {
    $store = new OAuthStore(oauthStorePath());
    $tokens = $store->issueTokens('c1', 3600, 1000);

    expect($store->validateAccessToken($tokens['access_token'], 1500))->toBeTrue()
        ->and($store->validateAccessToken($tokens['access_token'], 99999))->toBeFalse()
        ->and($store->validateAccessToken('bogus-token', 1500))->toBeFalse()
        ->and($store->consumeRefreshToken($tokens['refresh_token']))->toBe('c1')
        ->and($store->consumeRefreshToken($tokens['refresh_token']))->toBeNull();
});

it('round-trips a pending authorization keyed by bridge state', function () {
    $store = new OAuthStore(oauthStorePath());
    $request = [
        'client_id' => 'c1',
        'redirect_uri' => 'http://127.0.0.1:5000/cb',
        'code_challenge' => 'ch',
        'code_challenge_method' => 'S256',
        'scope' => 'sage',
        'state' => 'claude-state-xyz',
    ];

    $bridge = $store->createPendingAuthorization($request, 300, 1000);
    $got = $store->consumePendingAuthorization($bridge, 1100);

    expect($got)->not->toBeNull()
        ->and($got['state'])->toBe('claude-state-xyz')
        ->and($got['client_id'])->toBe('c1')
        ->and($store->consumePendingAuthorization($bridge, 1100))->toBeNull();
});
