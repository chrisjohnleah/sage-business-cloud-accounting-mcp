<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http;

use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\AuthorizationServer;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\OAuthStore;
use ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth\SageBridge;
use ChrisJohnLeah\SageAccounting\Mcp\SageClientFactory;
use ChrisJohnLeah\SageAccounting\Mcp\Server;
use ChrisJohnLeah\SageAccounting\Mcp\Support\StderrLogger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\Loop;
use React\Http\HttpServer as ReactHttpServer;
use React\Http\Message\Response as ReactResponse;
use React\Socket\SocketServer;
use Throwable;

/**
 * The OAuth-protected Streamable HTTP MCP server.
 *
 * One ReactPHP HTTP server hosts both the OAuth authorization-server endpoints
 * (so Claude Code drives the native authenticate/re-authenticate flow) and the
 * bearer-gated MCP endpoint. The OAuth flow bridges to Sage; the MCP endpoint is
 * handled in JSON mode by driving php-mcp's dispatcher.
 */
final class HttpServer
{
    private const string MCP_PATH = '/mcp';

    /** @var array<string, string> */
    private const CORS = [
        'Access-Control-Allow-Origin' => '*',
        'Access-Control-Allow-Methods' => 'GET, POST, OPTIONS',
        'Access-Control-Allow-Headers' => 'Content-Type, Authorization, Mcp-Session-Id, Last-Event-ID',
    ];

    public function __construct(
        private readonly string $host = '127.0.0.1',
        private readonly int $port = 8765,
    ) {
    }

    public function run(): void
    {
        $logger = new StderrLogger();
        $origin = "http://{$this->host}:{$this->port}";

        $sage = SageClientFactory::fromEnvironment();
        $mcp = new McpHandler(Server::build($sage, SageClientFactory::hasFullAccess()), $logger);

        $store = new OAuthStore(SageClientFactory::oauthStatePath());
        $router = new Router(new AuthorizationServer($store, new SageBridge(), $origin), $store, $origin, self::MCP_PATH);

        $http = new ReactHttpServer(
            fn (ServerRequestInterface $request): ResponseInterface => $this->handle($request, $router, $mcp),
        );

        $socket = new SocketServer("{$this->host}:{$this->port}");
        $http->listen($socket);

        $logger->info("Sage MCP (HTTP) listening on {$origin}");
        $logger->info("MCP endpoint: {$origin}".self::MCP_PATH);
        $logger->info("Register this Sage redirect URI: {$origin}/sage/callback");

        Loop::get()->run();
    }

    private function handle(ServerRequestInterface $request, Router $router, McpHandler $mcp): ResponseInterface
    {
        $method = $request->getMethod();
        $path = $request->getUri()->getPath();

        if ($method === 'OPTIONS') {
            return new ReactResponse(204, self::CORS, '');
        }

        try {
            if ($path === self::MCP_PATH) {
                if ($method !== 'POST') {
                    return $this->render(new HttpResult(405, ['Allow' => 'POST, OPTIONS'], ''));
                }

                $guard = $router->guardMcp($request->getHeaderLine('Authorization') ?: null);

                if ($guard !== null) {
                    return $this->render($guard);
                }

                return $this->render($mcp->handle((string) $request->getBody()));
            }

            $result = $router->route(
                $method,
                $path,
                self::stringKeyed($request->getQueryParams()),
                $this->parseBody($request),
            );

            return $this->render($result);
        } catch (Throwable $e) {
            return $this->render(HttpResult::json(['error' => 'server_error', 'error_description' => $e->getMessage()], 500));
        }
    }

    private function render(HttpResult $result): ResponseInterface
    {
        return new ReactResponse($result->status, $result->headers + self::CORS, $result->body);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $contentType = $request->getHeaderLine('Content-Type');

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode((string) $request->getBody(), true);

            return self::stringKeyed($decoded);
        }

        $parsed = $request->getParsedBody();

        if (is_array($parsed)) {
            return self::stringKeyed($parsed);
        }

        $fields = [];
        parse_str((string) $request->getBody(), $fields);

        return self::stringKeyed($fields);
    }

    /**
     * @return array<string, mixed>
     */
    private static function stringKeyed(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $result = [];
        foreach ($value as $key => $item) {
            $result[(string) $key] = $item;
        }

        return $result;
    }
}
