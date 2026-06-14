<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp;

use ChrisJohnLeah\SageAccounting\Auth\StoredToken;
use ChrisJohnLeah\SageAccounting\Contracts\TokenStore;
use DateTimeImmutable;
use JsonException;
use RuntimeException;

/**
 * File-backed token store so the MCP server runs self-contained, with no
 * database. The connection's token is persisted as a single JSON document;
 * put() overwrites it so Sage's rotated refresh token always replaces the last.
 */
final class FileTokenStore implements TokenStore
{
    public function __construct(private readonly string $path)
    {
    }

    public function get(): ?StoredToken
    {
        if (! is_file($this->path)) {
            return null;
        }

        $contents = file_get_contents($this->path);

        if ($contents === false || trim($contents) === '') {
            return null;
        }

        try {
            /** @var array<string, mixed> $data */
            $data = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Corrupt Sage token file at {$this->path}: {$e->getMessage()}", 0, $e);
        }

        $accessToken = $data['access_token'] ?? null;
        $refreshToken = $data['refresh_token'] ?? null;

        if (! is_string($accessToken) || ! is_string($refreshToken)) {
            return null;
        }

        $expiresAtRaw = $data['expires_at'] ?? null;
        $expiresAt = is_string($expiresAtRaw) && $expiresAtRaw !== ''
            ? new DateTimeImmutable($expiresAtRaw)
            : null;

        $businessId = $data['business_id'] ?? null;

        return new StoredToken(
            accessToken: $accessToken,
            refreshToken: $refreshToken,
            expiresAt: $expiresAt,
            businessId: is_string($businessId) ? $businessId : null,
        );
    }

    public function put(StoredToken $token): void
    {
        $this->ensureDirectory();

        $payload = [
            'access_token' => $token->accessToken,
            'refresh_token' => $token->refreshToken,
            'expires_at' => $token->expiresAt?->format(DateTimeImmutable::ATOM),
            'business_id' => $token->businessId,
        ];

        try {
            $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new RuntimeException("Unable to encode Sage token: {$e->getMessage()}", 0, $e);
        }

        if (file_put_contents($this->path, $json, LOCK_EX) === false) {
            throw new RuntimeException("Unable to write Sage token file at {$this->path}.");
        }

        @chmod($this->path, 0600);
    }

    public function forget(): void
    {
        if (is_file($this->path)) {
            @unlink($this->path);
        }
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);

        if (! is_dir($dir) && ! @mkdir($dir, 0700, true) && ! is_dir($dir)) {
            throw new RuntimeException("Unable to create token directory at {$dir}.");
        }
    }
}
