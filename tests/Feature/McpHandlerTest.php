<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Http\McpHandler;
use ChrisJohnLeah\SageAccounting\Mcp\Server;
use ChrisJohnLeah\SageAccounting\Requests\Contacts\GetContacts;
use PhpMcp\Server\Server as McpServer;
use Psr\Log\NullLogger;
use Saloon\Http\Faking\MockResponse;

function mcpHandler(McpServer $server): McpHandler
{
    return new McpHandler($server, new NullLogger());
}

it('responds to initialize with server info', function () {
    $handler = mcpHandler(Server::build(fakeSage([])));

    $result = $handler->handle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2024-11-05","capabilities":{},"clientInfo":{"name":"t","version":"1"}}}');

    expect($result->status)->toBe(200);
    $body = json_decode($result->body, true);
    expect($body['id'])->toBe(1)
        ->and($body['result']['serverInfo']['name'])->toBe('Sage Accounting MCP');
});

it('accepts the initialized notification with 202 and no body', function () {
    $handler = mcpHandler(Server::build(fakeSage([])));

    $result = $handler->handle('{"jsonrpc":"2.0","method":"notifications/initialized"}');

    expect($result->status)->toBe(202)
        ->and($result->body)->toBe('');
});

it('lists tools over HTTP', function () {
    $handler = mcpHandler(Server::build(fakeSage([])));

    $result = $handler->handle('{"jsonrpc":"2.0","id":2,"method":"tools/list"}');

    expect($result->status)->toBe(200);
    $body = json_decode($result->body, true);
    $names = array_map(static fn (array $t): string => $t['name'], $body['result']['tools']);
    expect($names)->toContain('list_contacts', 'get_business', 'sage_connect');
});

it('calls a tool over HTTP and returns its result', function () {
    $sage = fakeSage([
        GetContacts::class => MockResponse::make([
            '$items' => [['id' => 'c1', 'displayed_as' => 'Acme Ltd', 'name' => 'Acme Ltd']],
            '$next' => null,
        ], 200),
    ]);

    $handler = mcpHandler(Server::build($sage));

    $result = $handler->handle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"list_contacts","arguments":{}}}');

    expect($result->status)->toBe(200)
        ->and($result->body)->toContain('Acme Ltd');
});

it('returns a JSON-RPC parse error for malformed input', function () {
    $handler = mcpHandler(Server::build(fakeSage([])));

    $result = $handler->handle('not json at all');

    expect($result->status)->toBe(200);
    $body = json_decode($result->body, true);
    expect($body['error']['code'])->toBe(-32700);
});
