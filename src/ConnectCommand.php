<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp;

use ChrisJohnLeah\SageAccounting\Data\Business;
use ChrisJohnLeah\SageAccounting\Mcp\Support\LoopbackConnector;
use ChrisJohnLeah\SageAccounting\Sage;
use Throwable;

/**
 * One-time OAuth "connect" flow for the MCP server.
 *
 * By default this uses an RFC 8252 ("OAuth 2.0 for Native Apps") loopback
 * redirect — the same approach `gcloud auth login` / `gh auth login` use. The
 * command starts a short-lived listener on http://127.0.0.1:PORT/callback, opens
 * the browser, and catches the authorization code there, so the redirect never
 * touches the host application's production callback. Claude Code does not
 * broker OAuth for stdio MCP servers, so the server owns this flow.
 *
 * Fallbacks: `connect --manual` prints the URL and reads a pasted code/redirect
 * URL from STDIN (uses the configured SAGE_REDIRECT_URI); `connect <code|url>`
 * exchanges a code non-interactively.
 *
 * The SDK persists tokens via the configured TokenStore (FileTokenStore).
 */
final class ConnectCommand
{
    private const int DEFAULT_CALLBACK_PORT = 8765;

    private const int CALLBACK_TIMEOUT_SECONDS = 300;

    /**
     * @param  list<string>  $args  CLI arguments after the `connect` sub-command.
     */
    public static function run(array $args = []): int
    {
        $positional = self::firstPositional($args);

        if ($positional !== null) {
            return self::runWithCode($positional);
        }

        if (in_array('--manual', $args, true)) {
            return self::runManual();
        }

        return self::runLoopback(self::resolvePort($args));
    }

    /**
     * Loopback flow (default): catch the redirect on a local listener.
     */
    private static function runLoopback(int $port): int
    {
        try {
            $result = LoopbackConnector::connect(
                $port,
                self::CALLBACK_TIMEOUT_SECONDS,
                static function (string $authUrl, string $redirectUri): void {
                    fwrite(STDOUT, "Register this redirect URI in your Sage Developer app (exact match required):\n\n  {$redirectUri}\n\n");
                    fwrite(STDOUT, "Opening your browser to authorise Sage. If it does not open, visit:\n\n  {$authUrl}\n\n");
                    fwrite(STDOUT, "Listening on {$redirectUri} for the redirect…\n");
                },
            );
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Failed to connect to Sage: '.$exception->getMessage()."\n");

            return 1;
        }

        self::reportSuccess($result['business']);

        return 0;
    }

    /**
     * Manual flow: print the URL and read a pasted code/redirect URL from STDIN.
     */
    private static function runManual(): int
    {
        $sage = SageClientFactory::fromEnvironment();

        $url = $sage->authorizationUrl();
        $expectedState = $sage->generatedState();

        fwrite(STDOUT, "Open this URL in your browser and authorise access to Sage:\n\n  {$url}\n\n");
        fwrite(STDOUT, "After authorising, Sage redirects to your configured redirect URI with a `code` parameter.\n");
        fwrite(STDOUT, "Paste the full redirect URL (or just the code) and press Enter:\n> ");

        $line = self::readLine();

        if ($line === '') {
            fwrite(STDERR, "No code provided — aborting.\n");

            return 1;
        }

        [$code, $callbackState] = self::parseCodeAndState($line);

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

        self::reportSuccess($business);

        return 0;
    }

    /**
     * Non-interactive: exchange a code (or redirect URL) supplied as an argument.
     */
    private static function runWithCode(string $input): int
    {
        [$code] = self::parseCodeAndState($input);

        if ($code === null) {
            fwrite(STDERR, "Could not find an authorization code in the input — aborting.\n");

            return 1;
        }

        $sage = SageClientFactory::fromEnvironment();

        try {
            $business = self::exchange($sage, $code);
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Failed to connect to Sage: '.$exception->getMessage()."\n");

            return 1;
        }

        self::reportSuccess($business);

        return 0;
    }

    /**
     * Exchange an authorization code for tokens (persisted by the SDK) and resolve
     * the active business. Extracted so it can be tested without I/O.
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

    /**
     * Resolve the loopback callback port: --port=N, then SAGE_MCP_CALLBACK_PORT,
     * then the default. Must match the redirect URI registered with Sage.
     *
     * @param  list<string>  $args
     */
    public static function resolvePort(array $args): int
    {
        foreach ($args as $arg) {
            if (str_starts_with($arg, '--port=')) {
                $value = substr($arg, strlen('--port='));

                if ($value !== '' && ctype_digit($value)) {
                    return (int) $value;
                }
            }
        }

        $env = getenv('SAGE_MCP_CALLBACK_PORT');

        if (is_string($env) && $env !== '' && ctype_digit($env)) {
            return (int) $env;
        }

        return self::DEFAULT_CALLBACK_PORT;
    }

    /**
     * The first argument that is not a flag (used as the authorization code/URL).
     *
     * @param  list<string>  $args
     */
    public static function firstPositional(array $args): ?string
    {
        foreach ($args as $arg) {
            if (! str_starts_with($arg, '--')) {
                return $arg;
            }
        }

        return null;
    }

    private static function reportSuccess(?Business $business): void
    {
        fwrite(STDOUT, "\n✓ Connected to Sage. Token saved to ".SageClientFactory::tokenPath()."\n");

        if ($business !== null) {
            $label = $business->displayedAs ?? $business->name ?? $business->id ?? 'unknown';
            fwrite(STDOUT, "Active business: {$label}\n");
        } else {
            fwrite(STDOUT, "Warning: connected, but no accessible business was found for this account.\n");
        }
    }

    private static function readLine(): string
    {
        $line = fgets(STDIN);

        return $line === false ? '' : trim($line);
    }
}
