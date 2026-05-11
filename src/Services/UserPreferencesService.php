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

    public const SIDEBAR_WIDTH_MIN     = 200;
    public const SIDEBAR_WIDTH_MAX     = 600;
    public const SIDEBAR_WIDTH_DEFAULT = 280;

    /**
     * Closed schema: every preference must be declared here.
     *
     * @var array<string, array{type: string, default: mixed, min?: int, max?: int}>
     */
    private const SCHEMA = [
        'expandedFolders'  => [
            'type'    => 'int_array',
            // Root (id 0) is expanded by default so the user sees the
            // top-level folders without needing to click first.
            'default' => [0],
        ],
        'allMedia'         => [
            'type'    => 'bool',
            'default' => false,
        ],
        'sidebarWidth'     => [
            'type'    => 'int',
            'default' => self::SIDEBAR_WIDTH_DEFAULT,
            'min'     => self::SIDEBAR_WIDTH_MIN,
            'max'     => self::SIDEBAR_WIDTH_MAX,
        ],
        'selectedFolderId' => [
            'type'    => 'int',
            'default' => 0,
            'min'     => 0,
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
        ] = $this->coerce(self::SCHEMA[$key], $value);
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
     * Coerce a raw value against a schema spec.
     *
     * @param array{type: string, default: mixed, min?: int, max?: int} $spec  Schema entry for the key
     * @param mixed                                                     $value Raw value
     *
     * @return array{0: bool, 1: mixed} [success, coerced value]
     */
    private function coerce(array $spec, $value): array
    {
        switch ($spec['type']) {
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

            case 'int':
                if (! is_numeric($value)) {
                    return [
                        false,
                        null,
                    ];
                }
                $int = (int) $value;
                if (isset($spec['min']) && $int < $spec['min']) {
                    $int = $spec['min'];
                }
                if (isset($spec['max']) && $int > $spec['max']) {
                    $int = $spec['max'];
                }
                return [
                    true,
                    $int,
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
                    // Negative IDs cannot reference a real folder (Root is 0).
                    if ($int < 0) {
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
