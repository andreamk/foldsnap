<?php

/**
 * Tests for CountersRecalculator
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\CountersRecalculator;
use FoldSnap\Services\FolderCounterService;
use FoldSnap\Services\FolderNameSanitizer;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class CountersRecalculatorTests extends WP_UnitTestCase
{
    private FolderCounterService $counters;
    private FolderRepository $repository;
    private CountersRecalculator $recalculator;

    /**
     * Set up
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        TaxonomyService::register();
        $this->counters     = new FolderCounterService();
        $this->repository   = new FolderRepository(new FolderNameSanitizer(), $this->counters);
        $this->recalculator = new CountersRecalculator($this->counters);
    }

    /**
     * Tear down — reset state for next test
     *
     * @return void
     */
    public function tearDown(): void
    {
        delete_option(CountersRecalculator::OPT_STACK);
        delete_option(CountersRecalculator::OPT_INITIALIZED);
        delete_option(FolderCounterService::OPT_ROOT_SIZE);
        delete_option(FolderCounterService::OPT_ROOT_COUNT);
        parent::tearDown();
    }

    /**
     * Test running on an empty tree finishes immediately and marks initialized
     *
     * @return void
     */
    public function test_run_on_empty_tree_completes(): void
    {
        $result = $this->recalculator->processChunk();

        $this->assertTrue($result['done']);
        $this->assertSame(0, $result['processed']);
        $this->assertSame(0, $result['remaining']);
        $this->assertTrue($this->recalculator->isInitialized());
    }

    /**
     * Test full pass over a tree leaves correct totals on every node
     *
     * @return void
     */
    public function test_full_pass_writes_correct_totals(): void
    {
        $parent = $this->repository->create('Parent');
        $child  = $this->repository->create('Child', $parent->getId());
        $grand  = $this->repository->create('Grand', $child->getId());

        $att1 = $this->createAttachmentWithSize(100);
        $att2 = $this->createAttachmentWithSize(200);
        $att3 = $this->createAttachmentWithSize(50);

        // Attach via raw WP API to bypass the incremental delta logic — this
        // simulates the migration scenario where counter meta is stale and
        // recalculate has to repopulate it from scratch.
        wp_set_object_terms($att1, $parent->getId(), TaxonomyService::TAXONOMY_NAME);
        wp_set_object_terms($att2, $child->getId(), TaxonomyService::TAXONOMY_NAME);
        wp_set_object_terms($att3, $grand->getId(), TaxonomyService::TAXONOMY_NAME);

        // Drive recalculate to completion.
        do {
            $result = $this->recalculator->processChunk(2);
        } while (! $result['done']);

        $this->assertSame('1', get_term_meta($grand->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('50', get_term_meta($grand->getId(), FolderModel::META_SIZE, true));

        $this->assertSame('2', get_term_meta($child->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('250', get_term_meta($child->getId(), FolderModel::META_SIZE, true));

        $this->assertSame('3', get_term_meta($parent->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('350', get_term_meta($parent->getId(), FolderModel::META_SIZE, true));

        $this->assertSame(3, $this->counters->getGlobalCount());
        $this->assertSame(350, $this->counters->getGlobalSize());
    }

    /**
     * Test recalculate is idempotent — running twice yields the same result
     *
     * @return void
     */
    public function test_idempotent(): void
    {
        $folder = $this->repository->create('Folder');

        $att = $this->createAttachmentWithSize(123);
        wp_set_object_terms($att, $folder->getId(), TaxonomyService::TAXONOMY_NAME);

        do {
            $r = $this->recalculator->processChunk(10);
        } while (! $r['done']);

        $size1  = get_term_meta($folder->getId(), FolderModel::META_SIZE, true);
        $count1 = get_term_meta($folder->getId(), FolderModel::META_COUNT, true);

        // Second pass: reset stack + flag, run again.
        $this->recalculator->reset();
        do {
            $r = $this->recalculator->processChunk(10);
        } while (! $r['done']);

        $this->assertSame($size1, get_term_meta($folder->getId(), FolderModel::META_SIZE, true));
        $this->assertSame($count1, get_term_meta($folder->getId(), FolderModel::META_COUNT, true));
    }

    /**
     * Test reset() clears the stack and the initialized flag
     *
     * @return void
     */
    public function test_reset_clears_state(): void
    {
        update_option(CountersRecalculator::OPT_STACK, [1, 2, 3]);
        update_option(CountersRecalculator::OPT_INITIALIZED, '1');

        $this->recalculator->reset();

        $this->assertFalse(get_option(CountersRecalculator::OPT_STACK));
        $this->assertFalse(get_option(CountersRecalculator::OPT_INITIALIZED));
        $this->assertFalse($this->recalculator->isInitialized());
    }

    /**
     * Test ensureFolderCountersInitialized normalizes legacy terms
     *
     * @return void
     */
    public function test_first_chunk_normalizes_meta_for_existing_terms(): void
    {
        // Insert a term via raw WP API — no meta initialization.
        $result = wp_insert_term('Legacy', TaxonomyService::TAXONOMY_NAME);
        $this->assertIsArray($result);
        $termId = (int) $result['term_id'];

        $this->assertSame('', get_term_meta($termId, FolderModel::META_SIZE, true));
        $this->assertSame('', get_term_meta($termId, FolderModel::META_COUNT, true));

        do {
            $r = $this->recalculator->processChunk(10);
        } while (! $r['done']);

        $this->assertSame('0', get_term_meta($termId, FolderModel::META_SIZE, true));
        $this->assertSame('0', get_term_meta($termId, FolderModel::META_COUNT, true));
    }

    /**
     * Create attachment with filesize meta
     *
     * @param int $fileSize Size in bytes.
     *
     * @return int Attachment ID.
     */
    private function createAttachmentWithSize(int $fileSize): int
    {
        $attachmentId = $this->factory()->attachment->create();
        update_post_meta($attachmentId, '_wp_attachment_metadata', ['filesize' => $fileSize]);
        return $attachmentId;
    }
}
