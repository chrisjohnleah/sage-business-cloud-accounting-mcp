<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Tools;

use ChrisJohnLeah\SageAccounting\Mcp\Support\DtoMapper;
use ChrisJohnLeah\SageAccounting\Sage;

/**
 * MCP tool: get the Sage business this server is connected to.
 */
final class GetBusinessTool
{
    public function __construct(private readonly Sage $sage)
    {
    }

    /**
     * Get the Sage business this server is connected to (the active business).
     *
     * Resolves the business from the connected account and caches its id for all
     * subsequent calls. Use this to confirm which company the server is acting on.
     *
     * @return array<string, mixed>
     */
    public function handle(): array
    {
        $business = $this->sage->resolveBusiness();

        if ($business === null) {
            return [
                'business' => null,
                'message' => 'No Sage business is accessible for the connected account.',
            ];
        }

        return ['business' => DtoMapper::business($business)];
    }
}
