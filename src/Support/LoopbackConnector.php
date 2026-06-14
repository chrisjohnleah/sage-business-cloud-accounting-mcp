<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Support;

use ChrisJohnLeah\SageAccounting\Data\Business;
use ChrisJohnLeah\SageAccounting\Mcp\SageClientFactory;

/**
 * Runs the RFC 8252 loopback OAuth flow end to end: open a localhost listener,
 * send the user to Sage in their browser, catch the authorization code on the
 * loopback, exchange + persist the token, and resolve the active business.
 *
 * Shared by the `connect` CLI command and the `sage_connect` MCP tool. It writes
 * nothing to STDOUT (safe to call from inside an MCP tool handler); callers that
 * want to print the URL pass an $onReady callback.
 */
final class LoopbackConnector
{
    /**
     * @param  (callable(string $authUrl, string $redirectUri): void)|null  $onReady  invoked once the auth URL is known, before the browser is opened
     * @return array{business: ?Business, redirectUri: string, authUrl: string}
     */
    public static function connect(int $port, int $timeoutSeconds, ?callable $onReady = null): array
    {
        $listener = new LoopbackServer('127.0.0.1', $port);

        try {
            $redirectUri = $listener->redirectUri();
            $sage = SageClientFactory::fromEnvironment(null, $redirectUri);
            $authUrl = $sage->authorizationUrl();
            $expectedState = $sage->generatedState();

            if ($onReady !== null) {
                $onReady($authUrl, $redirectUri);
            }

            self::openBrowser($authUrl);

            $callback = $listener->awaitCode($timeoutSeconds);

            if ($callback['state'] !== null && $expectedState !== null) {
                $sage->exchangeCode($callback['code'], $callback['state'], $expectedState);
            } else {
                $sage->exchangeCode($callback['code']);
            }

            return [
                'business' => $sage->resolveBusiness(),
                'redirectUri' => $redirectUri,
                'authUrl' => $authUrl,
            ];
        } finally {
            $listener->close();
        }
    }

    public static function openBrowser(string $url): void
    {
        $argument = escapeshellarg($url);

        $command = match (PHP_OS_FAMILY) {
            'Darwin' => "open {$argument}",
            'Windows' => "start \"\" {$argument}",
            default => "xdg-open {$argument}",
        };

        if (PHP_OS_FAMILY === 'Windows') {
            @exec($command);
        } else {
            @exec($command.' > /dev/null 2>&1 &');
        }
    }
}
