<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Tools\CreateContactTool;
use ChrisJohnLeah\SageAccounting\Requests\Contacts\PostContacts;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

it('creates a contact and returns the mapped result', function () {
    $sage = fakeSage([
        PostContacts::class => MockResponse::make([
            'id' => 'c-new',
            'displayed_as' => 'New Supplier Ltd',
            'name' => 'New Supplier Ltd',
            'reference' => 'NEW',
            'email' => 'ap@newsupplier.test',
        ], 201),
    ]);

    $result = (new CreateContactTool($sage))->handle(
        name: 'New Supplier Ltd',
        contact_type_id: 'VENDOR',
        reference: 'NEW',
        email: 'ap@newsupplier.test',
    );

    expect($result['contact']['id'])->toBe('c-new')
        ->and($result['contact']['name'])->toBe('New Supplier Ltd')
        ->and($result['contact']['email'])->toBe('ap@newsupplier.test');
});

it('sends the contact wrapped under the contact key with contact_type_ids', function () {
    $captured = null;

    $sage = fakeSage([
        PostContacts::class => function (PendingRequest $request) use (&$captured) {
            $captured = $request->body()?->all();

            return MockResponse::make(['id' => 'c-new', 'name' => 'Acme'], 201);
        },
    ]);

    (new CreateContactTool($sage))->handle(name: 'Acme', contact_type_id: 'CUSTOMER');

    expect($captured)->not->toBeNull()
        ->and($captured['contact']['name'])->toBe('Acme')
        ->and($captured['contact']['contact_type_ids'])->toBe(['CUSTOMER']);
});

it('surfaces Sage validation errors as a runtime exception', function () {
    $sage = fakeSage([
        PostContacts::class => MockResponse::make([
            '$severity' => 'error',
            '$message' => 'name is required',
        ], 422),
    ]);

    expect(fn () => (new CreateContactTool($sage))->handle(name: '', contact_type_id: 'CUSTOMER'))
        ->toThrow(RuntimeException::class, 'Sage API error (HTTP 422)');
});
