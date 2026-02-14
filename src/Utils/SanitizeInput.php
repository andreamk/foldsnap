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
     * @param InputType $type    One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string    $varName Name of a variable to get
     * @param string    $default Default value if variable doesn't exist
     * @param int       $flags   Bitmask of Sanitize::STRIP_* constants
     *
     * @return string
     */
    public static function str(int $type, string $varName, string $default = '', int $flags = 0): string
    {
        $result = self::getRawStringValue($type, $varName);

        if (null === $result) {
            return $default;
        }

        return Sanitize::str($result, $flags);
    }

    /**
     * Strict sanitize string value from input superglobals.
     *
     * @param InputType $type             One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string    $varName          Name of a variable to get
     * @param string    $default          Default value if variable doesn't exist
     * @param string    $extraAcceptChars Extra accepted characters
     *
     * @return string
     */
    public static function strictStr(int $type, string $varName, string $default = '', string $extraAcceptChars = ''): string
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
     * @param InputType $type    One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string    $varName Name of a variable to get
     * @param int       $default Default value if variable doesn't exist
     *
     * @return int
     */
    public static function toInt(int $type, string $varName, int $default = 0): int
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
     * @param InputType $type    One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string    $varName Name of a variable to get
     * @param bool      $default Default value if variable doesn't exist
     *
     * @return bool
     */
    public static function toBool(int $type, string $varName, bool $default = false): bool
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
     * @param InputType $type             One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string    $varName          Name of a variable to get
     * @param string[]  $default          Default value if variable doesn't exist or is not an array
     * @param string    $extraAcceptChars Extra accepted characters
     *
     * @return string[]
     */
    public static function strictArray(int $type, string $varName, array $default = [], string $extraAcceptChars = ''): array
    {
        $input = self::getInputFromType($type);
        $value = $input[$varName] ?? null;

        if (!is_array($value)) {
            return $default;
        }

        return Sanitize::strictArray($value, $extraAcceptChars);
    }

    /**
     * Gets a specific external variable by name from REQUEST (GET or POST).
     * POST takes priority when variable exists in both.
     *
     * @param string      $variableName Name of a variable to get
     * @param int         $filter       The ID of the filter to apply
     * @param mixed[]|int $options      Associative array of options or bitwise disjunction of flags
     *
     * @return mixed Value of the requested variable on success
     */
    private static function filterRequest(string $variableName, int $filter = FILTER_DEFAULT, $options = 0)
    {
        $type = (null !== filter_input(INPUT_POST, $variableName)) ? INPUT_POST : INPUT_GET;
        return filter_input($type, $variableName, $filter, $options);
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
     * Get a raw string value from input superglobal, applying only FILTER_UNSAFE_RAW.
     * Returns null if the variable doesn't exist or is not a string.
     *
     * @param InputType $type    One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string    $varName Name of a variable to get
     *
     * @return string|null
     */
    private static function getRawStringValue(int $type, string $varName): ?string
    {
        $filter  = FILTER_UNSAFE_RAW;
        $options = [
            'options' => ['default' => null],
        ];

        if (self::INPUT_REQUEST === $type) {
            $result = self::filterRequest($varName, $filter, $options);
        } elseif (INPUT_SERVER === $type) {
            // On some servers filter_input doesn't work with INPUT_SERVER
            $result = isset($_SERVER[$varName]) ? filter_var(wp_unslash($_SERVER[$varName]), $filter, $options) : null;
        } else {
            $result = filter_input($type, $varName, $filter, $options);
        }

        if (!is_string($result)) {
            return null;
        }

        return $result;
    }

    /**
     * Return value from input superglobal by type, null if it doesn't exist.
     *
     * @param InputType $type    One of INPUT_GET, INPUT_POST, INPUT_COOKIE, INPUT_SERVER, INPUT_ENV or self::INPUT_REQUEST
     * @param string    $varName Name of a variable to get
     *
     * @return string|string[]|null
     */
    private static function getValueByType(int $type, string $varName)
    {
        $doNothingCallback = (fn($v) => $v);

        if (self::INPUT_REQUEST === $type) {
            $type = (null !== filter_input(INPUT_POST, $varName)) ? INPUT_POST : INPUT_GET;
        }

        if (INPUT_SERVER === $type) {
            // On some servers filter_input doesn't work with INPUT_SERVER
            if (isset($_SERVER[$varName])) {
                $value = filter_var(wp_unslash($_SERVER[$varName]), FILTER_CALLBACK, ['options' => $doNothingCallback]);
            } else {
                $value = null;
            }
        } else {
            $value = filter_input($type, $varName, FILTER_CALLBACK, ['options' => $doNothingCallback]);
        }

        /** @var string|string[]|null $value */
        return $value;
    }
}
