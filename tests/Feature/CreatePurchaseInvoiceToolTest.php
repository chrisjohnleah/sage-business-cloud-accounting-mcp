<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Tools\CreatePurchaseInvoiceTool;
use ChrisJohnLeah\SageAccounting\Requests\PurchaseInvoices\PostPurchaseInvoices;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\PendingRequest;

it('creates a purchase invoice and returns the mapped result', function () {
    $sage = fakeSage([
        PostPurchaseInvoices::class => MockResponse::make([
            'id' => 'pi-new',
            'displayed_as' => 'Invoice PI-9',
            'reference' => 'PI-9',
            'contact_name' => 'Acme Ltd',
            'total_amount' => '120.00',
            'invoice_lines' => [
                ['description' => 'Widgets', 'quantity' => '2', 'unit_price' => '50.00', 'total_amount' => '120.00'],
            ],
        ], 201),
    ]);

    $result = (new CreatePurchaseInvoiceTool($sage))->handle(
        contact_id: 'c1',
        date: '2026-06-14',
        invoice_lines: [
            [
                'description' => 'Widgets',
                'ledger_account_id' => 'la1',
                'quantity' => 2,
                'unit_price' => 50.0,
                'tax_rate_id' => 'GB_STANDARD',
            ],
        ],
        reference: 'PI-9',
    );

    expect($result['purchase_invoice']['id'])->toBe('pi-new')
        ->and($result['purchase_invoice']['reference'])->toBe('PI-9')
        ->and($result['purchase_invoice']['total_amount'])->toBe(120.0)
        ->and($result['purchase_invoice']['invoice_lines'][0]['description'])->toBe('Widgets');
});

it('wraps the payload under purchase_invoice with the line items', function () {
    $captured = null;

    $sage = fakeSage([
        PostPurchaseInvoices::class => function (PendingRequest $request) use (&$captured) {
            $captured = $request->body()?->all();

            return MockResponse::make(['id' => 'pi-new'], 201);
        },
    ]);

    (new CreatePurchaseInvoiceTool($sage))->handle(
        contact_id: 'c1',
        date: '2026-06-14',
        invoice_lines: [['description' => 'Widgets', 'ledger_account_id' => 'la1', 'quantity' => 1, 'unit_price' => 10.0]],
    );

    expect($captured)->not->toBeNull()
        ->and($captured['purchase_invoice']['contact_id'])->toBe('c1')
        ->and($captured['purchase_invoice']['date'])->toBe('2026-06-14')
        ->and($captured['purchase_invoice']['invoice_lines'][0]['description'])->toBe('Widgets');
});

it('surfaces Sage validation errors as a runtime exception', function () {
    $sage = fakeSage([
        PostPurchaseInvoices::class => MockResponse::make(['$message' => 'contact_id is required'], 422),
    ]);

    expect(fn () => (new CreatePurchaseInvoiceTool($sage))->handle(
        contact_id: 'missing',
        date: '2026-06-14',
        invoice_lines: [],
    ))->toThrow(RuntimeException::class, 'Sage API error (HTTP 422)');
});
