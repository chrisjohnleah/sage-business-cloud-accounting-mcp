<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http;

use PhpMcp\Schema\JsonRpc\BatchRequest;
use PhpMcp\Schema\JsonRpc\Notification;
use PhpMcp\Schema\JsonRpc\Parser;
use PhpMcp\Schema\JsonRpc\Request;
use PhpMcp\Schema\JsonRpc\Response;
use PhpMcp\Server\Contracts\SessionInterface;
use PhpMcp\Server\Dispatcher;
use PhpMcp\Server\Exception\McpServerException;
use PhpMcp\Server\Server as McpServer;
use PhpMcp\Server\Session\SubscriptionManager;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Handles MCP JSON-RPC requests over HTTP by driving php-mcp's Dispatcher
 * directly (request → typed message → Result → JSON-RPC response). No SSE: a
 * single JSON response per request, which is sufficient for request/response
 * tools and lets the whole MCP layer be unit-tested without the event loop.
 *
 * A bearer token (validated upstream) is required before this runs; the tools
 * themselves read the Sage connection from the Sage token store.
 */
final class McpHandler
{
    private const string SESSION_ID = 'http';

    private readonly Dispatcher $dispatcher;

    public function __construct(
        private readonly McpServer $server,
        LoggerInterface $logger,
    ) {
        $this->dispatcher = new Dispatcher(
            $server->getConfiguration(),
            $server->getRegistry(),
            new SubscriptionManager($logger),
        );

        $server->getSessionManager()->createSession(self::SESSION_ID);
    }

    public function handle(string $rawBody): HttpResult
    {
        try {
            $message = Parser::parse($rawBody);
        } catch (Throwable $e) {
            return self::jsonBody(self::errorArray(null, -32700, 'Parse error: '.$e->getMessage()));
        }

        if ($message instanceof Notification) {
            $this->dispatchNotification($message);

            return new HttpResult(202, [], '');
        }

        if ($message instanceof BatchRequest) {
            foreach ($message->getNotifications() as $notification) {
                $this->dispatchNotification($notification);
            }

            $responses = [];
            foreach ($message->getRequests() as $request) {
                $responses[] = $this->respondTo($request);
            }

            return $responses === [] ? new HttpResult(202, [], '') : self::jsonBody($responses);
        }

        if ($message instanceof Request) {
            return self::jsonBody($this->respondTo($message));
        }

        return self::jsonBody(self::errorArray(null, -32600, 'Unsupported JSON-RPC message.'));
    }

    /**
     * @return array<mixed, mixed>
     */
    private function respondTo(Request $request): array
    {
        $session = $this->session();

        try {
            $result = $this->dispatcher->handleRequest($request, $session);
            $session->save();

            return Response::make($request->id, $result)->jsonSerialize();
        } catch (McpServerException $e) {
            $code = $e->getCode();

            return self::errorArray($request->id, $code !== 0 ? $code : -32603, $e->getMessage());
        } catch (Throwable $e) {
            return self::errorArray($request->id, -32603, 'Internal error: '.$e->getMessage());
        }
    }

    private function dispatchNotification(Notification $notification): void
    {
        try {
            $session = $this->session();
            $this->dispatcher->handleNotification($notification, $session);
            $session->save();
        } catch (Throwable) {
            // Notifications carry no response; nothing to surface.
        }
    }

    private function session(): SessionInterface
    {
        $manager = $this->server->getSessionManager();

        return $manager->getSession(self::SESSION_ID) ?? $manager->createSession(self::SESSION_ID);
    }

    /**
     * @return array<string, mixed>
     */
    private static function errorArray(string|int|null $id, int $code, string $message): array
    {
        return [
            'jsonrpc' => '2.0',
            'id' => $id,
            'error' => ['code' => $code, 'message' => $message],
        ];
    }

    /**
     * @param  array<mixed, mixed>  $payload
     */
    private static function jsonBody(array $payload): HttpResult
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new HttpResult(200, ['Content-Type' => 'application/json'], $body === false ? '{}' : $body);
    }
}
