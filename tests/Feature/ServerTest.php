<?php

declare(strict_types=1);

use ChrisJohnLeah\SageAccounting\Mcp\Server;
use PhpMcp\Server\Server as McpServer;

it('builds a server and registers the read tools', function () {
    $server = Server::build(fakeSage([]));

    expect($server)->toBeInstanceOf(McpServer::class);

    $registry = $server->getRegistry();

    expect($registry->getTool('list_contacts'))->not->toBeNull()
        ->and($registry->getTool('list_purchase_invoices'))->not->toBeNull()
        ->and($registry->getTool('get_business'))->not->toBeNull()
        ->and($registry->getTool('sage_connect'))->not->toBeNull();
});

it('omits write tools when full access is not granted', function () {
    $registry = Server::build(fakeSage([]))->getRegistry();

    expect($registry->getTool('create_contact'))->toBeNull()
        ->and($registry->getTool('create_purchase_invoice'))->toBeNull();
});

it('registers write tools when full access is granted', function () {
    $registry = Server::build(fakeSage([]), fullAccess: true)->getRegistry();

    expect($registry->getTool('create_contact'))->not->toBeNull()
        ->and($registry->getTool('create_purchase_invoice'))->not->toBeNull();
});
