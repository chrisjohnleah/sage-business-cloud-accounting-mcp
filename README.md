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

Authorise the server against your Sage account once. The token is cached via
`FileTokenStore` and refreshed automatically on every subsequent call.

```bash
# Reads the same SAGE_* environment variables as the server.
php vendor/bin/sage-mcp connect
```

The command prints a Sage authorization URL. Open it, sign in, and approve
access. Sage redirects to your `SAGE_REDIRECT_URI` with a `?code=...` parameter —
paste the full redirect URL (or just the code) back into the prompt. The server
exchanges the code for tokens, stores them, and resolves your business id so all
later calls target the right company.

To enable the write tools, authorise with `SAGE_SCOPES=full_access` set before
running `connect` (see [Tools](#tools)).

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

All tools operate on the connected business (see [Connecting](#connecting-one-time-oauth)). Results are returned as JSON.

**Read — always available:**

| Tool | Description |
| --- | --- |
| `list_contacts` | List contacts (customers and suppliers). Filters: `updated_or_created_since`, `search`, `email`, `contact_type_id`, `limit`. |
| `list_purchase_invoices` | List purchase (supplier) invoices. Filters: `updated_or_created_since`, `status_id`, `contact_id`, `from_date`, `to_date`, `limit`. |
| `get_business` | Get the connected business (id, name, address, contact details). |

**Write — only registered when `SAGE_SCOPES=full_access`:**

| Tool | Description |
| --- | --- |
| `create_contact` | Create a contact. Required: `name`, `contact_type_id` (`CUSTOMER` or `VENDOR`). Optional: `reference`, `email`, `tax_number`, `notes`. |
| `create_purchase_invoice` | Create a purchase (supplier) invoice. Required: `contact_id`, `date`, `invoice_lines`. Optional: `due_date`, `reference`, `vendor_reference`, `notes`. |

Write tools are omitted from the tool list entirely unless `full_access` is in
`SAGE_SCOPES`, so a read-only deployment can never mutate Sage data.

## Testing

```bash
composer check   # Pint + PHPStan (max) + Pest
```

## Licence

MIT © [Chris John Leah](https://github.com/chrisjohnleah). See [LICENSE](LICENSE).

> Not affiliated with or endorsed by The Sage Group plc.
