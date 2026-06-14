<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Support\LoopbackServer;

it('parses the request target from an HTTP request line', function () {
    expect(LoopbackServer::requestTarget("GET /callback?code=ABC&state=xyz HTTP/1.1\r\n"))
        ->toBe('/callback?code=ABC&state=xyz')
        ->and(LoopbackServer::requestTarget('garbage'))->toBe('');
});

it('parses query params from a request target', function () {
    expect(LoopbackServer::queryParams('/callback?code=ABC&state=xyz'))
        ->toBe(['code' => 'ABC', 'state' => 'xyz'])
        ->and(LoopbackServer::queryParams('/callback'))->toBe([]);
});

it('binds an ephemeral port and exposes a loopback redirect uri', function () {
    $server = new LoopbackServer('127.0.0.1', 0);

    try {
        expect($server->port())->toBeGreaterThan(0)
            ->and($server->redirectUri())->toBe("http://127.0.0.1:{$server->port()}/callback");
    } finally {
        $server->close();
    }
});

it('captures the authorization code from a loopback redirect', function () {
    $server = new LoopbackServer('127.0.0.1', 0);
    $port = $server->port();

    $client = stream_socket_client("tcp://127.0.0.1:{$port}", $errno, $errstr, 2);
    expect($client)->not->toBeFalse();

    fwrite($client, "GET /callback?code=ABC123&state=xyz HTTP/1.1\r\nHost: 127.0.0.1\r\n\r\n");

    $result = $server->awaitCode(5);

    $response = stream_get_contents($client);
    fclose($client);
    $server->close();

    expect($result)->toBe(['code' => 'ABC123', 'state' => 'xyz'])
        ->and($response)->toContain('Sage connected');
});
