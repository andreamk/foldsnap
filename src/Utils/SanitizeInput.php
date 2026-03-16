<?php

/**
 * Input sanitization class.
 *
 * Reads values from superglobal variables ($_GET, $_POST, $_REQUEST, $_SERVER, etc.)
 * and returns them sanitized with proper return types.
 * Delegates actual sanitization to the Sanitize class.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Utils;

use Exception;

/**
 * SanitizeInput utility class
 *
 * @phpstan-type InputType 0|1|2|4|5|self::INPUT_REQUEST
 */
final class SanitizeInput
{
    /** @var int Custom constant for handling both GET and POST */
    public const INPUT_REQUEST = 10000;

    /**
     * Sanitize string from input superglobals, strips control characters.
     *
     * @param InputType       $type    One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string|string[] $varName Variable name or array of keys for nested access
     * @param string          $default Default value if variable doesn't exist
     * @param int             $flags   Bitmask of Sanitize::STRIP_* constants
     *
     * @return string
     */
    public static function str(int $type, $varName, string $default = '', int $flags = 0): string
    {
        $value = self::getValueByType($type, $varName);

        if (null === $value || is_array($value)) {
            return $default;
        }

        return Sanitize::str($value, $flags);
    }

    /**
     * Strict sanitize string value from input superglobals.
     *
     * @param InputType       $type             One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string|string[] $varName          Variable name or array of keys for nested access
     * @param string          $default          Default value if variable doesn't exist
     * @param string          $extraAcceptChars Extra accepted characters
     *
     * @return string
     */
    public static function strictStr(int $type, $varName, string $default = '', string $extraAcceptChars = ''): string
    {
        $value = self::getValueByType($type, $varName);
        if (null === $value) {
            return $default;
        }

        if (is_array($value)) {
            return $default;
        }

        $result = Sanitize::strictStr($value, $extraAcceptChars);
        return strlen($result) > 0 ? $result : $default;
    }

    /**
     * Sanitize integer value from input superglobals.
     *
     * @param InputType       $type    One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string|string[] $varName Variable name or array of keys for nested access
     * @param int             $default Default value if variable doesn't exist
     *
     * @return int
     */
    public static function toInt(int $type, $varName, int $default = 0): int
    {
        $value = self::getValueByType($type, $varName);
        if (null === $value || is_array($value)) {
            return $default;
        }

        return Sanitize::toInt($value, $default);
    }

    /**
     * Sanitize boolean value from input superglobals.
     *
     * @param InputType       $type    One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string|string[] $varName Variable name or array of keys for nested access
     * @param bool            $default Default value if variable doesn't exist
     *
     * @return bool
     */
    public static function toBool(int $type, $varName, bool $default = false): bool
    {
        $value = self::getValueByType($type, $varName);
        if (null === $value || is_array($value)) {
            return $default;
        }

        return Sanitize::toBool($value);
    }

    /**
     * Sanitize an array of strings from input superglobals using strict sanitization.
     *
     * @param InputType       $type             One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string|string[] $varName          Variable name or array of keys for nested access
     * @param string[]        $default          Default value if variable doesn't exist or is not an array
     * @param string          $extraAcceptChars Extra accepted characters
     *
     * @return string[]
     */
    public static function strictArray(int $type, $varName, array $default = [], string $extraAcceptChars = ''): array
    {
        $value = self::getValueByType($type, $varName);

        if (!is_array($value)) {
            return $default;
        }

        return Sanitize::strictArray($value, $extraAcceptChars);
    }


    /**
     * Return input superglobal array by type.
     *
     * @param InputType $type One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     *
     * @return mixed[]
     *
     * @throws Exception If type is invalid.
     */
    private static function getInputFromType(int $type): array
    {
        // Direct superglobal access is required here because filter_input() cannot read array values.
        // Nonce verification is the caller's responsibility, not this low-level utility.
        // phpcs:disable WordPress.Security.NonceVerification, WordPress.Security.ValidatedSanitizedInput
        switch ($type) {
            case INPUT_GET:
                return $_GET;
            case INPUT_POST:
                return $_POST;
            case INPUT_COOKIE:
                return $_COOKIE;
            case INPUT_SERVER:
                return $_SERVER;
            case INPUT_ENV:
                return $_ENV;
            case self::INPUT_REQUEST:
                return array_merge($_GET, $_POST);
            default:
                throw new Exception('Invalid input type: ' . esc_html((string) $type));
        }
        // phpcs:enable
    }


    /**
     * Return value from input superglobal by type, null if it doesn't exist.
     *
     * Reads directly from superglobals via getInputFromType(), then navigates
     * keys (single or nested). Scalar values are passed through filter_var()
     * for sanitization; INPUT_SERVER values are also wp_unslash'd.
     *
     * @param InputType       $type    One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string|string[] $varName Variable name or array of keys for nested access
     *
     * @return string|string[]|null
     */
    private static function getValueByType(int $type, $varName)
    {
        $keys    = is_array($varName) ? $varName : [$varName];
        $current = self::getInputFromType($type);

        foreach ($keys as $key) {
            if (!is_array($current) || !array_key_exists($key, $current)) {
                return null;
            }
            $current = $current[$key];
        }

        if (INPUT_SERVER === $type) {
            $current = wp_unslash($current);
        }

        return self::sanitizeRaw($current);
    }

    /**
     * Strip low ASCII control characters (including null bytes) from a value.
     * Applies recursively to arrays.
     *
     * @param mixed $value Raw value from superglobal.
     *
     * @return string|string[]|null Sanitized value, or null if not a string/array.
     */
    private static function sanitizeRaw($value)
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $k => $v) {
                $sanitized = self::sanitizeRaw($v);
                if (null !== $sanitized) {
                    $result[$k] = $sanitized;
                }
            }
            /** @var string[] $result */
            return $result;
        }

        if (!is_string($value)) {
            return null;
        }

        $filtered = filter_var($value, FILTER_UNSAFE_RAW, FILTER_FLAG_STRIP_LOW);
        return is_string($filtered) ? $filtered : null;
    }
}
