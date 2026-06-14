<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http\OAuth;

use JsonException;
use RuntimeException;

/**
 * File-backed state for the MCP server's built-in OAuth authorization server:
 * dynamically-registered clients, pending bridge authorizations (while the user
 * is away at Sage), single-use authorization codes (with their PKCE challenge),
 * and issued access/refresh tokens.
 *
 * These tokens only gate access to the MCP endpoint — the actual Sage connection
 * lives in the Sage TokenStore (populated by the bridge during /sage/callback).
 *
 * Single JSON document, owner-only, read-modify-write under a lock. Adequate for
 * the localhost, single-user deployment this server targets.
 */
final class OAuthStore
{
    /** @var list<string> */
    private const SECTIONS = ['clients', 'pending', 'codes', 'access_tokens', 'refresh_tokens'];

    public function __construct(private readonly string $path)
    {
    }

    /**
     * Register a client (RFC 7591 dynamic client registration).
     *
     * @param  list<string>  $redirectUris
     * @return array{client_id: string, redirect_uris: list<string>, client_name: ?string, client_id_issued_at: int}
     */
    public function registerClient(array $redirectUris, ?string $name, int $now): array
    {
        $clientId = self::randomId();

        $record = [
            'client_id' => $clientId,
            'redirect_uris' => $redirectUris,
            'client_name' => $name,
            'client_id_issued_at' => $now,
        ];

        $this->mutate(function (array &$state) use ($clientId, $record): void {
            $state['clients'][$clientId] = $record;
        });

        return $record;
    }

    /**
     * @return array{client_id: string, redirect_uris: list<string>, client_name: ?string}|null
     */
    public function getClient(string $clientId): ?array
    {
        $state = $this->load();
        $client = $state['clients'][$clientId] ?? null;

        if (! is_array($client)) {
            return null;
        }

        $redirectUris = [];
        $uris = $client['redirect_uris'] ?? [];

        if (is_array($uris)) {
            foreach ($uris as $uri) {
                if (is_string($uri)) {
                    $redirectUris[] = $uri;
                }
            }
        }

        return [
            'client_id' => $clientId,
            'redirect_uris' => $redirectUris,
            'client_name' => is_string($client['client_name'] ?? null) ? $client['client_name'] : null,
        ];
    }

    /**
     * Stash the in-flight authorization request while the user authorises at Sage.
     * Returns the opaque "bridge state" passed to Sage and echoed back.
     *
     * @param  array{client_id: string, redirect_uri: string, code_challenge: string, code_challenge_method: string, scope: string, state: string}  $request
     */
    public function createPendingAuthorization(array $request, int $ttlSeconds, int $now): string
    {
        $bridgeState = self::randomId();
        $record = $request + ['expires_at' => $now + $ttlSeconds];

        $this->mutate(function (array &$state) use ($bridgeState, $record): void {
            $state['pending'][$bridgeState] = $record;
        });

        return $bridgeState;
    }

    /**
     * @return array<string, mixed>|null  keys: client_id, redirect_uri, code_challenge, code_challenge_method, scope, state
     */
    public function consumePendingAuthorization(string $bridgeState, int $now): ?array
    {
        return $this->take('pending', $bridgeState, $now);
    }

    /**
     * Issue a single-use authorization code bound to its PKCE challenge.
     *
     * @param  array{client_id: string, redirect_uri: string, code_challenge: string, code_challenge_method: string, scope: string}  $data
     */
    public function createAuthCode(array $data, int $ttlSeconds, int $now): string
    {
        $code = self::randomId();
        $record = $data + ['expires_at' => $now + $ttlSeconds];

        $this->mutate(function (array &$state) use ($code, $record): void {
            $state['codes'][$code] = $record;
        });

        return $code;
    }

    /**
     * @return array<string, mixed>|null  keys: client_id, redirect_uri, code_challenge, code_challenge_method, scope
     */
    public function consumeAuthCode(string $code, int $now): ?array
    {
        return $this->take('codes', $code, $now);
    }

