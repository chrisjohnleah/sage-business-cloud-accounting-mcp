<?php

declare(strict_types=1);

namespace ChrisJohnLeah\SageAccounting\Mcp\Support;

use Psr\Log\AbstractLogger;
use Stringable;

/**
 * Minimal PSR-3 logger that writes to STDERR.
 *
 * Under the stdio transport, STDOUT is reserved exclusively for JSON-RPC
 * framing — anything written there corrupts the protocol stream. All server
 * diagnostics therefore go to STDERR.
 */
final class StderrLogger extends AbstractLogger
{
    /**
     * @param  array<array-key, mixed>  $context
     */
    public function log(mixed $level, string|Stringable $message, array $context = []): void
    {
        $levelText = is_string($level) ? $level : (is_scalar($level) ? (string) $level : 'log');

        $line = sprintf('[%s] %s', strtoupper($levelText), (string) $message);

        if ($context !== []) {
            $encoded = json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($encoded !== false) {
                $line .= ' '.$encoded;
            }
        }

        fwrite(STDERR, $line.PHP_EOL);
    }
}
