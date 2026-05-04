<?php

/**
 * Tests for FolderCounterService
 *
 * Covers the option-backed Root counters, the cached unassigned counters,
 * and the chain-delta writer.
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\FolderCounterService;
use FoldSnap\Services\FolderNameSanitizer;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\MediaFolderAssignmentService;
use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class FolderCounterServiceTests extends WP_UnitTestCase
{
    private FolderCounterService $counters;
    private FolderRepository $repository;
    private MediaFolderAssignmentService $assignments;

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        TaxonomyService::register();
        delete_option(FolderCounterService::OPT_ROOT_SIZE);
        delete_option(FolderCounterService::OPT_ROOT_COUNT);

        $this->counters    = new FolderCounterService();
        $this->repository  = new FolderRepository(new FolderNameSanitizer(), $this->counters);
        $this->assignments = new MediaFolderAssignmentService($this->repository, $this->counters);

        $this->counters->invalidateUnassignedCache();
    }

    /**
     * Test getUnassignedCount counts unassigned media
     *
     * @return void
     */
    public function test_unassigned_count_counts_unassigned(): void
    {
        $this->factory()->attachment->create();
        $this->factory()->attachment->create();

        $this->assertSame(2, $this->counters->getUnassignedCount());
    }

    /**
     * Test getUnassignedCount excludes assigned media
     *
     * @return void
     */
    public function test_unassigned_count_excludes_assigned(): void
    {
        $folderId = $this->createTerm('Photos');
        $assigned = $this->factory()->attachment->create();
        $this->factory()->attachment->create();

        $this->assignments->assign($folderId, [$assigned]);

        $this->assertSame(1, $this->counters->getUnassignedCount());
    }

    /**
     * Test getUnassignedSize returns the size of unassigned media
     *
     * @return void
     */
    public function test_unassigned_size_returns_unassigned_size(): void
    {
        $this->createAttachmentWithSize(1500);
        $this->createAttachmentWithSize(2500);

        $this->assertSame(4000, $this->counters->getUnassignedSize());
    }

    /**
     * Test getUnassignedSize excludes assigned media
     *
     * @return void
     */
    public function test_unassigned_size_excludes_assigned(): void
    {
        $folderId = $this->createTerm('Photos');
        $assigned = $this->createAttachmentWithSize(3000);
        $this->createAttachmentWithSize(1000);

        $this->assignments->assign($folderId, [$assigned]);

        $this->assertSame(1000, $this->counters->getUnassignedSize());
    }

    /**
     * Test adjustRoot writes the option-backed Root totals
     *
     * @return void
     */
    public function test_adjust_root_writes_options(): void
    {
        $this->counters->adjustRoot(2048, 3);
        $this->assertSame(2048, $this->counters->getGlobalSize());
        $this->assertSame(3, $this->counters->getGlobalCount());

        // Negative deltas accumulate.
        $this->counters->adjustRoot(-512, -1);
        $this->assertSame(1536, $this->counters->getGlobalSize());
        $this->assertSame(2, $this->counters->getGlobalCount());
    }

    /**
     * Test adjustRoot clamps to zero (never goes negative)
     *
     * @return void
     */
    public function test_adjust_root_clamps_to_zero(): void
    {
        update_option(FolderCounterService::OPT_ROOT_COUNT, 1);

        $this->counters->adjustRoot(0, -5);

        $this->assertSame(0, $this->counters->getGlobalCount());
    }

    /**
     * Test setRoot replaces the Root totals with absolute values
     *
     * @return void
     */
    public function test_set_root_writes_absolute_values(): void
    {
        $this->counters->adjustRoot(1000, 5);
        $this->counters->setRoot(42, 7);

        $this->assertSame(42, $this->counters->getGlobalSize());
        $this->assertSame(7, $this->counters->getGlobalCount());
    }

    /**
     * Test invalidateUnassignedCache forces a recompute on the next read
     *
     * @return void
     */
    public function test_invalidate_unassigned_cache_recomputes(): void
    {
        $this->factory()->attachment->create();
        $this->assertSame(1, $this->counters->getUnassignedCount());

        $this->factory()->attachment->create();
        // Without invalidation, the cached value would still be 1.
        $this->counters->invalidateUnassignedCache();
        $this->assertSame(2, $this->counters->getUnassignedCount());
    }

    /**
     * Test applyChainDelta updates the term meta on every term in the chain
     *
     * @return void
     */
    public function test_apply_chain_delta_updates_meta(): void
    {
        $parent = $this->repository->create('Parent');
        $child  = $this->repository->create('Child', $parent->getId());

        $this->counters->applyChainDelta([$child->getId(), $parent->getId()], 1500, 2);

        $this->assertSame('2', get_term_meta($child->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('1500', get_term_meta($child->getId(), FolderModel::META_SIZE, true));
        $this->assertSame('2', get_term_meta($parent->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('1500', get_term_meta($parent->getId(), FolderModel::META_SIZE, true));
    }

    /**
     * Test applyChainDelta is a no-op for an empty chain
     *
     * @return void
     */
    public function test_apply_chain_delta_no_op_for_empty_chain(): void
    {
        $folderId = $this->createTerm('Photos');
        update_term_meta($folderId, FolderModel::META_SIZE, '500');
        update_term_meta($folderId, FolderModel::META_COUNT, '3');

        $this->counters->applyChainDelta([], 1000, 5);

        $this->assertSame('500', get_term_meta($folderId, FolderModel::META_SIZE, true));
        $this->assertSame('3', get_term_meta($folderId, FolderModel::META_COUNT, true));
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
     * Create an attachment with filesize in its metadata
     *
     * @param int $fileSize File size in bytes
     *
     * @return int Attachment post ID
     */
    private function createAttachmentWithSize(int $fileSize): int
    {
        $attachmentId = $this->factory()->attachment->create();

        update_post_meta(
            $attachmentId,
            '_wp_attachment_metadata',
            ['filesize' => $fileSize]
        );

        return $attachmentId;
    }
}
