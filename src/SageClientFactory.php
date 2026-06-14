<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp;

use ChrisJohnLeah\SageAccounting\Contracts\TokenStore;
use ChrisJohnLeah\SageAccounting\Sage;
use ChrisJohnLeah\SageAccounting\SageConnector;
use RuntimeException;

/**
 * Builds a ready-to-use Sage client from environment variables, reusing the core
 * SDK exactly as the Laravel bridge does — same connector, same TokenStore
 * contract — but backed by a file token store so the server stays self-contained.
 */
final class SageClientFactory
{
    public static function fromEnvironment(?TokenStore $tokenStore = null, ?string $redirectUri = null): Sage
    {
        $tokenStore ??= new FileTokenStore(self::tokenPath());

        $connector = new SageConnector(
            clientId: self::env('SAGE_CLIENT_ID', required: true),
            clientSecret: self::env('SAGE_CLIENT_SECRET', required: true),
            redirectUri: $redirectUri ?? self::env('SAGE_REDIRECT_URI', required: true),
            scopes: self::scopes(),
            baseUrl: self::env('SAGE_API_BASE_URL', default: 'https://api.accounting.sage.com/v3.1'),
            authorizeEndpoint: self::env('SAGE_AUTHORIZE_ENDPOINT', default: 'https://www.sageone.com/oauth2/auth/central'),
            tokenEndpoint: self::env('SAGE_TOKEN_ENDPOINT', default: 'https://oauth.accounting.sage.com/token'),
        );

        return new Sage($connector, $tokenStore, self::refreshBuffer());
    }

    /**
     * Whether the configured scopes grant write access. Write tools are only
     * registered when this is true.
     */
    public static function hasFullAccess(): bool
    {
        return in_array('full_access', self::scopes(), true);
    }

    public static function tokenPath(): string
    {
        $configured = getenv('SAGE_MCP_TOKEN_PATH');

        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        $home = getenv('HOME') ?: getenv('USERPROFILE') ?: sys_get_temp_dir();

        return rtrim((string) $home, '/\\').'/.config/sage-mcp/token.json';
    }

    /**
     * @return list<string>
     */
    private static function scopes(): array
    {
        $raw = getenv('SAGE_SCOPES');
        $raw = is_string($raw) && $raw !== '' ? $raw : 'readonly';

        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }

    private static function refreshBuffer(): int
    {
        $raw = getenv('SAGE_REFRESH_BUFFER_SECONDS');

        return is_string($raw) && is_numeric($raw) ? (int) $raw : 60;
    }

    private static function env(string $key, string $default = '', bool $required = false): string
    {
        $value = getenv($key);

        if (! is_string($value) || $value === '') {
            if ($required) {
                throw new RuntimeException("Missing required environment variable: {$key}.");
            }

            return $default;
        }

        return $value;
    }
}
