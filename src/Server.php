<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp;

use RuntimeException;

/**
 * Entry point that builds the MCP server and registers Sage tools.
 *
 * IMPLEMENTATION PENDING. This server is intended to run on top of
 * php-mcp/server (https://github.com/php-mcp/server): register the classes under
 * src/Tools as MCP tools — each receiving a Sage client from
 * SageClientFactory::fromEnvironment() — then start the stdio transport so MCP
 * clients (Claude Desktop, Claude Code) can call them.
 *
 * The implementation pass replaces the body of run() with the real wiring. See
 * HANDOFF.md (local, gitignored) for the step-by-step prompt.
 *
 * @see https://github.com/php-mcp/server
 */
final class Server
{
    public static function run(): void
    {
        // TODO(implementation): build the php-mcp/server instance, discover/register
        // the tools in src/Tools, and start the stdio transport.
        throw new RuntimeException(
            'Sage MCP server not yet implemented — see HANDOFF.md for the implementation prompt.',
        );
    }
}
