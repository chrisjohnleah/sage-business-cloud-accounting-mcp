<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Support;

use RuntimeException;

/**
 * Minimal localhost loopback HTTP listener for the OAuth redirect (RFC 8252,
 * "OAuth 2.0 for Native Apps") — the same pattern `gcloud auth login` and
 * `gh auth login` use.
 *
 * The connect flow registers http://127.0.0.1:PORT/callback as the redirect URI,
 * so the Sage authorization code lands here, on a listener the CLI controls,
 * instead of being sent to a production web callback. No browser copy-paste, and
 * nothing ever hits the host application's redirect route.
 */
final class LoopbackServer
{
    /** @var resource */
    private $socket;

    private readonly int $boundPort;

    public function __construct(private readonly string $host = '127.0.0.1', int $port = 0)
    {
        $errno = 0;
        $errstr = '';

        $socket = @stream_socket_server("tcp://{$host}:{$port}", $errno, $errstr);

        if ($socket === false) {
            throw new RuntimeException(
                "Could not start the loopback listener on {$host}:{$port}: {$errstr} (errno {$errno}). "
                .'Is the port already in use? Set SAGE_MCP_CALLBACK_PORT to a free port.',
            );
        }

        $this->socket = $socket;
        $this->boundPort = self::resolveBoundPort($socket, $port);
    }

    public function redirectUri(): string
    {
        return "http://{$this->host}:{$this->boundPort}/callback";
    }

    public function port(): int
    {
        return $this->boundPort;
    }

    /**
     * Block until the OAuth redirect hits /callback with a `code`, reply to the
     * browser, and return the parsed query parameters.
     *
     * @return array{code: string, state: ?string}
     */
    public function awaitCode(int $timeoutSeconds): array
    {
        $deadline = time() + $timeoutSeconds;

        while (true) {
            $remaining = $deadline - time();

            if ($remaining <= 0) {
                throw new RuntimeException('Timed out waiting for the Sage authorization redirect.');
            }

            $connection = @stream_socket_accept($this->socket, $remaining);

            if ($connection === false) {
                continue;
            }

            $requestLine = fgets($connection);
            $params = is_string($requestLine) ? self::queryParams(self::requestTarget($requestLine)) : [];

            $code = $params['code'] ?? '';

            if ($code === '') {
                // Ignore favicon requests and stray hits; keep the tab waiting.
                $this->respond($connection, 'Waiting for the Sage authorization redirect…');

                continue;
            }

            $this->respond($connection, 'Sage connected. You can close this tab and return to the terminal.');

            $state = $params['state'] ?? '';

            return ['code' => $code, 'state' => $state === '' ? null : $state];
        }
    }

    public function close(): void
    {
        if (is_resource($this->socket)) {
            fclose($this->socket);
        }
    }

    /**
     * Extract the request target (path?query) from an HTTP request line such as
     * "GET /callback?code=abc&state=xyz HTTP/1.1".
     */
    public static function requestTarget(string $requestLine): string
    {
        $parts = explode(' ', trim($requestLine));

        return $parts[1] ?? '';
    }

    /**
     * @return array<string, string>
     */
    public static function queryParams(string $target): array
    {
        $query = parse_url($target, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return [];
        }

        $parsed = [];
        parse_str($query, $parsed);

        $params = [];

        foreach ($parsed as $key => $value) {
            if (is_string($value)) {
                $params[(string) $key] = $value;
            }
        }

        return $params;
    }

    /**
     * @param  resource  $socket
     */
    private static function resolveBoundPort($socket, int $requestedPort): int
    {
        if ($requestedPort !== 0) {
            return $requestedPort;
        }

        $name = stream_socket_get_name($socket, false);

        if (is_string($name) && ($pos = strrpos($name, ':')) !== false) {
            return (int) substr($name, $pos + 1);
        }

        return $requestedPort;
    }

    /**
     * @param  resource  $connection
     */
    private function respond($connection, string $message): void
    {
        $html = '<!doctype html><meta charset="utf-8"><title>Sage MCP</title>'
            .'<body style="font-family:system-ui,sans-serif;margin:3rem;color:#1a1a1a">'
            .'<h1>Sage MCP</h1><p>'.htmlspecialchars($message, ENT_QUOTES).'</p></body>';

        $response = "HTTP/1.1 200 OK\r\n"
            ."Content-Type: text/html; charset=utf-8\r\n"
            .'Content-Length: '.strlen($html)."\r\n"
            ."Connection: close\r\n\r\n"
            .$html;

        @fwrite($connection, $response);
        @fclose($connection);
    }
}
