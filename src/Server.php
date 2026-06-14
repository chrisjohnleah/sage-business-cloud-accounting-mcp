<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp;

use ChrisJohnLeah\SageAccounting\Mcp\Support\StderrLogger;
use ChrisJohnLeah\SageAccounting\Mcp\Tools\CreateContactTool;
use ChrisJohnLeah\SageAccounting\Mcp\Tools\CreatePurchaseInvoiceTool;
use ChrisJohnLeah\SageAccounting\Mcp\Tools\GetBusinessTool;
use ChrisJohnLeah\SageAccounting\Mcp\Tools\ListContactsTool;
use ChrisJohnLeah\SageAccounting\Mcp\Tools\ListPurchaseInvoicesTool;
use ChrisJohnLeah\SageAccounting\Sage;
use PhpMcp\Server\Defaults\BasicContainer;
use PhpMcp\Server\Server as McpServer;
use PhpMcp\Server\Transports\StdioServerTransport;

/**
 * Entry point that builds the MCP server and registers the Sage tools, then
 * serves them over the stdio transport so MCP clients (Claude Desktop, Claude
 * Code, etc.) can call them.
 *
 * Read tools are always registered. Write tools (create_contact,
 * create_purchase_invoice) are only registered when the configured scopes grant
 * `full_access`.
 */
final class Server
{
    private const string NAME = 'Sage Accounting MCP';

    private const string VERSION = '0.1.0';

    /**
     * Build the server, wiring the Sage client from the environment and serve it
     * over stdio. This blocks (runs the event loop) until the input stream closes
     * or the process is signalled.
     */
    public static function run(): void
    {
        $sage = SageClientFactory::fromEnvironment();

        self::build($sage, SageClientFactory::hasFullAccess())
            ->listen(new StdioServerTransport());
    }

    /**
     * Build (but do not start) the MCP server for a given Sage client.
     *
     * Separated from run() so it can be exercised in tests without opening the
     * stdio transport or starting the event loop.
     */
    public static function build(Sage $sage, bool $fullAccess = false): McpServer
    {
        $container = new BasicContainer();
        $container->set(Sage::class, $sage);

        $builder = McpServer::make()
            ->withServerInfo(self::NAME, self::VERSION)
            ->withLogger(new StderrLogger())
            ->withContainer($container)
            ->withTool(
                [ListContactsTool::class, 'handle'],
                name: 'list_contacts',
                description: 'List contacts (customers and suppliers) for the connected Sage business, with optional filters.',
            )
            ->withTool(
                [ListPurchaseInvoicesTool::class, 'handle'],
                name: 'list_purchase_invoices',
                description: 'List purchase (supplier) invoices for the connected Sage business, with optional filters.',
            )
            ->withTool(
                [GetBusinessTool::class, 'handle'],
                name: 'get_business',
                description: 'Get the Sage business this server is connected to (the active business).',
            );

        if ($fullAccess) {
            $builder
                ->withTool(
                    [CreateContactTool::class, 'handle'],
                    name: 'create_contact',
                    description: 'Create a contact (customer or supplier). Requires the full_access scope.',
                )
                ->withTool(
                    [CreatePurchaseInvoiceTool::class, 'handle'],
                    name: 'create_purchase_invoice',
                    description: 'Create a purchase (supplier) invoice. Requires the full_access scope.',
                );
        }

        return $builder->build();
    }
}
