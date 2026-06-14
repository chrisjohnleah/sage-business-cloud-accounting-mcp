<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\Pkce;

it('verifies a valid S256 challenge and rejects a wrong verifier', function () {
    $verifier = 'a-long-random-code-verifier-0123456789-abcdefghijklmnop';
    $challenge = rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');

    expect(Pkce::verify($verifier, $challenge, 'S256'))->toBeTrue()
        ->and(Pkce::verify('a-different-verifier-9876543210', $challenge, 'S256'))->toBeFalse();
});

it('supports plain and rejects unknown methods and empty input', function () {
    expect(Pkce::verify('abc', 'abc', 'plain'))->toBeTrue()
        ->and(Pkce::verify('abc', 'abc', 'S512'))->toBeFalse()
        ->and(Pkce::verify('', '', 'S256'))->toBeFalse();
});
