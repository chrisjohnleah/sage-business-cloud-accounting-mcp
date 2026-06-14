# Sage Business Cloud Accounting — MCP Server

[![CI](https://github.com/chrisjohnleah/sage-business-cloud-accounting-mcp/actions/workflows/ci.yml/badge.svg)](https://github.com/chrisjohnleah/sage-business-cloud-accounting-mcp/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

A [Model Context Protocol](https://modelcontextprotocol.io) server for the Sage Business Cloud Accounting API. It exposes Sage accounting data and actions as **tools an AI agent can call** (Claude Desktop, Claude Code, or any MCP client) — built directly on top of [`chrisjohnleah/sage-business-cloud-accounting-api`](https://github.com/chrisjohnleah/sage-business-cloud-accounting-api), so token refresh, pagination, and typed resources come for free.

> An MCP server is a **tool provider** — the AI model lives in the client. This package needs no AI/LLM SDK of its own.

This is a sibling to:

- [`sage-business-cloud-accounting-api`](https://github.com/chrisjohnleah/sage-business-cloud-accounting-api) — the framework-agnostic PHP SDK (this package depends on it).
- [`sage-business-cloud-accounting-api-laravel`](https://github.com/chrisjohnleah/sage-business-cloud-accounting-api-laravel) — the Laravel bridge.

## Requirements

- PHP 8.3+
- A Sage Developer app (https://developerselfservice.sageone.com)

## Installation

```bash
composer require chrisjohnleah/sage-business-cloud-accounting-mcp
```

## Configuration

The server reads its configuration from the environment:

```dotenv
SAGE_CLIENT_ID=...
SAGE_CLIENT_SECRET=...
SAGE_REDIRECT_URI=https://your-app.test/oauth/sage/callback
SAGE_SCOPES=readonly                 # or full_access

# Optional — where the OAuth token is cached.
# Defaults to ~/.config/sage-mcp/token.json
SAGE_MCP_TOKEN_PATH=/absolute/path/to/token.json
```

The token is cached as a single JSON file (see `FileTokenStore`), so the server is fully self-contained — no database required.

## Connecting (one-time OAuth)

> Connect command pending implementation — see `HANDOFF.md`.

Once connected, the cached token is refreshed automatically on each call.

## Registering with an MCP client

Add the server to your client's MCP config (Claude Desktop example):

```json
{
  "mcpServers": {
    "sage": {
      "command": "php",
      "args": ["/absolute/path/to/vendor/bin/sage-mcp"],
      "env": {
        "SAGE_CLIENT_ID": "...",
        "SAGE_CLIENT_SECRET": "...",
        "SAGE_REDIRECT_URI": "...",
        "SAGE_SCOPES": "readonly"
      }
    }
  }
}
```

## Tools

> Tool surface pending implementation. The first example (`ListContactsTool`) shows the shape; planned tools include `list_contacts`, `list_purchase_invoices`, and `get_business`.

## Testing

```bash
composer check   # Pint + PHPStan (max) + Pest
```

## Licence

MIT © [Chris John Leah](https://github.com/chrisjohnleah). See [LICENSE](LICENSE).

> Not affiliated with or endorsed by The Sage Group plc.
