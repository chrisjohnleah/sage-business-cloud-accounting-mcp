<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Http;

/**
 * Framework-agnostic HTTP outcome returned by the OAuth/MCP handlers. The router
 * renders it into a ReactPHP response. Keeping handlers free of ReactPHP types
 * makes them straightforward to unit-test.
 */
final class HttpResult
{
    /**
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $extraHeaders
     */
    public static function json(array $data, int $status = 200, array $extraHeaders = []): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self(
            $status,
            ['Content-Type' => 'application/json'] + $extraHeaders,
            $body === false ? '{}' : $body,
        );
    }

    /**
     * @param  array<string, string>  $extraHeaders
     */
    public static function redirect(string $location, array $extraHeaders = [], int $status = 302): self
    {
        return new self($status, ['Location' => $location] + $extraHeaders, '');
    }

    public static function html(string $html, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $html);
    }
}
