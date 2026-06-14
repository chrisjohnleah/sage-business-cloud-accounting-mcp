<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp;

use ChrisJohnLeah\SageAccounting\Data\Business;
use ChrisJohnLeah\SageAccounting\Sage;
use Throwable;

/**
 * One-time OAuth "connect" flow for the MCP server.
 *
 * Prints the Sage authorization URL, reads back the redirect `code`, exchanges
 * it for tokens (which the SDK persists to the FileTokenStore automatically),
 * then resolves the active business so its id is cached alongside the tokens.
 *
 * Mirrors the Laravel bridge's `sage:connect` / exchangeCode flow, but reads the
 * code from STDIN so it works for the self-contained, file-backed server.
 */
final class ConnectCommand
{
    /**
     * @param  string|null  $input  An authorization code or full redirect URL supplied non-interactively; when null, the command prompts for it.
     */
    public static function run(?string $input = null): int
    {
        $sage = SageClientFactory::fromEnvironment();

        $callbackState = null;
        $expectedState = null;

        if ($input === null) {
            $url = $sage->authorizationUrl();
            $expectedState = $sage->generatedState();

            fwrite(STDOUT, "Open this URL in your browser and authorise access to Sage:\n\n  {$url}\n\n");
            fwrite(STDOUT, "After authorising, Sage redirects to your redirect URI with a `code` parameter.\n");
            fwrite(STDOUT, "Paste the full redirect URL (or just the code) and press Enter:\n> ");

            $line = self::readLine();

            if ($line === '') {
                fwrite(STDERR, "No code provided — aborting.\n");

                return 1;
            }

            [$code, $callbackState] = self::parseCodeAndState($line);
        } else {
            // Non-interactive: the code/URL came from a previous process, so there
            // is no same-process state to verify CSRF against.
            [$code] = self::parseCodeAndState($input);
        }

        if ($code === null) {
            fwrite(STDERR, "Could not find an authorization code in the input — aborting.\n");

            return 1;
        }

        try {
            $business = self::exchange($sage, $code, $callbackState, $expectedState);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Failed to connect to Sage: '.$exception->getMessage()."\n");

            return 1;
        }

        fwrite(STDOUT, "\n✓ Connected to Sage. Token saved to ".SageClientFactory::tokenPath()."\n");

        if ($business !== null) {
            $label = $business->displayedAs ?? $business->name ?? $business->id ?? 'unknown';
            fwrite(STDOUT, "Active business: {$label}\n");
        } else {
            fwrite(STDOUT, "Warning: connected, but no accessible business was found for this account.\n");
        }

        return 0;
    }

    /**
     * Exchange an authorization code for tokens (persisted by the SDK) and resolve
     * the active business. Extracted from run() so it can be tested without I/O.
     */
    public static function exchange(
        Sage $sage,
        string $code,
        ?string $callbackState = null,
        ?string $expectedState = null,
    ): ?Business {
        if ($callbackState !== null && $expectedState !== null) {
            $sage->exchangeCode($code, $callbackState, $expectedState);
        } else {
            $sage->exchangeCode($code);
        }

        return $sage->resolveBusiness();
    }

    /**
     * Extract the authorization code (and CSRF state, if present) from either a
     * full redirect URL, a bare query string, or a raw code.
     *
     * @return array{0: ?string, 1: ?string} [code, state]
     */
    public static function parseCodeAndState(string $input): array
    {
        $input = trim($input);

        if ($input === '') {
            return [null, null];
        }

        if (str_contains($input, '=')) {
            if (str_contains($input, '?')) {
                $parsed = parse_url($input, PHP_URL_QUERY);
                $query = is_string($parsed) ? $parsed : '';
            } else {
                $query = $input;
            }

            $params = [];
            parse_str($query, $params);

            $code = isset($params['code']) && is_string($params['code']) && $params['code'] !== '' ? $params['code'] : null;
            $state = isset($params['state']) && is_string($params['state']) && $params['state'] !== '' ? $params['state'] : null;

            if ($code !== null) {
                return [$code, $state];
            }
        }

        return [$input, null];
    }

    private static function readLine(): string
    {
        $line = fgets(STDIN);

        return $line === false ? '' : trim($line);
    }
}
