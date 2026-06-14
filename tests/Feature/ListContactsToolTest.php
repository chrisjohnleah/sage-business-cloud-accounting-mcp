<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Tools\ListContactsTool;
use ChrisJohnLeah\SageAccounting\Requests\Contacts\GetContacts;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

it('lists contacts and maps them to JSON-friendly rows', function () {
    $sage = fakeSage([
        GetContacts::class => MockResponse::make([
            '$items' => [
                [
                    'id' => 'c1',
                    'displayed_as' => 'Acme Ltd',
                    'name' => 'Acme Ltd',
                    'reference' => 'ACME',
                    'email' => 'hello@acme.test',
                    'balance' => '125.50',
                    'contact_types' => [['id' => 'CUSTOMER', 'displayed_as' => 'Customer']],
                ],
                ['id' => 'c2', 'displayed_as' => 'Beta LLC', 'name' => 'Beta LLC'],
            ],
            '$total' => 2,
            '$page' => 1,
            '$itemsPerPage' => 20,
            '$next' => null,
        ], 200),
    ]);

    $result = (new ListContactsTool($sage))->handle();

    expect($result['count'])->toBe(2)
        ->and($result['contacts'][0]['id'])->toBe('c1')
        ->and($result['contacts'][0]['name'])->toBe('Acme Ltd')
        ->and($result['contacts'][0]['email'])->toBe('hello@acme.test')
        ->and($result['contacts'][0]['balance'])->toBe(125.5)
        ->and($result['contacts'][0]['contact_types'][0]['id'])->toBe('CUSTOMER')
        ->and($result['contacts'][1]['name'])->toBe('Beta LLC');
});

it('caps the number of contacts returned at the requested limit', function () {
    $items = [];
    for ($i = 0; $i < 5; $i++) {
        $items[] = ['id' => "c{$i}", 'name' => "Contact {$i}"];
    }

    $sage = fakeSage([
        GetContacts::class => MockResponse::make(['$items' => $items, '$next' => null], 200),
    ]);

    $result = (new ListContactsTool($sage))->handle(limit: 2);

    expect($result['count'])->toBe(2)
        ->and($result['limit'])->toBe(2)
        ->and($result['contacts'])->toHaveCount(2);
});

it('stops paginating once the limit is reached, without fetching further pages', function () {
    $calls = 0;

    $sage = fakeSage([
        GetContacts::class => function (PendingRequest $request) use (&$calls) {
            $calls++;

            // Page 1 returns more items than the limit AND advertises a next page.
            // If the tool collected eagerly instead of breaking early, it would
            // follow `$next` and call this mock again.
            return MockResponse::make([
                '$items' => [
                    ['id' => 'c1', 'name' => 'A'],
                    ['id' => 'c2', 'name' => 'B'],
                    ['id' => 'c3', 'name' => 'C'],
                ],
                '$next' => 'https://api.accounting.sage.com/v3.1/contacts?page=2',
            ], 200);
        },
    ]);

    $result = (new ListContactsTool($sage))->handle(limit: 2);

    expect($result['count'])->toBe(2)
        ->and($calls)->toBe(1);
});

it('clamps an out-of-range limit into bounds', function () {
    $sage = fakeSage([
        GetContacts::class => MockResponse::make(['$items' => [], '$next' => null], 200),
    ]);

    $result = (new ListContactsTool($sage))->handle(limit: 9999);

    expect($result['limit'])->toBe(200);
});
