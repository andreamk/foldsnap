<?php

/**
 * Tests for FolderTreeNavigator
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\FolderTreeNavigator;
use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class FolderTreeNavigatorTests extends WP_UnitTestCase
{
    private FolderRepository $repository;
    private FolderTreeNavigator $navigator;

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        TaxonomyService::register();
        $this->repository = new FolderRepository();
        $this->navigator  = new FolderTreeNavigator($this->repository);
    }

    /**
     * Test computeTotals on a single childless folder returns its direct values
     *
     * @return void
     */
    public function test_compute_totals_single_folder(): void
    {
        $folderId = $this->createTerm('Folder');
        $att      = $this->createAttachmentWithSize(1000);
        $this->repository->assignMedia($folderId, [$att]);

        $folder = $this->repository->getById($folderId);
        $totals = $this->navigator->computeTotals([$folder]);

        $this->assertSame(1, $totals[$folderId]['total_media_count']);
        $this->assertSame(1000, $totals[$folderId]['total_size']);
    }

    /**
     * Test computeTotals aggregates direct + descendants
     *
     * @return void
     */
    public function test_compute_totals_recursive(): void
    {
        $parentId = $this->createTerm('Parent');
        $childId  = $this->createTerm('Child', ['parent' => $parentId]);
        $grandId  = $this->createTerm('Grand', ['parent' => $childId]);

        $att1 = $this->createAttachmentWithSize(1000);
        $att2 = $this->createAttachmentWithSize(2000);
        $att3 = $this->createAttachmentWithSize(500);

        $this->repository->assignMedia($parentId, [$att1]);
        $this->repository->assignMedia($childId, [$att2]);
        $this->repository->assignMedia($grandId, [$att3]);

        $parent = $this->repository->getById($parentId);
        $totals = $this->navigator->computeTotals([$parent]);

        $this->assertSame(3, $totals[$parentId]['total_media_count']);
        $this->assertSame(3500, $totals[$parentId]['total_size']);
    }

    /**
     * Test computeTotals returns separate totals per requested folder
     *
     * @return void
     */
    public function test_compute_totals_multiple_folders(): void
    {
        $folderA = $this->createTerm('A');
        $folderB = $this->createTerm('B');
        $childA  = $this->createTerm('A1', ['parent' => $folderA]);

        $att1 = $this->createAttachmentWithSize(100);
        $att2 = $this->createAttachmentWithSize(200);
        $att3 = $this->createAttachmentWithSize(50);

        $this->repository->assignMedia($folderA, [$att1]);
        $this->repository->assignMedia($childA, [$att2]);
        $this->repository->assignMedia($folderB, [$att3]);

        $models = $this->repository->getByIds([$folderA, $folderB]);
        $totals = $this->navigator->computeTotals($models);

        $this->assertSame(2, $totals[$folderA]['total_media_count']);
        $this->assertSame(300, $totals[$folderA]['total_size']);
        $this->assertSame(1, $totals[$folderB]['total_media_count']);
        $this->assertSame(50, $totals[$folderB]['total_size']);
    }

    /**
     * Test computeTotals returns empty map for empty input
     *
     * @return void
     */
    public function test_compute_totals_empty_input(): void
    {
        $this->assertSame([], $this->navigator->computeTotals([]));
    }

    /**
     * Test hasChildren returns true for parents and false for leaves
     *
     * @return void
     */
    public function test_has_children_distinguishes_leaves(): void
    {
        $parentId = $this->createTerm('Parent');
        $leafId   = $this->createTerm('Leaf');
        $this->createTerm('Child', ['parent' => $parentId]);

        $result = $this->navigator->hasChildren([$parentId, $leafId]);

        $this->assertTrue($result[$parentId]);
        $this->assertFalse($result[$leafId]);
    }

    /**
     * Test resolvePath returns ordered chain Root → target
     *
     * @return void
     */
    public function test_resolve_path_full_chain(): void
    {
        $topId   = $this->createTerm('Top');
        $childId = $this->createTerm('Child', ['parent' => $topId]);
        $grandId = $this->createTerm('Grand', ['parent' => $childId]);

        $path = $this->navigator->resolvePath($grandId);

        $this->assertCount(4, $path);
        $this->assertTrue($path[0]->isRoot());
        $this->assertSame($topId, $path[1]->getId());
        $this->assertSame($childId, $path[2]->getId());
        $this->assertSame($grandId, $path[3]->getId());
    }

    /**
     * Test resolvePath returns empty for non-existent folder
     *
     * @return void
     */
    public function test_resolve_path_missing_folder(): void
    {
        $this->assertSame([], $this->navigator->resolvePath(999999));
    }

    /**
     * Test resolvePath(0) returns just the Root folder
     *
     * @return void
     */
    public function test_resolve_path_for_root(): void
    {
        $path = $this->navigator->resolvePath(0);

        $this->assertCount(1, $path);
        $this->assertTrue($path[0]->isRoot());
    }

    /**
     * Test resolvePath returns empty for invalid (negative) folder ID
     *
     * @return void
     */
    public function test_resolve_path_invalid_id(): void
    {
        $this->assertSame([], $this->navigator->resolvePath(-1));
    }

    /**
     * Create a taxonomy term and return its ID
     *
     * @param string               $name Term name
     * @param array<string, mixed> $args Optional args
     *
     * @return int
     */
    private function createTerm(string $name, array $args = []): int
    {
        $result = wp_insert_term($name, TaxonomyService::TAXONOMY_NAME, $args);
        return (int) $result['term_id'];
    }

    /**
     * Create an attachment with filesize meta
     *
     * @param int $fileSize Size in bytes
     *
     * @return int
     */
    private function createAttachmentWithSize(int $fileSize): int
    {
        $attachmentId = $this->factory()->attachment->create();
        update_post_meta($attachmentId, '_wp_attachment_metadata', ['filesize' => $fileSize]);
        return $attachmentId;
    }

    /**
     * Test computeTotals on the virtual Root sums every attachment globally
     *
     * @return void
     */
    public function test_compute_totals_for_root_uses_global_aggregates(): void
    {
        $folder = $this->createTerm('Photos');
        $att1   = $this->createAttachmentWithSize(100); // assigned
        $att2   = $this->createAttachmentWithSize(250); // unassigned
        $att3   = $this->createAttachmentWithSize(700); // unassigned
        $this->repository->assignMedia($folder, [$att1]);

        $root = $this->repository->getById(FolderModel::ROOT_ID);
        $this->assertNotNull($root);
        $totals = $this->navigator->computeTotals([$root]);

        $this->assertArrayHasKey(0, $totals);
        $this->assertSame(3, $totals[0]['total_media_count']);
        $this->assertSame(1050, $totals[0]['total_size']);
        // Silence unused-var warnings.
        $this->assertNotSame($att2, $att3);
    }
}
