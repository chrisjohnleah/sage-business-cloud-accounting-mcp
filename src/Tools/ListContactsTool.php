<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Tools;

use ChrisJohnLeah\SageAccounting\Sage;

/**
 * Example tool shape — lists Sage contacts for the connected business.
 *
 * The implementation pass turns this into a real MCP tool (e.g. with
 * php-mcp/server's #[McpTool] attribute), maps the SDK's typed results into a
 * JSON-serialisable array, and adds input parameters (filters, pagination).
 * Mirror this shape for further tools: list_invoices, get_business, etc.
 */
final class ListContactsTool
{
    /**
     * @return list<array<string, mixed>>
     */
    public function handle(Sage $sage): array
    {
        // TODO(implementation): map the SDK result into JSON-friendly rows, e.g.
        //   foreach ($sage->contacts()->list() as $contact) { $rows[] = [...]; }
        $sage->contacts();

        return [];
    }
}
