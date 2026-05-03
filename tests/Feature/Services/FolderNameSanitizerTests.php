<?php

/**
 * Tests for FolderNameSanitizer
 *
 * Covers `ensureUnique` against the real taxonomy: it queries sibling
 * names via Database::getSiblingNameMatches, so unit-mocking would not
 * exercise the SQL pattern actually shipped in production.
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

use FoldSnap\Services\FolderNameSanitizer;
use FoldSnap\Services\TaxonomyService;
use InvalidArgumentException;
use WP_UnitTestCase;

class FolderNameSanitizerTests extends WP_UnitTestCase
{
    private FolderNameSanitizer $sanitizer;

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        TaxonomyService::register();
        $this->sanitizer = new FolderNameSanitizer();
    }

    /**
     * Test sanitize strips Excel-injection leading characters
     *
     * @return void
     */
    public function test_sanitize_strips_excel_injection_prefix(): void
    {
        $this->assertSame('SUM(1+1)', $this->sanitizer->sanitize('=SUM(1+1)'));
        $this->assertSame('cmd', $this->sanitizer->sanitize('+cmd'));
        $this->assertSame('user', $this->sanitizer->sanitize('@user'));
    }

    /**
     * Test sanitize strips ASCII control characters embedded in the name
     *
     * @return void
     */
    public function test_sanitize_strips_control_characters(): void
    {
        $this->assertSame('hello', $this->sanitizer->sanitize("hel\x01lo"));
    }

    /**
     * Test sanitize throws when input becomes empty after cleanup
     *
     * @return void
     */
    public function test_sanitize_throws_when_empty_after_cleanup(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->sanitizer->sanitize('   ');
    }

    /**
     * Test sanitize caps names longer than the max length
     *
     * @return void
     */
    public function test_sanitize_caps_long_names(): void
    {
        $long   = str_repeat('a', 250);
        $result = $this->sanitizer->sanitize($long);
        $this->assertSame(200, mb_strlen($result));
    }

    /**
     * Test ensureUnique returns the name unchanged when no siblings exist
     *
     * @return void
     */
    public function test_ensure_unique_returns_name_when_no_siblings(): void
    {
        $this->assertSame(
            'Photos',
            $this->sanitizer->ensureUnique('Photos', 0)
        );
    }

    /**
     * Test ensureUnique returns the name when only numbered siblings exist
     *
     * "Photos (3)" alone is not a conflict for "Photos" — the original wins.
     *
     * @return void
     */
    public function test_ensure_unique_returns_name_when_only_numbered_siblings_exist(): void
    {
        $this->createTerm('Photos (3)');
        $this->assertSame(
            'Photos',
            $this->sanitizer->ensureUnique('Photos', 0)
        );
    }

    /**
     * Test ensureUnique appends "(2)" when only an exact match exists
     *
     * @return void
     */
    public function test_ensure_unique_appends_2_when_only_exact_match_exists(): void
    {
        $this->createTerm('Photos');
        $this->assertSame(
            'Photos (2)',
            $this->sanitizer->ensureUnique('Photos', 0)
        );
    }

    /**
     * Test ensureUnique picks max suffix + 1 across exact and numbered matches
     *
     * Exact "Photos" + "Photos (3)" + "Photos (5)" → result "Photos (6)".
     *
     * @return void
     */
    public function test_ensure_unique_picks_max_plus_one_with_exact_and_numbered(): void
    {
        $this->createTerm('Photos');
        $this->createTerm('Photos (3)');
        $this->createTerm('Photos (5)');
        $this->assertSame(
            'Photos (6)',
            $this->sanitizer->ensureUnique('Photos', 0)
        );
    }

    /**
     * Test ensureUnique excludes the term being renamed (self) from the check
     *
     * @return void
     */
    public function test_ensure_unique_excludes_self_id(): void
    {
        $termId = $this->createTerm('Photos');
        $this->assertSame(
            'Photos',
            $this->sanitizer->ensureUnique('Photos', 0, $termId)
        );
    }

    /**
     * Test ensureUnique is scoped to the given parent
     *
     * Same name under a different parent does not count as a conflict.
     *
     * @return void
     */
    public function test_ensure_unique_is_scoped_to_parent(): void
    {
        $parent = $this->createTerm('Parent');
        $this->createTerm('Photos', ['parent' => $parent]);
        $this->assertSame(
            'Photos',
            $this->sanitizer->ensureUnique('Photos', 0)
        );
    }

    /**
     * Test ensureUnique compares names case-insensitively
     *
     * @return void
     */
    public function test_ensure_unique_is_case_insensitive_on_exact_match(): void
    {
        $this->createTerm('Photos');
        $this->assertSame(
            'photos (2)',
            $this->sanitizer->ensureUnique('photos', 0)
        );
    }

    /**
     * Insert a taxonomy term and return its ID
     *
     * @param string               $name Term name.
     * @param array<string, mixed> $args Optional wp_insert_term args.
     *
     * @return int Term ID.
     */
    private function createTerm(string $name, array $args = []): int
    {
        $result = wp_insert_term($name, TaxonomyService::TAXONOMY_NAME, $args);
        return (int) $result['term_id'];
    }
}
