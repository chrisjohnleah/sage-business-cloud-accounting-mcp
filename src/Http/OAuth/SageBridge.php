<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth;

use ChrisJohnLeah\SageAccounting\Data\Business;
use ChrisJohnLeah\SageAccounting\Mcp\SageClientFactory;

/**
 * Live implementation of the OAuth bridge's upstream side: talks to Sage via the
 * core SDK, building the authorization URL and exchanging the code (which the SDK
 * persists to the Sage token store the MCP tools read).
 */
final class SageBridge implements SageBridgeContract
{
    public function authorizationUrl(string $redirectUri, string $state): string
    {
        return SageClientFactory::fromEnvironment(null, $redirectUri)->authorizationUrl($state);
    }

    public function exchange(string $redirectUri, string $code): ?Business
    {
        $sage = SageClientFactory::fromEnvironment(null, $redirectUri);
        $sage->exchangeCode($code);

        return $sage->resolveBusiness();
    }
}
