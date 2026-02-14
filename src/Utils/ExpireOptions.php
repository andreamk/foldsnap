<?php

/**
 * Expire options - transient-like system using wp_options with expiration
 *
 * Uses regular WordPress options with embedded expiration timestamps,
 * providing more reliable behavior than transients for caching purposes.
 *
 * @package FoldSnap\Utils
 */

declare(strict_types=1);

namespace FoldSnap\Utils;

final class ExpireOptions
{
    const OPTION_PREFIX = 'foldsnap_opt_expire_';

    /** @var array<string, array{expire: int, value: mixed}> */
    private static $cacheOptions = [];

    /**
     * Sets/updates the value of an expire option.
     *
     * @param string $key        Expire option key.
     * @param mixed  $value      Option value.
     * @param int    $expiration Optional. Time until expiration in seconds. Default 0 (no expiration).
     *
     * @return bool True if the value was set, false otherwise.
     */
    public static function set(string $key, $value, int $expiration = 0): bool
    {
        $time = ($expiration > 0 ? time() + $expiration : 0);

        self::$cacheOptions[$key] = [
            'expire' => $time,
            'value'  => $value,
        ];

        return update_option(
            self::OPTION_PREFIX . $key,
            (string) wp_json_encode(self::$cacheOptions[$key]),
            true
        );
    }

    /**
     * Retrieve string value of an expire option.
     *
     * @param string $key     Expire option key.
     * @param string $default Return this value if option doesn't exist or is expired.
     *
     * @return string
     */
    public static function getString(string $key, string $default = ''): string
    {
        $value = self::getRaw($key);
        return is_string($value) ? $value : $default;
    }

    /**
     * Retrieve integer value of an expire option.
     *
     * @param string $key     Expire option key.
     * @param int    $default Return this value if option doesn't exist or is expired.
     *
     * @return int
     */
    public static function getInt(string $key, int $default = 0): int
    {
        $value = self::getRaw($key);
        if (is_int($value)) {
            return $value;
        }
        if (is_numeric($value)) {
            return (int) $value;
        }
        return $default;
    }

