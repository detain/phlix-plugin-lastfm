<?php

/**
 * LogSafety.
 *
 * @copyright 2026 Joe Huss <detain@interserver.net>
 * @license   MIT
 */

declare(strict_types=1);

namespace Phlix\Plugins\Scrobbler\Lastfm;

/**
 * Bound for exception text forwarded into log context.
 *
 * The host connection class forces PDO emulated prepares, which
 * re-materializes bound parameters as quoted literals inside SQL exception
 * messages. Today the plugin's live statements bind only the user UUID —
 * session keys never pass through a plugin-side bound write (`save()` is
 * host-mirrored reference code with zero runtime callers) — but a log line
 * that forwards `getMessage()` verbatim turns any future bound-value choice
 * into a potential log leak and an unbounded line. Truncating at the catch
 * boundary keeps the diagnostic head (error class, SQLSTATE, the first clause)
 * for triage while capping both effects at once.
 *
 * @package Phlix\Plugins\Scrobbler\Lastfm
 */
final class LogSafety
{
    /** Bytes of exception message kept before the truncation marker. */
    public const MAX_EXCEPTION_MESSAGE_BYTES = 512;

    /**
     * Worst-case length of the marker (fixed text + the decimal original
     * size, which cannot exceed PHP_INT_MAX's 20 digits) — lets callers
     * assert a hard bound on the whole line without matching the format.
     */
    public const MAX_MARKER_BYTES = 64;

    /**
     * The throwable's message, cut to at most `$maxBytes` on a character
     * boundary, plus a marker naming the original size when it was cut.
     *
     * @throws \InvalidArgumentException when `$maxBytes` is not positive —
     *         a zero bound would silently discard every diagnostic.
     */
    public static function boundedMessage(\Throwable $t, int $maxBytes = self::MAX_EXCEPTION_MESSAGE_BYTES): string
    {
        if ($maxBytes <= 0) {
            throw new \InvalidArgumentException(
                'A log-message bound must be positive, got ' . $maxBytes . '.',
            );
        }

        $message = $t->getMessage();
        if (strlen($message) <= $maxBytes) {
            return $message;
        }

        $kept = substr($message, 0, $maxBytes);
        // Back off any trailing partial multi-byte sequence: a cut mid
        // character would put invalid UTF-8 into every log consumer. At
        // most a few iterations (a UTF-8 sequence is ≤ 4 bytes).
        while ($kept !== '' && preg_match('//u', $kept) !== 1) {
            $kept = substr($kept, 0, -1);
        }

        return $kept . '... [truncated from ' . strlen($message) . ' bytes]';
    }
}
