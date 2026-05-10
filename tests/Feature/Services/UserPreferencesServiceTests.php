<?php

/**
 * Tests for UserPreferencesService
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

use FoldSnap\Services\UserPreferencesService;
use InvalidArgumentException;
use WP_UnitTestCase;

class UserPreferencesServiceTests extends WP_UnitTestCase
{
    private UserPreferencesService $service;
    private int $userId;

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $this->service = new UserPreferencesService();
        $this->userId  = self::factory()->user->create(['role' => 'administrator']);
    }

    /**
     * Test getDefaults returns the values declared in the schema
     *
     * @return void
     */
    public function test_get_defaults_returns_declared_schema_defaults(): void
    {
        $defaults = $this->service->getDefaults();

        $this->assertSame([0], $defaults['expandedFolders']);
        $this->assertFalse($defaults['allMedia']);
        $this->assertSame(280, $defaults['sidebarWidth']);
    }

    /**
     * Test isKnownKey accepts schema keys and rejects unknown ones
     *
     * @return void
     */
    public function test_is_known_key_recognises_declared_keys(): void
    {
        $this->assertTrue($this->service->isKnownKey('expandedFolders'));
        $this->assertTrue($this->service->isKnownKey('allMedia'));
        $this->assertFalse($this->service->isKnownKey('nonexistent'));
    }

    /**
     * Test getAll returns defaults for a user with no stored preferences
     *
     * @return void
     */
    public function test_get_all_returns_defaults_when_user_has_no_stored_prefs(): void
    {
        $all = $this->service->getAll($this->userId);

        $this->assertSame([0], $all['expandedFolders']);
        $this->assertFalse($all['allMedia']);
    }

    /**
     * Test set then get roundtrip for an int_array preference
     *
     * @return void
     */
    public function test_set_then_get_roundtrip_for_int_array(): void
    {
        $ok = $this->service->set($this->userId, 'expandedFolders', [3, 7, 12]);

        $this->assertTrue($ok);
        $this->assertSame([3, 7, 12], $this->service->get($this->userId, 'expandedFolders'));
    }

    /**
     * Test set then get roundtrip for a bool preference
     *
     * @return void
     */
    public function test_set_then_get_roundtrip_for_bool(): void
    {
        $ok = $this->service->set($this->userId, 'allMedia', true);

        $this->assertTrue($ok);
        $this->assertTrue($this->service->get($this->userId, 'allMedia'));
    }

    /**
     * Test getAll fills missing keys with their declared defaults
     *
     * @return void
     */
    public function test_get_all_fills_missing_keys_with_defaults(): void
    {
        $this->service->set($this->userId, 'allMedia', true);

        $all = $this->service->getAll($this->userId);

        $this->assertTrue($all['allMedia']);
        $this->assertSame([0], $all['expandedFolders']);
    }

    /**
     * Test that setting one preference does not erase others
     *
     * @return void
     */
    public function test_set_preserves_other_keys(): void
    {
        $this->service->set($this->userId, 'expandedFolders', [1, 2]);
        $this->service->set($this->userId, 'allMedia', true);

        $all = $this->service->getAll($this->userId);

        $this->assertSame([1, 2], $all['expandedFolders']);
        $this->assertTrue($all['allMedia']);
    }

    /**
     * Test set throws InvalidArgumentException on a key not in the schema
     *
     * @return void
     */
    public function test_set_unknown_key_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->set($this->userId, 'totallyMadeUp', 1);
    }

    /**
     * Test get throws InvalidArgumentException on a key not in the schema
     *
     * @return void
     */
    public function test_get_unknown_key_throws_invalid_argument(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->service->get($this->userId, 'totallyMadeUp');
    }

    /**
     * Test bool coercion rejects non-coercible string values
     *
     * @return void
     */
    public function test_set_bool_with_non_coercible_string_returns_false(): void
    {
        $ok = $this->service->set($this->userId, 'allMedia', 'banana');

        $this->assertFalse($ok);
        $this->assertFalse($this->service->get($this->userId, 'allMedia'));
    }

    /**
     * Test bool coercion rejects null
     *
     * @return void
     */
    public function test_set_bool_with_null_returns_false(): void
    {
        $ok = $this->service->set($this->userId, 'allMedia', null);

        $this->assertFalse($ok);
    }

    /**
     * Test bool coercion accepts the string 'true'
     *
     * @return void
     */
    public function test_set_bool_accepts_string_true(): void
    {
        $ok = $this->service->set($this->userId, 'allMedia', 'true');

        $this->assertTrue($ok);
        $this->assertTrue($this->service->get($this->userId, 'allMedia'));
    }

    /**
     * Test int_array silently filters non-numeric and negative entries (0 is valid: it is Root's ID)
     *
     * @return void
     */
    public function test_set_int_array_filters_invalid_elements_silently(): void
    {
        $ok = $this->service->set($this->userId, 'expandedFolders', [1, 'abc', 0, -3, '5']);

        $this->assertTrue($ok);
        $this->assertSame([1, 0, 5], $this->service->get($this->userId, 'expandedFolders'));
    }

    /**
     * Test int_array deduplicates repeated values
     *
     * @return void
     */
    public function test_set_int_array_deduplicates(): void
    {
        $ok = $this->service->set($this->userId, 'expandedFolders', [1, 2, 1, 3, 2]);

        $this->assertTrue($ok);
        $this->assertSame([1, 2, 3], $this->service->get($this->userId, 'expandedFolders'));
    }

    /**
     * Test int_array rejects a non-array root value
     *
     * @return void
     */
    public function test_set_int_array_rejects_non_array_root(): void
    {
        $ok = $this->service->set($this->userId, 'expandedFolders', 'not an array');

        $this->assertFalse($ok);
    }

    /**
     * Test int_array can be cleared by storing an empty array
     *
     * @return void
     */
    public function test_set_int_array_with_empty_array_persists_empty(): void
    {
        $this->service->set($this->userId, 'expandedFolders', [1, 2, 3]);
        $ok = $this->service->set($this->userId, 'expandedFolders', []);

        $this->assertTrue($ok);
        $this->assertSame([], $this->service->get($this->userId, 'expandedFolders'));
    }

    /**
     * Test int coercion accepts a numeric value within range
     *
     * @return void
     */
    public function test_set_int_within_range_persists_as_is(): void
    {
        $ok = $this->service->set($this->userId, 'sidebarWidth', 320);

        $this->assertTrue($ok);
        $this->assertSame(320, $this->service->get($this->userId, 'sidebarWidth'));
    }

    /**
     * Test int coercion clamps a value below the declared min
     *
     * @return void
     */
    public function test_set_int_below_min_is_clamped_to_min(): void
    {
        $ok = $this->service->set($this->userId, 'sidebarWidth', 100);

        $this->assertTrue($ok);
        $this->assertSame(200, $this->service->get($this->userId, 'sidebarWidth'));
    }

    /**
     * Test int coercion clamps a value above the declared max
     *
     * @return void
     */
    public function test_set_int_above_max_is_clamped_to_max(): void
    {
        $ok = $this->service->set($this->userId, 'sidebarWidth', 9000);

        $this->assertTrue($ok);
        $this->assertSame(600, $this->service->get($this->userId, 'sidebarWidth'));
    }

    /**
     * Test int coercion accepts numeric strings
     *
     * @return void
     */
    public function test_set_int_accepts_numeric_string(): void
    {
        $ok = $this->service->set($this->userId, 'sidebarWidth', '350');

        $this->assertTrue($ok);
        $this->assertSame(350, $this->service->get($this->userId, 'sidebarWidth'));
    }

    /**
     * Test int coercion rejects non-numeric values
     *
     * @return void
     */
    public function test_set_int_with_non_numeric_returns_false(): void
    {
        $ok = $this->service->set($this->userId, 'sidebarWidth', 'wide');

        $this->assertFalse($ok);
        $this->assertSame(280, $this->service->get($this->userId, 'sidebarWidth'));
    }

    /**
     * Test that two users have independent preference storage
     *
     * @return void
     */
    public function test_two_users_have_separate_preferences(): void
    {
        $otherUserId = self::factory()->user->create(['role' => 'administrator']);

        $this->service->set($this->userId, 'expandedFolders', [9, 9, 9]);

        $this->assertSame([9], $this->service->get($this->userId, 'expandedFolders'));
        $this->assertSame([0], $this->service->get($otherUserId, 'expandedFolders'));
    }
}
