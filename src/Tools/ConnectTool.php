<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Tools;

use ChrisJohnLeah\SageAccounting\Mcp\ConnectCommand;
use ChrisJohnLeah\SageAccounting\Mcp\Support\DtoMapper;
use ChrisJohnLeah\SageAccounting\Mcp\Support\LoopbackConnector;
use Throwable;

/**
 * MCP tool: authenticate (or re-authenticate) the server to Sage via OAuth.
 *
 * Opens the user's browser, catches the authorization code on a localhost
 * loopback (RFC 8252), and persists the token — all without leaving the MCP
 * client. This is what makes "the server runs the re-auth flow itself" possible
 * for a stdio server, since the loopback needs no terminal input.
 */
final class ConnectTool
{
    private const int MIN_TIMEOUT = 30;

    private const int MAX_TIMEOUT = 600;

    /**
     * Authenticate this server to Sage. Opens your browser to Sage's consent
     * screen and captures the result automatically. Call this whenever a Sage
     * tool reports that no token is stored / the server is not connected.
     *
     * Requires the loopback redirect URI (default http://127.0.0.1:8765/callback,
     * or SAGE_MCP_CALLBACK_PORT) to be registered in your Sage Developer app.
     *
     * @param  int  $timeout_seconds  How long to wait for you to finish authorising in the browser (30-600). Defaults to 180.
     * @return array<string, mixed>
     */
    public function handle(int $timeout_seconds = 180): array
    {
        $timeout = max(self::MIN_TIMEOUT, min(self::MAX_TIMEOUT, $timeout_seconds));
        $port = ConnectCommand::resolvePort([]);

        $authUrl = null;
        $redirectUri = null;

        try {
            $result = LoopbackConnector::connect(
                $port,
                $timeout,
                static function (string $url, string $redirect) use (&$authUrl, &$redirectUri): void {
                    $authUrl = $url;
                    $redirectUri = $redirect;
                },
            );

            $business = $result['business'];

            return [
                'connected' => true,
                'redirect_uri' => $result['redirectUri'],
                'business' => $business === null ? null : DtoMapper::business($business),
                'message' => $business === null
                    ? 'Connected to Sage, but no accessible business was found for this account.'
                    : 'Connected to Sage.',
            ];
        } catch (Throwable $exception) {
            return [
                'connected' => false,
                'redirect_uri' => $redirectUri,
                'authorize_url' => $authUrl,
                'message' => 'Sage authorisation did not complete: '.$exception->getMessage()
                    .' Ensure '.($redirectUri ?? 'the loopback redirect URI')
                    .' is registered in your Sage Developer app, then try again'
                    .($authUrl !== null ? ' — or open authorize_url manually.' : '.'),
            ];
        }
    }
}
