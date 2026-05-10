<?php

/**
 * Per-user UI preferences storage with a closed schema.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Services;

use InvalidArgumentException;

class UserPreferencesService
{
    /** @var string Single user_meta key holding the whole preferences map */
    private const META_KEY = 'foldsnap_opt_preferences';

    /**
     * Closed schema: every preference must be declared here.
     *
     * @var array<string, array{type: string, default: mixed}>
     */
    private const SCHEMA = [
        'expandedFolders' => [
            'type'    => 'int_array',
            'default' => [],
        ],
        'allMedia'        => [
            'type'    => 'bool',
            'default' => false,
        ],
    ];

    /**
     * Whether $key is part of the declared schema.
     *
     * @param string $key Preference key
     *
     * @return bool
     */
    public function isKnownKey(string $key): bool
    {
        return array_key_exists($key, self::SCHEMA);
    }

    /**
     * Map of key → declared default value.
     *
     * @return array<string, mixed>
     */
    public function getDefaults(): array
    {
        $defaults = [];
        foreach (self::SCHEMA as $key => $spec) {
            $defaults[$key] = $spec['default'];
        }
        return $defaults;
    }

    /**
     * Read every preference for $userId, filling missing entries with defaults.
     *
     * Always returns a complete map: callers never have to handle missing keys.
     *
     * @param int $userId User ID
     *
     * @return array<string, mixed>
     */
    public function getAll(int $userId): array
    {
        $stored = $this->readStoredMap($userId);
        $result = [];

        foreach (self::SCHEMA as $key => $spec) {
            $result[$key] = array_key_exists($key, $stored)
                ? $stored[$key]
                : $spec['default'];
        }

        return $result;
    }

    /**
     * Read a single preference value, falling back to its declared default.
     *
     * @param int    $userId User ID
     * @param string $key    Preference key
     *
     * @return mixed
     *
     * @throws InvalidArgumentException If $key is not in the schema.
     */
    public function get(int $userId, string $key)
    {
        if (! $this->isKnownKey($key)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Unknown preference key: %s', $key))
            );
        }

        $stored = $this->readStoredMap($userId);

        return array_key_exists($key, $stored)
            ? $stored[$key]
            : self::SCHEMA[$key]['default'];
    }

    /**
     * Write one preference, merging into the existing map.
     *
     * @param int    $userId User ID
     * @param string $key    Preference key
     * @param mixed  $value  Raw value from the caller
     *
     * @return bool True on success, false if the value is not coercible to the declared type.
     *
     * @throws InvalidArgumentException If $key is not in the schema.
     */
    public function set(int $userId, string $key, $value): bool
    {
        if (! $this->isKnownKey($key)) {
            throw new InvalidArgumentException(
                esc_html(sprintf('Unknown preference key: %s', $key))
            );
        }

        [
            $ok,
            $coerced,
        ] = $this->coerce(self::SCHEMA[$key]['type'], $value);
        if (! $ok) {
            return false;
        }

        $stored       = $this->readStoredMap($userId);
        $stored[$key] = $coerced;

        update_user_meta($userId, self::META_KEY, $stored);

        return true;
    }

    /**
     * Read the raw stored map from user_meta, normalising to an array.
     *
     * @param int $userId User ID
     *
     * @return array<string, mixed>
     */
    private function readStoredMap(int $userId): array
    {
        $raw = get_user_meta($userId, self::META_KEY, true);

        if (! is_array($raw)) {
            return [];
        }

        /** @var array<string, mixed> $raw */
        return $raw;
    }

    /**
     * Coerce a raw value to the declared type.
     *
     * @param string $type  Schema type token
     * @param mixed  $value Raw value
     *
     * @return array{0: bool, 1: mixed} [success, coerced value]
     */
    private function coerce(string $type, $value): array
    {
        switch ($type) {
            case 'bool':
                if (null === $value) {
                    return [
                        false,
                        null,
                    ];
                }
                $coerced = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
                if (null === $coerced) {
                    return [
                        false,
                        null,
                    ];
                }
                return [
                    true,
                    $coerced,
                ];

            case 'int_array':
                if (! is_array($value)) {
                    return [
                        false,
                        null,
                    ];
                }
                $ints = [];
                foreach ($value as $entry) {
                    if (! is_numeric($entry)) {
                        continue;
                    }
                    $int = (int) $entry;
                    if ($int <= 0) {
                        continue;
                    }
                    if (! in_array($int, $ints, true)) {
                        $ints[] = $int;
                    }
                }
                return [
                    true,
                    $ints,
                ];

            default:
                return [
                    false,
                    null,
                ];
        }
    }
}
