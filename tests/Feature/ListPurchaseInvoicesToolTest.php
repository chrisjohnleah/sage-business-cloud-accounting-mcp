<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Tools\ListPurchaseInvoicesTool;
use ChrisJohnLeah\SageAccounting\Requests\PurchaseInvoices\GetPurchaseInvoices;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

it('lists purchase invoices and maps amounts and nested fields', function () {
    $sage = fakeSage([
        GetPurchaseInvoices::class => MockResponse::make([
            '$items' => [
                [
                    'id' => 'inv1',
                    'displayed_as' => 'Invoice PI-1',
                    'reference' => 'PI-1',
                    'vendor_reference' => 'SUP-99',
                    'contact_name' => 'Acme Ltd',
                    'date' => '2026-06-01',
                    'status' => ['id' => 'UNPAID', 'displayed_as' => 'Unpaid'],
                    'net_amount' => '100.00',
                    'tax_amount' => '20.00',
                    'total_amount' => '120.00',
                    'outstanding_amount' => '120.00',
                    'invoice_lines' => [
                        [
                            'description' => 'Widgets',
                            'quantity' => '2',
                            'unit_price' => '50.00',
                            'net_amount' => '100.00',
                            'total_amount' => '120.00',
                            'ledger_account' => ['id' => 'la1', 'displayed_as' => '5000 Purchases'],
                        ],
                    ],
                ],
            ],
            '$total' => 1,
            '$page' => 1,
            '$itemsPerPage' => 20,
            '$next' => null,
        ], 200),
    ]);

    $result = (new ListPurchaseInvoicesTool($sage))->handle();

    expect($result['count'])->toBe(1)
        ->and($result['purchase_invoices'][0]['id'])->toBe('inv1')
        ->and($result['purchase_invoices'][0]['reference'])->toBe('PI-1')
        ->and($result['purchase_invoices'][0]['contact']['name'])->toBe('Acme Ltd')
        ->and($result['purchase_invoices'][0]['status']['id'])->toBe('UNPAID')
        ->and($result['purchase_invoices'][0]['total_amount'])->toBe(120.0)
        ->and($result['purchase_invoices'][0]['invoice_lines'][0]['description'])->toBe('Widgets')
        ->and($result['purchase_invoices'][0]['invoice_lines'][0]['ledger_account']['id'])->toBe('la1');
});

it('stops paginating once the limit is reached, without fetching further pages', function () {
    $calls = 0;

    $sage = fakeSage([
        GetPurchaseInvoices::class => function (PendingRequest $request) use (&$calls) {
            $calls++;

            return MockResponse::make([
                '$items' => [
                    ['id' => 'inv1', 'reference' => 'PI-1'],
                    ['id' => 'inv2', 'reference' => 'PI-2'],
                    ['id' => 'inv3', 'reference' => 'PI-3'],
                ],
                '$next' => 'https://api.accounting.sage.com/v3.1/purchase_invoices?page=2',
            ], 200);
        },
    ]);

    $result = (new ListPurchaseInvoicesTool($sage))->handle(limit: 2);

    expect($result['count'])->toBe(2)
        ->and($calls)->toBe(1);
});

it('returns an empty list when there are no invoices', function () {
    $sage = fakeSage([
        GetPurchaseInvoices::class => MockResponse::make(['$items' => [], '$next' => null], 200),
    ]);

    $result = (new ListPurchaseInvoicesTool($sage))->handle(status_id: 'PAID');

    expect($result['count'])->toBe(0)
        ->and($result['purchase_invoices'])->toBe([]);
});