    /**
     * Retrieve boolean value of an expire option.
     *
     * @param string $key     Expire option key.
     * @param bool   $default Return this value if option doesn't exist or is expired.
     *
     * @return bool
     */
    public static function getBool(string $key, bool $default = false): bool
    {
        $value = self::getRaw($key);
        if (is_bool($value)) {
            return $value;
        }
        if (null === $value) {
            return $default;
        }
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Retrieve raw value of an expire option, null if not found or expired.
     *
     * @param string $key Expire option key.
     *
     * @return mixed Value of option, or null if not found/expired.
     */
    private static function getRaw(string $key)
    {
        if (!isset(self::$cacheOptions[$key])) {
            self::$cacheOptions[$key] = self::decodeOption(get_option(self::OPTION_PREFIX . $key));
        }

        if (self::$cacheOptions[$key]['expire'] < 0) {
            return null;
        }

        if (self::$cacheOptions[$key]['expire'] > 0 && self::$cacheOptions[$key]['expire'] < time()) {
            self::delete($key);
            return null;
        }

        return self::$cacheOptions[$key]['value'];
    }

    /**
     * Retrieves the expiration timestamp of an expire option.
     *
     * @param string $key Expire option key.
     *
     * @return int Expire timestamp, -1 if option doesn't exist or is expired.
     */
    public static function getExpireTime(string $key): int
    {
        self::getRaw($key);
        return self::$cacheOptions[$key]['expire'];
    }

    /**
     * Get string value, or set it if expired/missing.
     *
     * @param string $key        Expire option key.
     * @param string $value      Value to set if expired.
     * @param int    $expiration Time until expiration in seconds. Default 0 (no expiration).
     * @param string $default    Default if stored value is not a string.
     *
     * @return string Current value, or $default if expired (and value was updated).
     */
    public static function getUpdateString(string $key, string $value, int $expiration = 0, string $default = ''): string
    {
        if (self::updateIfExpired($key, $value, $expiration)) {
            return $default;
        }

        $stored = self::$cacheOptions[$key]['value'];
        return is_string($stored) ? $stored : $default;
    }

    /**
     * Get int value, or set it if expired/missing.
     *
     * @param string $key        Expire option key.
     * @param int    $value      Value to set if expired.
     * @param int    $expiration Time until expiration in seconds. Default 0 (no expiration).
     * @param int    $default    Default if stored value is not an int.
     *
     * @return int Current value, or $default if expired (and value was updated).
     */
    public static function getUpdateInt(string $key, int $value, int $expiration = 0, int $default = 0): int
    {
        if (self::updateIfExpired($key, $value, $expiration)) {
            return $default;
        }

        $stored = self::$cacheOptions[$key]['value'];
        if (is_int($stored)) {
            return $stored;
        }
        if (is_string($stored) && is_numeric($stored)) {
            return (int) $stored;
        }
        return $default;
    }

    /**
     * Get bool value, or set it if expired/missing.
     *
     * @param string $key        Expire option key.
     * @param bool   $value      Value to set if expired.
     * @param int    $expiration Time until expiration in seconds. Default 0 (no expiration).
     * @param bool   $default    Default if stored value is not a bool.
     *
     * @return bool Current value, or $default if expired (and value was updated).
     */
    public static function getUpdateBool(string $key, bool $value, int $expiration = 0, bool $default = false): bool
    {
        if (self::updateIfExpired($key, $value, $expiration)) {
            return $default;
        }

        $stored = self::$cacheOptions[$key]['value'];
        if (is_bool($stored)) {
            return $stored;
        }
        if (null === $stored) {
            return $default;
        }
        return filter_var($stored, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Load cache and set value if expired. Returns true if value was expired/missing.
     *
     * @param string $key        Expire option key.
     * @param mixed  $value      Value to set if expired.
     * @param int    $expiration Time until expiration in seconds.
     *
     * @return bool True if expired and updated, false if still valid.
     */
    private static function updateIfExpired(string $key, $value, int $expiration): bool
    {
        if (!isset(self::$cacheOptions[$key])) {
            self::$cacheOptions[$key] = self::decodeOption(get_option(self::OPTION_PREFIX . $key));
        }

        if (self::$cacheOptions[$key]['expire'] < time()) {
            self::set($key, $value, $expiration);
            return true;
        }

        return false;
    }

    /**
     * Deletes an option.
     *
     * @param string $key Expire option key.
     *
     * @return bool True if the option was deleted, false otherwise.
     */
    public static function delete(string $key): bool
    {
        if (delete_option(self::OPTION_PREFIX . $key)) {
            self::$cacheOptions[$key] = self::unexistsKeyValue();
            return true;
        }

        return false;
    }

    /**
     * Delete all expire options.
     *
     * @return bool
     */
    public static function deleteAll(): bool
    {
        /** @var \wpdb $wpdb */
        global $wpdb;

        $likePattern = $wpdb->esc_like(self::OPTION_PREFIX) . '%';

        /** @var string[] $optionNames */
        // WordPress Options API has no function to search options by prefix (LIKE pattern).
        // Direct query is the only way. Caching is unnecessary since results are immediately deleted.
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $optionNames = $wpdb->get_col(
            $wpdb->prepare(
                'SELECT option_name FROM %i WHERE option_name LIKE %s',
                $wpdb->base_prefix . 'options',
                $likePattern
            )
        );

        foreach ($optionNames as $optionName) {
            delete_option($optionName);
        }

        self::$cacheOptions = [];

        return true;
    }

    /**
     * Decode a raw option value into a typed cache entry.
     *
     * @param mixed $option Raw value from get_option().
     *
     * @return array{expire: int, value: mixed}
     */
    private static function decodeOption($option): array
    {
        if (!is_string($option)) {
            return self::unexistsKeyValue();
        }

        $decoded = json_decode($option, true);
        if (!is_array($decoded) || !array_key_exists('expire', $decoded) || !array_key_exists('value', $decoded)) {
            return self::unexistsKeyValue();
        }

        return [
            'expire' => is_numeric($decoded['expire']) ? (int) $decoded['expire'] : -1,
            'value'  => $decoded['value'],
        ];
    }

    /**
     * Return value for non-existent key option.
     *
     * @return array{expire: int, value: false}
     */
    private static function unexistsKeyValue(): array
    {
        return [
            'expire' => -1,
            'value'  => false,
        ];
    }
}