    /**
     * Issue an access token (+ refresh token) for a client.
     *
     * @return array{access_token: string, refresh_token: string, expires_in: int}
     */
    public function issueTokens(string $clientId, int $accessTtlSeconds, int $now): array
    {
        $accessToken = self::randomId();
        $refreshToken = self::randomId();

        $this->mutate(function (array &$state) use ($accessToken, $refreshToken, $clientId, $accessTtlSeconds, $now): void {
            $state['access_tokens'][$accessToken] = ['client_id' => $clientId, 'expires_at' => $now + $accessTtlSeconds];
            $state['refresh_tokens'][$refreshToken] = ['client_id' => $clientId];
        });

        return [
            'access_token' => $accessToken,
            'refresh_token' => $refreshToken,
            'expires_in' => $accessTtlSeconds,
        ];
    }

    public function validateAccessToken(string $token, int $now): bool
    {
        if ($token === '') {
            return false;
        }

        $state = $this->load();
        $record = $state['access_tokens'][$token] ?? null;

        if (! is_array($record)) {
            return false;
        }

        $expiresAt = $record['expires_at'] ?? 0;

        return is_int($expiresAt) && $expiresAt > $now;
    }

    /**
     * Consume a refresh token, returning its client_id (rotated: the caller
     * issues a fresh pair).
     */
    public function consumeRefreshToken(string $refreshToken): ?string
    {
        $clientId = null;

        $this->mutate(function (array &$state) use ($refreshToken, &$clientId): void {
            $record = $state['refresh_tokens'][$refreshToken] ?? null;
            unset($state['refresh_tokens'][$refreshToken]);

            if (is_array($record) && is_string($record['client_id'] ?? null)) {
                $clientId = $record['client_id'];
            }
        });

        return $clientId;
    }

    /**
     * Read + delete a TTL-bound entry from a section. Returns null if missing or
     * expired. Strips the expires_at key from the returned record.
     *
     * @return array<string, mixed>|null
     */
    private function take(string $section, string $key, int $now): ?array
    {
        $record = null;

        $this->mutate(function (array &$state) use ($section, $key, $now, &$record): void {
            $found = $state[$section][$key] ?? null;
            unset($state[$section][$key]);

            if (is_array($found)) {
                $expiresAt = $found['expires_at'] ?? 0;

                if (is_int($expiresAt) && $expiresAt > $now) {
                    $clean = [];
                    foreach ($found as $field => $value) {
                        if (is_string($field) && $field !== 'expires_at') {
                            $clean[$field] = $value;
                        }
                    }
                    $record = $clean;
                }
            }
        });

        return $record;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        $empty = array_fill_keys(self::SECTIONS, []);

        if (! is_file($this->path)) {
            return $empty;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false || trim($contents) === '') {
            return $empty;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Corrupt OAuth state file at {$this->path}: {$e->getMessage()}", 0, $e);
        }

        $state = $empty;

        foreach (self::SECTIONS as $section) {
            if (isset($data[$section]) && is_array($data[$section])) {
                /** @var array<string, mixed> $sectionData */
                $sectionData = $data[$section];
                $state[$section] = $sectionData;
            }
        }

        return $state;
    }

    /**
     * @param  callable(array<string, array<string, mixed>> $state): void  $mutator
     */
    private function mutate(callable $mutator): void
    {
        $this->ensureDirectory();

        $state = $this->load();
        $mutator($state);

        try {
            $json = json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Unable to encode OAuth state: {$e->getMessage()}", 0, $e);
        }

        if (file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write OAuth state file at {$this->path}.");
        }

        @chmod($this->path, 0600);
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create OAuth state directory at {$dir}.");
        }
    }

    private static function randomId(): string
    {
        return bin2hex(random_bytes(32));
    }
}
