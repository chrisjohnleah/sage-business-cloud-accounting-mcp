<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Auth\StoredToken;
use ChrisJohnLeah\SageAccounting\Mcp\FileTokenStore;

function tempTokenPath(): string
{
    return sys_get_temp_dir().'/sage-mcp-test-'.uniqid().'/token.json';
}

it('returns null when no token file exists', function () {
    $store = new FileTokenStore(tempTokenPath());

    expect($store->get())->toBeNull();
});

it('persists a token, overwrites on put, and forgets it', function () {
    $store = new FileTokenStore(tempTokenPath());

    $store->put(new StoredToken(
        accessToken: 'access-1',
        refreshToken: 'refresh-1',
        expiresAt: new DateTimeImmutable('2030-01-01T00:00:00+00:00'),
        businessId: 'biz-123',
    ));

    $store->put(new StoredToken(
        accessToken: 'access-2',
        refreshToken: 'refresh-2',
        expiresAt: null,
        businessId: null,
    ));

    $token = $store->get();

    expect($token)->not->toBeNull()
        ->and($token->accessToken)->toBe('access-2')
        ->and($token->refreshToken)->toBe('refresh-2')
        ->and($token->expiresAt)->toBeNull()
        ->and($token->businessId)->toBeNull();

    $store->forget();

    expect($store->get())->toBeNull();
});

it('throws a clear error on a corrupt token file', function () {
    $path = tempTokenPath();
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, '{ this is not valid json');

    $store = new FileTokenStore($path);

    expect(fn () => $store->get())->toThrow(RuntimeException::class, 'Corrupt Sage token file');
});

it('throws a clear error when expires_at is unparseable', function () {
    $path = tempTokenPath();
    @mkdir(dirname($path), 0700, true);
    file_put_contents($path, json_encode([
        'access_token' => 'a',
        'refresh_token' => 'r',
        'expires_at' => 'not-a-date',
    ]));

    $store = new FileTokenStore($path);

    expect(fn () => $store->get())->toThrow(RuntimeException::class, 'Corrupt Sage token file');
});

it('writes the token file with owner-only permissions on POSIX systems', function () {
    if (DIRECTORY_SEPARATOR !== '/') {
        $this->markTestSkipped('chmod is a no-op on Windows.');
    }

    $path = tempTokenPath();
    $store = new FileTokenStore($path);
    $store->put(new StoredToken(accessToken: 'a', refreshToken: 'r'));

    expect(substr(sprintf('%o', fileperms($path)), -4))->toBe('0600');
});
