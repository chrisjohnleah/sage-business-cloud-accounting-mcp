<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Tools\GetBusinessTool;
use ChrisJohnLeah\SageAccounting\Requests\Businesses\GetBusinesses;
use Saloon\Http\Faking\MockResponse;

it('resolves and maps the connected business', function () {
    $sage = fakeSage([
        GetBusinesses::class => MockResponse::make([
            '$items' => [
                [
                    'id' => 'biz-1',
                    'displayed_as' => 'My Company Ltd',
                    'name' => 'My Company Ltd',
                    'address_line_1' => '1 High Street',
                    'city' => 'London',
                    'postal_code' => 'E1 1AA',
                    'country' => ['id' => 'GB', 'displayed_as' => 'United Kingdom'],
                    'website' => 'https://my.test',
                ],
            ],
            '$total' => 1,
            '$page' => 1,
            '$itemsPerPage' => 20,
            '$next' => null,
        ], 200),
    ]);

    $result = (new GetBusinessTool($sage))->handle();

    expect($result['business']['id'])->toBe('biz-1')
        ->and($result['business']['name'])->toBe('My Company Ltd')
        ->and($result['business']['city'])->toBe('London')
        ->and($result['business']['country']['id'])->toBe('GB')
        ->and($result['business']['website'])->toBe('https://my.test');
});

it('reports when no business is accessible', function () {
    $sage = fakeSage([
        GetBusinesses::class => MockResponse::make(['$items' => [], '$next' => null], 200),
    ]);

    $result = (new GetBusinessTool($sage))->handle();

    expect($result['business'])->toBeNull()
        ->and($result['message'])->toContain('No Sage business');
});
