<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Auth\ArrayTokenStore;
use ChrisJohnLeah\SageAccounting\Mcp\ConnectCommand;
use ChrisJohnLeah\SageAccounting\Requests\Businesses\GetBusinesses;
use ChrisJohnLeah\SageAccounting\Sage;
use ChrisJohnLeah\SageAccounting\SageConnector;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\OAuth2\GetAccessTokenRequest;

it('exchanges a code, persists the token, and resolves the business', function () {
    $store = new ArrayTokenStore();

    $connector = new SageConnector(
        clientId: 'id',
        clientSecret: 'secret',
        redirectUri: 'https://app.test/callback',
        scopes: ['full_access'],
    );

    $connector->withMockClient(new MockClient([
        GetAccessTokenRequest::class => MockResponse::make([
            'access_token' => 'new-access-token',
            'refresh_token' => 'new-refresh-token',
            'expires_in' => 3600,
        ], 200),
        GetBusinesses::class => MockResponse::make([
            '$items' => [['id' => 'biz-9', 'name' => 'Connected Co', 'displayed_as' => 'Connected Co']],
            '$next' => null,
        ], 200),
    ]));

    $sage = new Sage($connector, $store);

    $business = ConnectCommand::exchange($sage, 'auth-code-123');

    expect($business)->not->toBeNull()
        ->and($business->id)->toBe('biz-9');

    $stored = $store->get();

    expect($stored)->not->toBeNull()
        ->and($stored->accessToken)->toBe('new-access-token')
        ->and($stored->refreshToken)->toBe('new-refresh-token')
        ->and($stored->businessId)->toBe('biz-9');
});

it('parses code and state from a full redirect URL', function () {
    expect(ConnectCommand::parseCodeAndState('https://app.test/callback?code=ABC&state=XYZ'))
        ->toBe(['ABC', 'XYZ']);
});

it('parses a bare query string', function () {
    expect(ConnectCommand::parseCodeAndState('code=ABC&state=XYZ'))->toBe(['ABC', 'XYZ']);
});

it('treats a bare code as the code with no state', function () {
    expect(ConnectCommand::parseCodeAndState('RAWCODE'))->toBe(['RAWCODE', null]);
});

it('returns nulls for empty input', function () {
    expect(ConnectCommand::parseCodeAndState('   '))->toBe([null, null]);
});
