# Changelog

All notable changes are documented here, following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.2.0] - 2026-06-14

### Added
- `connect` now defaults to an RFC 8252 loopback redirect (like `gcloud`/`gh`): it starts a temporary `http://127.0.0.1:8765/callback` listener (`LoopbackServer`), opens the browser, and catches the authorization code automatically — the redirect no longer needs to hit a production web callback, and there is no copy-paste. Port configurable via `--port=N` / `SAGE_MCP_CALLBACK_PORT`.
- `connect --manual` (print URL, paste code/redirect URL) and `connect <code|url>` (non-interactive) retained as fallbacks.
- `SageClientFactory::fromEnvironment()` accepts an optional redirect-URI override (used by the loopback connect flow).

## [0.1.0] - 2026-06-14

### Added
- Working stdio MCP server built on [`php-mcp/server`](https://github.com/php-mcp/server) (`Server::run()` / `Server::build()`), exposing Sage Accounting as agent tools.
- Read tools: `list_contacts`, `list_purchase_invoices` (both with filters and a bounded `limit`), and `get_business`.
- Write tools `create_contact` and `create_purchase_invoice`, registered only when `SAGE_SCOPES` grants `full_access`.
- `DtoMapper` — maps the SDK's typed DTOs into curated, JSON-serialisable arrays; `ApiError` — surfaces Sage HTTP errors with status and body; `StderrLogger` — keeps diagnostics off the stdio JSON-RPC stream.
- `bin/sage-mcp connect` — one-time OAuth flow (`ConnectCommand`) that prints the authorization URL, exchanges the code, and resolves the active business; tokens persist via `FileTokenStore`.
- `FileTokenStore` — file-backed `TokenStore` so the server runs self-contained, with no database.
- `SageClientFactory` — builds the core SDK client from environment variables, reusing the SDK's connector and `TokenStore` contract; `hasFullAccess()` gates the write tools.
- `bin/sage-mcp` console entry point (serve over stdio, or `connect`).
- Pest tests for every tool, the connect flow, and tool gating, using a Saloon `MockClient` to fake the HTTP layer.
- CI (Pint + PHPStan + Pest) on PHP 8.3 and 8.4.

[Unreleased]: https://github.com/chrisjohnleah/sage-business-cloud-accounting-mcp/compare/v0.2.0...HEAD
[0.2.0]: https://github.com/chrisjohnleah/sage-business-cloud-accounting-mcp/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/chrisjohnleah/sage-business-cloud-accounting-mcp/releases/tag/v0.1.0
