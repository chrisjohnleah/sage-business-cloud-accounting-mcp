# Changelog

All notable changes are documented here, following [Keep a Changelog](https://keepachangelog.com/en/1.1.0/)
and [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Initial scaffold of the Sage Business Cloud Accounting MCP server.
- `FileTokenStore` — file-backed `TokenStore` so the server runs self-contained, with no database.
- `SageClientFactory` — builds the core SDK client from environment variables, reusing the SDK's connector and `TokenStore` contract.
- Skeleton `Server` entry point and an example `ListContactsTool` (tool/transport wiring pending).
- `bin/sage-mcp` console entry point.
- CI (Pint + PHPStan + Pest) on PHP 8.3 and 8.4.

[Unreleased]: https://github.com/chrisjohnleah/sage-business-cloud-accounting-mcp/commits/main
