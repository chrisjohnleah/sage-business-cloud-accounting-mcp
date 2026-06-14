<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Tools;

use ChrisJohnLeah\SageAccounting\Mcp\Support\DtoMapper;
use ChrisJohnLeah\SageAccounting\Sage;

/**
 * MCP tool: list purchase (supplier) invoices for the connected business.
 */
final class ListPurchaseInvoicesTool
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 200;

    public function __construct(private readonly Sage $sage)
    {
    }

    /**
     * List purchase (supplier) invoices for the connected Sage business.
     *
     * @param  string|null  $updated_or_created_since  ISO-8601 datetime. Only return invoices created or updated since then — use for incremental sync.
     * @param  string|null  $status_id  Filter by status, e.g. "UNPAID", "PART_PAID" or "PAID".
     * @param  string|null  $contact_id  Return only invoices for this supplier/contact id.
     * @param  string|null  $from_date  Earliest invoice date, ISO-8601 (e.g. "2026-01-01").
     * @param  string|null  $to_date  Latest invoice date, ISO-8601 (e.g. "2026-12-31").
     * @param  int  $limit  Maximum number of invoices to return (1-200). Defaults to 50.
     * @return array<string, mixed>
     */
    public function handle(
        ?string $updated_or_created_since = null,
        ?string $status_id = null,
        ?string $contact_id = null,
        ?string $from_date = null,
        ?string $to_date = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $filters = array_filter([
            'updated_or_created_since' => $updated_or_created_since,
            'status_id' => $status_id,
            'contact_id' => $contact_id,
            'from_date' => $from_date,
            'to_date' => $to_date,
        ], static fn (?string $value): bool => $value !== null);

        $invoices = [];

        foreach ($this->sage->purchaseInvoices()->list($filters) as $invoice) {
            $invoices[] = DtoMapper::purchaseInvoice($invoice);

            if (count($invoices) >= $limit) {
                break;
            }
        }

        return [
            'count' => count($invoices),
            'limit' => $limit,
            'purchase_invoices' => $invoices,
        ];
    }
}
