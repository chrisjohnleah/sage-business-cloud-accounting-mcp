<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Auth\ArrayTokenStore;
use ChrisJohnLeah\SageAccounting\Auth\StoredToken;
use ChrisJohnLeah\SageAccounting\Mcp\Tests\TestCase;
use ChrisJohnLeah\SageAccounting\Sage;
use ChrisJohnLeah\SageAccounting\SageConnector;
use Saloon\Http\Faking\MockClient;

uses(TestCase::class)->in('Feature');

/**
 * Build a Sage client wired to a Saloon MockClient and an in-memory token store,
 * so tools can be exercised against faked HTTP responses with no network.
 *
 * @param  array<class-string, Saloon\Http\Faking\MockResponse|callable>  $mocks  keyed by request class
 */
function fakeSage(array $mocks, ?StoredToken $token = null): Sage
{
    $connector = new SageConnector(
        clientId: 'test-client',
        clientSecret: 'test-secret',
        redirectUri: 'https://app.test/callback',
        scopes: ['full_access'],
    );

    $connector->withMockClient(new MockClient($mocks));

    $token ??= new StoredToken(
        accessToken: 'access-token',
        refreshToken: 'refresh-token',
        expiresAt: new DateTimeImmutable('+1 hour'),
        businessId: 'biz-1',
    );

    return new Sage($connector, new ArrayTokenStore($token));
}
