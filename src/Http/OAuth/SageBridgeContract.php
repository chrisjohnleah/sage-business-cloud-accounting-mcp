<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth;

use ChrisJohnLeah\SageAccounting\Data\Business;

/**
 * The upstream side of the OAuth bridge. Defined as an interface so the
 * authorization server can be unit-tested with a stub instead of real Sage HTTP.
 */
interface SageBridgeContract
{
    /**
     * Build the Sage authorization URL, sending `state` (our bridge state) so
     * Sage echoes it back to /sage/callback for correlation.
     */
    public function authorizationUrl(string $redirectUri, string $state): string;

    /**
     * Exchange the Sage authorization code, persisting the Sage token via the
     * normal token store, and resolve the active business.
     */
    public function exchange(string $redirectUri, string $code): ?Business;
}
