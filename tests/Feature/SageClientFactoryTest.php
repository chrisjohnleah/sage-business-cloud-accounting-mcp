<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\SageClientFactory;

afterEach(function () {
    putenv('SAGE_SCOPES');
});

it('reports no full access by default', function () {
    putenv('SAGE_SCOPES');

    expect(SageClientFactory::hasFullAccess())->toBeFalse();
});

it('reports no full access for readonly scope', function () {
    putenv('SAGE_SCOPES=readonly');

    expect(SageClientFactory::hasFullAccess())->toBeFalse();
});

it('reports full access when the full_access scope is configured', function () {
    putenv('SAGE_SCOPES=readonly,full_access');

    expect(SageClientFactory::hasFullAccess())->toBeTrue();
});
