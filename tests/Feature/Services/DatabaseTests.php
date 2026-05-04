<?php

/**
 * Tests for Database service queries
 *
 * Covers Step 8 additions: getDescendantIds, getChildrenCounts,
 * getDirectSizesForFolders.
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

use FoldSnap\Services\Database;
use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class DatabaseTests extends WP_UnitTestCase
{
    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        TaxonomyService::register();
    }

    /**
     * Test getDescendantIds returns empty map when input is empty
     *
     * @return void
     */
    public function test_get_descendant_ids_empty_input(): void
    {
        $result = Database::getDescendantIds([], TaxonomyService::TAXONOMY_NAME);
        $this->assertSame([], $result);
    }

    /**
     * Test getDescendantIds returns empty descendants for childless roots
     *
     * @return void
     */
    public function test_get_descendant_ids_no_children(): void
    {
        $rootId = $this->createTerm('Root');

        $result = Database::getDescendantIds([$rootId], TaxonomyService::TAXONOMY_NAME);

        $this->assertSame([$rootId => []], $result);
    }

    /**
     * Test getDescendantIds walks multiple levels
     *
     * @return void
     */
    public function test_get_descendant_ids_walks_multiple_levels(): void
    {
        $root       = $this->createTerm('Root');
        $child      = $this->createTerm('Child', ['parent' => $root]);
        $grandchild = $this->createTerm('Grandchild', ['parent' => $child]);
        $sibling    = $this->createTerm('Sibling', ['parent' => $root]);

        $result = Database::getDescendantIds([$root], TaxonomyService::TAXONOMY_NAME);

        $this->assertArrayHasKey($root, $result);
        $descendants = $result[$root];
        sort($descendants);
        $expected = [
            $child,
            $grandchild,
            $sibling,
        ];
        sort($expected);
        $this->assertSame($expected, $descendants);
    }

    /**
     * Test getDescendantIds groups results by root
     *
     * @return void
     */
    public function test_get_descendant_ids_groups_by_root(): void
    {
        $rootA      = $this->createTerm('A');
        $childA     = $this->createTerm('A1', ['parent' => $rootA]);
        $rootB      = $this->createTerm('B');
        $childB     = $this->createTerm('B1', ['parent' => $rootB]);
        $grandB     = $this->createTerm('B1a', ['parent' => $childB]);
        $standalone = $this->createTerm('Standalone');

        $result = Database::getDescendantIds([$rootA, $rootB, $standalone], TaxonomyService::TAXONOMY_NAME);

        $this->assertSame([$childA], $result[$rootA]);
        $bDescendants = $result[$rootB];
        sort($bDescendants);
        $bExpected = [
            $childB,
            $grandB,
        ];
        sort($bExpected);
        $this->assertSame($bExpected, $bDescendants);
        $this->assertSame([], $result[$standalone]);
    }

    /**
     * Test getDescendantIds returns full descendant list per root
     * even when one root is itself a descendant of another root
     *
     * Regression: an earlier implementation marked all root IDs as "seen"
     * upfront, so a root that was a descendant of another root would not
     * appear in that other root's descendants list.
     *
     * @return void
     */
    public function test_get_descendant_ids_handles_overlapping_roots(): void
    {
        $parent = $this->createTerm('Parent');
        $child  = $this->createTerm('Child', ['parent' => $parent]);

        $result = Database::getDescendantIds([$parent, $child], TaxonomyService::TAXONOMY_NAME);

        $this->assertSame([$child], $result[$parent]);
        $this->assertSame([], $result[$child]);
    }

    /**
     * Test getDescendantIds ignores invalid IDs
     *
     * @return void
     */
    public function test_get_descendant_ids_ignores_invalid_ids(): void
    {
        $rootId = $this->createTerm('Root');
        $child  = $this->createTerm('Child', ['parent' => $rootId]);

        $result = Database::getDescendantIds([$rootId, 0, -1], TaxonomyService::TAXONOMY_NAME);

        $this->assertArrayNotHasKey(0, $result);
        $this->assertArrayNotHasKey(-1, $result);
        $this->assertSame([$child], $result[$rootId]);
    }

    /**
     * Test getChildrenCounts returns zero for parents with no children
     *
     * @return void
     */
    public function test_get_children_counts_no_children(): void
    {
        $parentId = $this->createTerm('Parent');

        $result = Database::getChildrenCounts([$parentId], TaxonomyService::TAXONOMY_NAME);

        $this->assertSame([$parentId => 0], $result);
    }

    /**
     * Test getChildrenCounts counts only direct children
     *
     * @return void
     */
    public function test_get_children_counts_direct_only(): void
    {
        $parentId = $this->createTerm('Parent');
        $childA   = $this->createTerm('A', ['parent' => $parentId]);
        $childB   = $this->createTerm('B', ['parent' => $parentId]);
        $this->createTerm('Grandchild', ['parent' => $childA]);

        $result = Database::getChildrenCounts([$parentId, $childA, $childB], TaxonomyService::TAXONOMY_NAME);

        $this->assertSame(2, $result[$parentId]);
        $this->assertSame(1, $result[$childA]);
        $this->assertSame(0, $result[$childB]);
    }

    /**
     * Test getChildrenCounts returns empty for empty input
     *
     * @return void
     */
    public function test_get_children_counts_empty_input(): void
    {
        $this->assertSame([], Database::getChildrenCounts([], TaxonomyService::TAXONOMY_NAME));
    }

    /**
     * Test getDirectSizesForFolders sums attachment sizes per folder
     *
     * @return void
     */
    public function test_get_direct_sizes_sums_per_folder(): void
    {
        $folderA = $this->createTerm('A');
        $folderB = $this->createTerm('B');

        $att1 = $this->createAttachmentWithSize(1000);
        $att2 = $this->createAttachmentWithSize(2500);
        $att3 = $this->createAttachmentWithSize(700);

        wp_set_object_terms($att1, $folderA, TaxonomyService::TAXONOMY_NAME);
        wp_set_object_terms($att2, $folderA, TaxonomyService::TAXONOMY_NAME);
        wp_set_object_terms($att3, $folderB, TaxonomyService::TAXONOMY_NAME);

        $result = Database::getDirectSizesForFolders([$folderA, $folderB], TaxonomyService::TAXONOMY_NAME);

        $this->assertSame(3500, $result[$folderA]);
        $this->assertSame(700, $result[$folderB]);
    }

    /**
     * Test getDirectSizesForFolders returns zero for folders with no media
     *
     * @return void
     */
    public function test_get_direct_sizes_zero_when_empty(): void
    {
        $folder = $this->createTerm('Empty');

        $result = Database::getDirectSizesForFolders([$folder], TaxonomyService::TAXONOMY_NAME);

        $this->assertSame([$folder => 0], $result);
    }

    /**
     * Test getDirectSizesForFolders ignores attachments with no filesize meta
     *
     * @return void
     */
    public function test_get_direct_sizes_ignores_missing_filesize(): void
    {
        $folder = $this->createTerm('Folder');
        $att    = $this->factory()->attachment->create();

        wp_set_object_terms($att, $folder, TaxonomyService::TAXONOMY_NAME);

        $result = Database::getDirectSizesForFolders([$folder], TaxonomyService::TAXONOMY_NAME);

        $this->assertSame(0, $result[$folder]);
    }

    /**
     * Test getDirectSizesForFolders is restricted to requested folders
     *
     * @return void
     */
    public function test_get_direct_sizes_restricted_to_requested(): void
    {
        $folderA = $this->createTerm('A');
        $folderB = $this->createTerm('B');

        $att = $this->createAttachmentWithSize(500);
        wp_set_object_terms($att, $folderB, TaxonomyService::TAXONOMY_NAME);

        $result = Database::getDirectSizesForFolders([$folderA], TaxonomyService::TAXONOMY_NAME);

        $this->assertSame([$folderA => 0], $result);
        $this->assertArrayNotHasKey($folderB, $result);
    }

    /**
     * Create a taxonomy term and return its ID
     *
     * @param string               $name Term name
     * @param array<string, mixed> $args Optional args for wp_insert_term
     *
     * @return int Term ID
     */
    private function createTerm(string $name, array $args = []): int
    {
        $result = wp_insert_term($name, TaxonomyService::TAXONOMY_NAME, $args);
        return (int) $result['term_id'];
    }

    /**
     * Create an attachment with a filesize meta entry
     *
     * @param int $fileSize File size in bytes
     *
     * @return int Attachment post ID
     */
    private function createAttachmentWithSize(int $fileSize): int
    {
        $attachmentId = $this->factory()->attachment->create();
        update_post_meta($attachmentId, '_wp_attachment_metadata', ['filesize' => $fileSize]);
        return $attachmentId;
    }
}
