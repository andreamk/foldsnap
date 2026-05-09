<?php

/**
 * Value sanitization utility class.
 *
 * Provides sanitization functions for values (strings, integers, booleans, arrays).
 * Does NOT access superglobals — for input reading use SanitizeInput.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Utils;

use InvalidArgumentException;

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
     * Non-scalar input (array, object, null) returns empty string. Scalar input
     * (string, int, float, bool) is cast to string before processing.
     *
     * Flags (combinable via bitwise OR):
     * - STRIP_NEWLINES:    also strip \r and \n
     * - STRIP_WHITESPACE:  also strip all whitespace (includes newlines, spaces, tabs)
     * - STRIP_TRIM:        trim leading/trailing whitespace after stripping (implies STRIP_NEWLINES)
     * - STRIP_HTML_ESCAPE: HTML-escape the result (XSS protection)
     *
     * @param mixed $string Input value (scalar values are cast to string; non-scalar returns '')
     * @param int   $flags  Bitmask of STRIP_* constants
     *
     * @return string
     */
    public static function str($string, int $flags = 0): string
    {
        if (!is_scalar($string)) {
            return '';
        }

        $string = (string) $string;

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
     * Validate and sanitize a hex color code
     *
     * Accepts 3 or 6 digit hex colors with # prefix (e.g., #fff, #ff0000).
     *
     * @param string $color Hex color string
     *
     * @return string Validated hex color
     *
     * @throws InvalidArgumentException If color is not a valid hex color.
     */
    public static function hexColor(string $color): string
    {
        if (1 !== preg_match('/^#([A-Fa-f0-9]{3}){1,2}$/', $color)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Invalid hex color: %s', $color))
            );
        }

        return $color;
    }

    /**
     * Strict sanitization for a single string value: only alphanumeric characters
     * and custom extra characters are kept.
     *
     * Non-scalar input (array, object, null) returns empty string. Scalar input
     * (string, int, float, bool) is cast to string before processing.
     *
     * @param mixed  $input            Input value (scalar values are cast to string; non-scalar returns '')
     * @param string $extraAcceptChars Extra accepted characters
     *
     * @return string
     */
    public static function strictStr($input, string $extraAcceptChars = ''): string
    {
        if (!is_scalar($input)) {
            return '';
        }

        $regex  = '/[^a-zA-Z0-9' . preg_quote($extraAcceptChars, '/') . ' ]/m';
        $result = preg_replace($regex, '', (string) $input);
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
     * Sanitize value to integer.
     *
     * Non-scalar input (array, object, null) returns the default. Numeric scalar
     * input is truncated toward zero (e.g., '3.9' → 3, '-3.9' → -3). Non-numeric
     * strings return the default.
     *
     * @param mixed $input   Input value (numeric scalars are truncated; non-scalar/non-numeric returns default)
     * @param int   $default Default value if input is not a valid number
     *
     * @return int
     */
    public static function toInt($input, int $default = 0): int
    {
        if (!is_scalar($input)) {
            return $default;
        }

        if (!is_numeric($input)) {
            return $default;
        }

        return (int) (float) $input;
    }

    /**
     * Sanitize value to float.
     *
     * Non-scalar input (array, object, null) returns the default. Scalar input
     * is cast to string before validation via FILTER_VALIDATE_FLOAT.
     *
     * @param mixed $input   Input value (scalar values are validated; non-scalar returns default)
     * @param float $default Default value if input is not a valid float
     *
     * @return float
     */
    public static function toFloat($input, float $default = 0.0): float
    {
        if (!is_scalar($input)) {
            return $default;
        }

        $result = filter_var((string) $input, FILTER_VALIDATE_FLOAT, ['options' => ['default' => $default]]);
        return is_float($result) ? $result : $default;
    }

    /**
     * Sanitize value to boolean.
     *
     * Non-scalar input (array, object, null) returns false. Scalar input is
     * validated via FILTER_VALIDATE_BOOLEAN.
     *
     * @param mixed $input Input value (scalar values are validated; non-scalar returns false)
     *
     * @return bool
     */
    public static function toBool($input): bool
    {
        if (!is_scalar($input)) {
            return false;
        }

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
