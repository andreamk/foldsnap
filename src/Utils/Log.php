<?php

/**
 * Plugin logger.
 *
 * Thin wrapper around PHP's `error_log` that prefixes every message with
 * `[FoldSnap]` so log entries are searchable and obviously ours. The single
 * `error_log` call lives here so the WordPress coding-standards suppression
 * is confined to one place.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Utils;

use Throwable;

final class Log
{
    /**
     * Log a free-form message.
     *
     * @param string $message Human-readable message; will be prefixed.
     *
     * @return void
     */
    public static function error(string $message): void
    {
        // Deliberate error reporting — not stray debug output.
        // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
        error_log(sprintf('[FoldSnap] %s', $message));
    }

    /**
     * Log a caught exception.
     *
     * @param Throwable $e       The exception to log.
     * @param string    $context Optional tag (e.g. 'REST handler').
     *
     * @return void
     */
    public static function exception(Throwable $e, string $context = ''): void
    {
        $prefix = '' !== $context ? $context . ': ' : '';
        self::error(sprintf(
            '%s%s: %s in %s:%d',
            $prefix,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        ));
        self::error(sprintf('Stack trace: %s', $e->getTraceAsString()));
    }
}
