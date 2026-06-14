<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Tools;

use ChrisJohnLeah\SageAccounting\Mcp\Support\DtoMapper;
use ChrisJohnLeah\SageAccounting\Sage;

/**
 * MCP tool: list contacts (customers and suppliers) for the connected business.
 */
final class ListContactsTool
{
    private const int DEFAULT_LIMIT = 50;

    private const int MAX_LIMIT = 200;

    public function __construct(private readonly Sage $sage)
    {
    }

    /**
     * List contacts (customers and suppliers) for the connected Sage business.
     *
     * @param  string|null  $updated_or_created_since  ISO-8601 datetime (e.g. "2026-01-01T00:00:00Z"). Only return contacts created or updated since then — use for incremental sync.
     * @param  string|null  $search  Free-text search across contact name and reference.
     * @param  string|null  $email  Return only contacts with this email address.
     * @param  string|null  $contact_type_id  Filter by contact type, e.g. "CUSTOMER" or "VENDOR".
     * @param  int  $limit  Maximum number of contacts to return (1-200). Defaults to 50.
     * @return array<string, mixed>
     */
    public function handle(
        ?string $updated_or_created_since = null,
        ?string $search = null,
        ?string $email = null,
        ?string $contact_type_id = null,
        int $limit = self::DEFAULT_LIMIT,
    ): array {
        $limit = max(1, min(self::MAX_LIMIT, $limit));

        $filters = array_filter([
            'updated_or_created_since' => $updated_or_created_since,
            'search' => $search,
            'email' => $email,
            'contact_type_id' => $contact_type_id,
        ], static fn (?string $value): bool => $value !== null);

        $contacts = [];

        foreach ($this->sage->contacts()->list($filters) as $contact) {
            $contacts[] = DtoMapper::contact($contact);

            if (count($contacts) >= $limit) {
                break;
            }
        }

        return [
            'count' => count($contacts),
            'limit' => $limit,
            'contacts' => $contacts,
        ];
    }
}
