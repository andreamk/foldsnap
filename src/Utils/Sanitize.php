<?php

/**
 * Pure value sanitization utility class.
 *
 * Provides sanitization functions for values (strings, integers, booleans, arrays).
 * Does NOT access superglobals — for input reading use SanitizeInput.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Utils;

/**
 * Sanitize utility class
 *
 * @phpstan-type StripFlags int-mask-of<self::STRIP_*>
 */
final class Sanitize
{
    /** @var int Strip newlines (\r\n) */
    public const STRIP_NEWLINES = 1;

    /** @var int Strip all whitespace (spaces, tabs, newlines) */
    public const STRIP_WHITESPACE = 2;

    /** @var int Trim leading/trailing whitespace after stripping */
    public const STRIP_TRIM = 4;

    /** @var int HTML-escape the result (XSS protection) */
    public const STRIP_HTML_ESCAPE = 8;

    /**
     * Remove control characters from string, with optional flags for additional stripping.
     *
     * Base behavior: always removes non-stamp control characters (x00-x08, x0B, x0C, x0E-x1F, x7F-x9F).
     *
     * Flags (combinable via bitwise OR):
     * - STRIP_NEWLINES:    also strip \r and \n
     * - STRIP_WHITESPACE:  also strip all whitespace (includes newlines, spaces, tabs)
     * - STRIP_TRIM:        trim leading/trailing whitespace after stripping (implies STRIP_NEWLINES)
     * - STRIP_HTML_ESCAPE: HTML-escape the result (XSS protection)
     *
     * @param string $string Input string
     * @param int    $flags  Bitmask of STRIP_* constants
     *
     * @return string
     */
    public static function str(string $string, int $flags = 0): string
    {
        if ($flags & self::STRIP_WHITESPACE) {
            $pattern = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\r\n\s]/u';
        } elseif (($flags & self::STRIP_NEWLINES) || ($flags & self::STRIP_TRIM)) {
            $pattern = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F\r\n]/u';
        } else {
            $pattern = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F-\x9F]/u';
        }

        $result = (string) preg_replace($pattern, '', $string);

        if ($flags & self::STRIP_TRIM) {
            $result = trim($result);
        }

        if ($flags & self::STRIP_HTML_ESCAPE) {
            $result = htmlspecialchars($result);
        }

        return $result;
    }

    /**
     * Strict sanitization for a single string value: only alphanumeric characters
     * and custom extra characters are kept.
     *
     * @param string $input            Input string
     * @param string $extraAcceptChars Extra accepted characters
     *
     * @return string
     */
    public static function strictStr(string $input, string $extraAcceptChars = ''): string
    {
        $regex  = '/[^a-zA-Z0-9' . preg_quote($extraAcceptChars, '/') . ' ]/m';
        $result = preg_replace($regex, '', $input);
        return (null === $result ? '' : $result);
    }

    /**
     * Strict sanitization for an array of values: only alphanumeric characters
     * and custom extra characters are kept in each element.
     *
     * @param array<int|string, mixed> $input            Input array
     * @param string                   $extraAcceptChars Extra accepted characters
     *
     * @return string[]
     */
    public static function strictArray(array $input, string $extraAcceptChars = ''): array
    {
        $result = [];
        foreach ($input as $key => $val) {
            if (is_array($val)) {
                $result[$key] = implode('', self::strictArray($val, $extraAcceptChars));
            } else {
                $result[$key] = self::strictStr(is_scalar($val) ? (string) $val : '', $extraAcceptChars);
            }
        }
        return $result;
    }

    /**
     * Sanitize string value to integer.
     *
     * @param string $input   Input value as string
     * @param int    $default Default value if input is not valid
     *
     * @return int
     */
    public static function toInt(string $input, int $default = 0): int
    {
        $result = filter_var($input, FILTER_VALIDATE_INT, ['options' => ['default' => $default]]);
        return is_int($result) ? $result : $default;
    }

    /**
     * Sanitize string value to boolean.
     *
     * @param string $input Input value as string
     *
     * @return bool
     */
    public static function toBool(string $input): bool
    {
        return filter_var($input, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Close all output buffers and optionally return content.
     *
     * @param bool $getContent If true returns buffer content, otherwise it is discarded
     *
     * @return string
     */
    public static function obCleanAll(bool $getContent = true): string
    {
        $result = '';
        for ($i = 0; $i < ob_get_level(); $i++) {
            if ($getContent) {
                $result .= (string) ob_get_contents();
            }
            ob_clean();
        }
        return $result;
    }
}
