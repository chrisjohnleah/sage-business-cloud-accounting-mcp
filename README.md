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

By default `connect` uses an **RFC 8252 loopback redirect** — the same pattern
`gcloud auth login` and `gh auth login` use. It starts a temporary listener on
`http://127.0.0.1:8765/callback`, opens your browser, and catches the
authorization code there automatically. The redirect never touches a production
web callback, and there is nothing to copy-paste. (Claude Code does not broker
OAuth for stdio MCP servers, so the server owns this flow.)

> **From inside your MCP client:** you don't need a terminal — just invoke the
> **`sage_connect`** tool (e.g. ask the agent to "connect to Sage"). It runs the
> same loopback flow, opens your browser, and saves the token. Call it whenever a
> Sage tool reports it's not connected.

**One-time setup:** register the loopback redirect URI **exactly** in your Sage
Developer app (Sage requires an exact match):

```
http://127.0.0.1:8765/callback
```

Change the port with `--port=N` or `SAGE_MCP_CALLBACK_PORT` (register a matching
URI). To enable the write tools, set `SAGE_SCOPES=full_access` before connecting
(see [Tools](#tools)). After connecting, the cached token refreshes automatically.

**Alternatives**

```bash
php vendor/bin/sage-mcp connect --manual         # print URL, paste the redirect URL/code back
php vendor/bin/sage-mcp connect "<code-or-url>"   # exchange a code non-interactively
```

`--manual` uses your configured `SAGE_REDIRECT_URI` instead of the loopback.

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

## HTTP mode — native OAuth re-authentication

By default this is a **stdio** server (above), which Claude Code cannot show an
"Authenticate / Re-authenticate" action for — it can only "Reconnect". If you
want the native OAuth experience, run it as an **HTTP server** instead. It then
acts as its own OAuth 2.1 authorization server (with discovery metadata, dynamic
client registration, and PKCE) that **bridges to Sage**, so Claude Code drives
the whole authenticate / re-authenticate flow.

Run it as a persistent service (it is *not* spawned by the client):

```bash
php vendor/bin/sage-mcp serve        # listens on http://127.0.0.1:8765 by default
# SAGE_MCP_HTTP_HOST / SAGE_MCP_HTTP_PORT to change the address.
```

**One-time setup:** register this redirect URI **exactly** in your Sage Developer app:

```
http://127.0.0.1:8765/sage/callback
```

Point your MCP client at the HTTP endpoint (note `type` and `url` — no `command`):

```json
{
  "mcpServers": {
    "sage": {
      "type": "http",
      "url": "http://127.0.0.1:8765/mcp"
    }
  }
}
```

The server still reads `SAGE_CLIENT_ID` / `SAGE_CLIENT_SECRET` from its
environment. In the client, choose **Authenticate**: your browser opens to Sage,
you approve, and the server stores the connection — no separate `connect` step.
The bearer tokens it issues only gate the MCP endpoint; the Sage tokens live in
the usual token store. OAuth server state is kept in `SAGE_MCP_OAUTH_PATH`
(defaults next to the token file).

## Tools

All tools operate on the connected business (see [Connecting](#connecting-one-time-oauth)). Results are returned as JSON.

**Read — always available:**

| Tool | Description |
| --- | --- |
| `list_contacts` | List contacts (customers and suppliers). Filters: `updated_or_created_since`, `search`, `email`, `contact_type_id`, `limit`. |
| `list_purchase_invoices` | List purchase (supplier) invoices. Filters: `updated_or_created_since`, `status_id`, `contact_id`, `from_date`, `to_date`, `limit`. |
| `get_business` | Get the connected business (id, name, address, contact details). |
| `sage_connect` | Authenticate/re-authenticate to Sage (opens your browser, loopback OAuth). Call this if a tool reports it's not connected. |

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
