<?php

/**
 * Tests for AttachmentLifecycleService
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\AttachmentLifecycleService;
use FoldSnap\Services\FolderCounterService;
use FoldSnap\Services\FolderNameSanitizer;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\MediaFolderAssignmentService;
use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class AttachmentLifecycleServiceTests extends WP_UnitTestCase
{
    private FolderCounterService $counters;
    private FolderRepository $repository;
    private MediaFolderAssignmentService $assignments;
    private AttachmentLifecycleService $service;

    /**
     * Set up
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        TaxonomyService::register();
        $this->counters    = new FolderCounterService();
        $this->repository  = new FolderRepository(new FolderNameSanitizer(), $this->counters);
        $this->assignments = new MediaFolderAssignmentService($this->repository, $this->counters);
        $this->service     = new AttachmentLifecycleService($this->counters);
    }

    /**
     * Tear down — clean global counter options
     *
     * @return void
     */
    public function tearDown(): void
    {
        delete_option(FolderCounterService::OPT_ROOT_SIZE);
        delete_option(FolderCounterService::OPT_ROOT_COUNT);
        parent::tearDown();
    }

    /**
     * Test new uploads bump the global Root counters
     *
     * @return void
     */
    public function test_metadata_generated_increments_root_counters(): void
    {
        $att = $this->factory()->attachment->create();
        update_post_meta($att, '_wp_attachment_metadata', ['filesize' => 1234]);

        $this->service->onMetadataGenerated(['filesize' => 1234], $att, 'create');
        $this->service->flush();

        $this->assertSame(1, $this->counters->getGlobalCount());
        $this->assertSame(1234, $this->counters->getGlobalSize());
    }

    /**
     * Test regenerations (context !== 'create') do NOT bump counters
     *
     * @return void
     */
    public function test_regeneration_does_not_increment(): void
    {
        $att = $this->factory()->attachment->create();

        $this->service->onMetadataGenerated(['filesize' => 999], $att, 'update');
        $this->service->flush();

        $this->assertSame(0, $this->counters->getGlobalCount());
        $this->assertSame(0, $this->counters->getGlobalSize());
    }

    /**
     * Test deleting an unassigned attachment subtracts only from Root
     *
     * @return void
     */
    public function test_delete_unassigned_attachment_decrements_only_root(): void
    {
        update_option(FolderCounterService::OPT_ROOT_SIZE, 5000);
        update_option(FolderCounterService::OPT_ROOT_COUNT, 3);

        $att = $this->factory()->attachment->create();
        update_post_meta($att, '_wp_attachment_metadata', ['filesize' => 500]);

        $this->service->onAttachmentDelete($att);
        $this->service->flush();

        $this->assertSame(2, $this->counters->getGlobalCount());
        $this->assertSame(4500, $this->counters->getGlobalSize());
    }

    /**
     * Test deleting an assigned attachment subtracts from chain AND Root
     *
     * @return void
     */
    public function test_delete_assigned_attachment_decrements_chain_and_root(): void
    {
        $parent = $this->repository->create('Parent');
        $child  = $this->repository->create('Child', $parent->getId());

        $att = $this->factory()->attachment->create();
        update_post_meta($att, '_wp_attachment_metadata', ['filesize' => 800]);
        $this->assignments->assign($child->getId(), [$att]);

        // Pre-state: chain has the media, Root global is 1/800 (set by us
        // since lifecycle hooks don't fire in unit tests).
        update_option(FolderCounterService::OPT_ROOT_SIZE, 800);
        update_option(FolderCounterService::OPT_ROOT_COUNT, 1);

        $this->service->onAttachmentDelete($att);
        $this->service->flush();

        $this->assertSame(0, $this->counters->getGlobalCount());
        $this->assertSame(0, $this->counters->getGlobalSize());

        $this->assertSame('0', get_term_meta($child->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('0', get_term_meta($child->getId(), FolderModel::META_SIZE, true));
        $this->assertSame('0', get_term_meta($parent->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('0', get_term_meta($parent->getId(), FolderModel::META_SIZE, true));
    }

    /**
     * Test multiple additions in one request batch into a single Root update
     *
     * @return void
     */
    public function test_multiple_additions_batched(): void
    {
        $a = $this->factory()->attachment->create();
        $b = $this->factory()->attachment->create();
        $c = $this->factory()->attachment->create();

        $this->service->onMetadataGenerated(['filesize' => 100], $a, 'create');
        $this->service->onMetadataGenerated(['filesize' => 200], $b, 'create');
        $this->service->onMetadataGenerated(['filesize' => 300], $c, 'create');
        $this->service->flush();

        $this->assertSame(3, $this->counters->getGlobalCount());
        $this->assertSame(600, $this->counters->getGlobalSize());
    }
}
