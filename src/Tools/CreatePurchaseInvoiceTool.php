<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Tools;

use ChrisJohnLeah\SageAccounting\Data\PurchaseInvoice;
use ChrisJohnLeah\SageAccounting\Mcp\Support\ApiError;
use ChrisJohnLeah\SageAccounting\Mcp\Support\DtoMapper;
use ChrisJohnLeah\SageAccounting\Requests\PurchaseInvoices\PostPurchaseInvoices;
use ChrisJohnLeah\SageAccounting\Sage;
use RuntimeException;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;

/**
 * MCP tool (WRITE — requires the `full_access` OAuth scope): create a purchase
 * (supplier) invoice.
 *
 * Registered by the server only when SAGE_SCOPES includes `full_access`.
 */
final class CreatePurchaseInvoiceTool
{
    public function __construct(private readonly Sage $sage)
    {
    }

    /**
     * Create a purchase (supplier) invoice in the connected Sage business.
     *
     * Requires the `full_access` OAuth scope.
     *
     * @param  string  $contact_id  The Sage id of the supplier/contact being billed.
     * @param  string  $date  Invoice date, ISO-8601 (e.g. "2026-06-14").
     * @param  array<int, array<string, mixed>>  $invoice_lines  One or more line items. Each line should provide: description, ledger_account_id, quantity, unit_price, tax_rate_id. Optional per line: unit_price_includes_tax (bool).
     * @param  string|null  $due_date  Payment due date, ISO-8601.
     * @param  string|null  $reference  Your own reference for the invoice.
     * @param  string|null  $vendor_reference  The supplier's own invoice number.
     * @param  string|null  $notes  Free-text notes.
     * @return array<string, mixed>
     */
    public function handle(
        string $contact_id,
        string $date,
        array $invoice_lines,
        ?string $due_date = null,
        ?string $reference = null,
        ?string $vendor_reference = null,
        ?string $notes = null,
    ): array {
        $payload = array_filter([
            'contact_id' => $contact_id,
            'date' => $date,
            'due_date' => $due_date,
            'reference' => $reference,
            'vendor_reference' => $vendor_reference,
            'notes' => $notes,
            'invoice_lines' => array_values($invoice_lines),
        ], static fn (mixed $value): bool => $value !== null);

        try {
            $response = $this->sage->connector()->send(new PostPurchaseInvoices(['purchase_invoice' => $payload]));
            $response->throw();
            $created = $response->dtoOrFail();

            if (! $created instanceof PurchaseInvoice) {
                throw new RuntimeException('Sage did not return the created purchase invoice.');
            }

            return ['purchase_invoice' => DtoMapper::purchaseInvoice($created)];
        } catch (RequestException $exception) {
            throw new RuntimeException(ApiError::message($exception), previous: $exception);
        } catch (FatalRequestException $exception) {
            throw new RuntimeException('Could not reach Sage: '.$exception->getMessage(), previous: $exception);
        }
    }
}
