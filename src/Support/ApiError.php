<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Support;

use Saloon\Exceptions\Request\RequestException;

/**
 * Turns a Saloon HTTP error into a concise, agent-readable message that
 * includes the status code and (a truncated) response body, so validation and
 * permission failures from Sage surface usefully through the MCP tool result.
 */
final class ApiError
{
    private const int MAX_BODY_LENGTH = 500;

    public static function message(RequestException $exception): string
    {
        $response = $exception->getResponse();
        $body = trim($response->body());

        if (mb_strlen($body) > self::MAX_BODY_LENGTH) {
            $body = mb_substr($body, 0, self::MAX_BODY_LENGTH).'…';
        }

        return sprintf(
            'Sage API error (HTTP %d): %s',
            $response->status(),
            $body === '' ? $exception->getMessage() : $body,
        );
    }
}
