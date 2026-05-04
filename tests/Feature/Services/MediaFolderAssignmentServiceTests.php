<?php

/**
 * Tests for MediaFolderAssignmentService
 *
 * Covers media ↔ folder assignment, including delta math on ancestor
 * chains and the "assign to Root" shortcut. Counter accessors live in
 * FolderCounterServiceTests.
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
use InvalidArgumentException;
use WP_UnitTestCase;

class MediaFolderAssignmentServiceTests extends WP_UnitTestCase
{
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
        $counters          = new FolderCounterService();
        $this->repository  = new FolderRepository(new FolderNameSanitizer(), $counters);
        $this->assignments = new MediaFolderAssignmentService($this->repository, $counters);
    }

    /**
     * Test assign sets the term relationship
     *
     * @return void
     */
    public function test_assign_sets_relationship(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->assignments->assign($folderId, [$attachmentId]);

        $terms = wp_get_object_terms($attachmentId, TaxonomyService::TAXONOMY_NAME);
        $this->assertCount(1, $terms);
        $this->assertSame($folderId, $terms[0]->term_id);

        $folder = $this->repository->getById($folderId);
        $this->assertSame(1, $folder->getMediaCount());
    }

    /**
     * Test assign replaces an existing folder assignment
     *
     * @return void
     */
    public function test_assign_replaces_existing_assignment(): void
    {
        $folder1Id    = $this->createTerm('Folder A');
        $folder2Id    = $this->createTerm('Folder B');
        $attachmentId = $this->factory()->attachment->create();

        $this->assignments->assign($folder1Id, [$attachmentId]);
        $this->assignments->assign($folder2Id, [$attachmentId]);

        $terms = wp_get_object_terms($attachmentId, TaxonomyService::TAXONOMY_NAME);
        $this->assertCount(1, $terms);
        $this->assertSame($folder2Id, $terms[0]->term_id);

        $folder1 = $this->repository->getById($folder1Id);
        $folder2 = $this->repository->getById($folder2Id);
        $this->assertSame(0, $folder1->getMediaCount());
        $this->assertSame(1, $folder2->getMediaCount());
    }

    /**
     * Test assign returns previous folder IDs for cache refresh
     *
     * @return void
     */
    public function test_assign_returns_previous_folder_ids(): void
    {
        $origin1Id = $this->createTerm('Origin 1');
        $origin2Id = $this->createTerm('Origin 2');
        $destId    = $this->createTerm('Dest');
        $att1      = $this->factory()->attachment->create();
        $att2      = $this->factory()->attachment->create();

        $this->assignments->assign($origin1Id, [$att1]);
        $this->assignments->assign($origin2Id, [$att2]);

        $previous = $this->assignments->assign($destId, [$att1, $att2]);

        sort($previous);
        $expected = [
            $origin1Id,
            $origin2Id,
        ];
        sort($expected);
        $this->assertSame($expected, $previous);
    }

    /**
     * Test assign returns an empty array for previously unassigned media
     *
     * @return void
     */
    public function test_assign_returns_empty_for_unassigned(): void
    {
        $folderId = $this->createTerm('Folder');
        $att      = $this->factory()->attachment->create();

        $this->assertSame([], $this->assignments->assign($folderId, [$att]));
    }

    /**
     * Test assign excludes the destination folder from returned IDs
     *
     * Reassigning a media to the same folder it already lives in must not
     * surface that folder as a "previous" ID — there's no origin chain to
     * refresh in that case.
     *
     * @return void
     */
    public function test_assign_excludes_destination_from_previous(): void
    {
        $folderId = $this->createTerm('Folder');
        $att      = $this->factory()->attachment->create();

        $this->assignments->assign($folderId, [$att]);
        $previous = $this->assignments->assign($folderId, [$att]);

        $this->assertSame([], $previous);
    }

    /**
     * Test assign handles multiple media items
     *
     * @return void
     */
    public function test_assign_handles_multiple_items(): void
    {
        $folderId      = $this->createTerm('Photos');
        $attachmentId1 = $this->factory()->attachment->create();
        $attachmentId2 = $this->factory()->attachment->create();

        $this->assignments->assign($folderId, [$attachmentId1, $attachmentId2]);

        $folder = $this->repository->getById($folderId);
        $this->assertSame(2, $folder->getMediaCount());
    }

    /**
     * Test assign throws for a non-existent folder
     *
     * @return void
     */
    public function test_assign_throws_for_missing_folder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->assignments->assign(999999, [1]);
    }

    /**
     * Test assign throws on non-attachment post IDs
     *
     * @return void
     */
    public function test_assign_throws_on_non_attachment_ids(): void
    {
        $folderId = $this->createTerm('Photos');
        $pageId   = $this->factory()->post->create(['post_type' => 'page']);

        $this->expectException(InvalidArgumentException::class);

        $this->assignments->assign($folderId, [$pageId]);
    }

    /**
     * Test assign throws when mixing valid and invalid IDs
     *
     * @return void
     */
    public function test_assign_throws_on_mixed_valid_and_invalid_ids(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();
        $pageId       = $this->factory()->post->create(['post_type' => 'page']);

        $this->expectException(InvalidArgumentException::class);

        $this->assignments->assign($folderId, [$attachmentId, $pageId]);
    }

    /**
     * Test assign accepts an empty array without error
     *
     * @return void
     */
    public function test_assign_accepts_empty_array(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->assignments->assign($folderId, [$attachmentId]);
        $this->assignments->assign($folderId, []);

        $folder = $this->repository->getById($folderId);
        $this->assertSame(1, $folder->getMediaCount());
    }

    /**
     * Test remove strips the term relationship
     *
     * @return void
     */
    public function test_remove_removes_relationship(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->assignments->assign($folderId, [$attachmentId]);
        $this->assignments->remove($folderId, [$attachmentId]);

        $folderAfter = $this->repository->getById($folderId);
        $this->assertSame(0, $folderAfter->getMediaCount());
    }

    /**
     * Test remove throws for a non-existent folder
     *
     * @return void
     */
    public function test_remove_throws_for_missing_folder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->assignments->remove(999999, [1]);
    }

    /**
     * Test remove on Root throws
     *
     * @return void
     */
    public function test_remove_from_root_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->assignments->remove(0, [1]);
    }

    /**
     * Test remove with partial selection updates count correctly
     *
     * @return void
     */
    public function test_remove_partial_updates_count(): void
    {
        $folderId = $this->createTerm('Photos');
        $media1   = $this->factory()->attachment->create();
        $media2   = $this->factory()->attachment->create();
        $media3   = $this->factory()->attachment->create();

        $this->assignments->assign($folderId, [$media1, $media2, $media3]);

        $this->assignments->remove($folderId, [$media2]);

        $folderAfter = $this->repository->getById($folderId);
        $this->assertSame(2, $folderAfter->getMediaCount());
    }

    /**
     * Test assign(0) acts as "unassign" — strips every folder term
     *
     * @return void
     */
    public function test_assign_to_root_unassigns(): void
    {
        $folder1 = $this->createTerm('A');
        $folder2 = $this->createTerm('B');
        $att1    = $this->factory()->attachment->create();
        $att2    = $this->factory()->attachment->create();

        $this->assignments->assign($folder1, [$att1]);
        $this->assignments->assign($folder2, [$att2]);

        $previous = $this->assignments->assign(0, [$att1, $att2]);

        // Both attachments should now have zero terms.
        $this->assertSame(
            [],
            wp_get_object_terms($att1, TaxonomyService::TAXONOMY_NAME, ['fields' => 'ids'])
        );
        $this->assertSame(
            [],
            wp_get_object_terms($att2, TaxonomyService::TAXONOMY_NAME, ['fields' => 'ids'])
        );

        sort($previous);
        $expected = [
            $folder1,
            $folder2,
        ];
        sort($expected);
        $this->assertSame($expected, $previous);
    }

    /**
     * Test assign bumps the destination chain's counter meta
     *
     * @return void
     */
    public function test_assign_increments_destination_chain(): void
    {
        $parent = $this->repository->create('Parent');
        $child  = $this->repository->create('Child', $parent->getId());
        $att    = $this->createAttachmentWithSize(1500);

        $this->assignments->assign($child->getId(), [$att]);

        $this->assertSame('1', get_term_meta($child->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('1500', get_term_meta($child->getId(), FolderModel::META_SIZE, true));
        $this->assertSame('1', get_term_meta($parent->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('1500', get_term_meta($parent->getId(), FolderModel::META_SIZE, true));
    }

    /**
     * Test assign from one folder to another shifts the deltas correctly
     *
     * @return void
     */
    public function test_assign_moves_deltas_between_chains(): void
    {
        $origin = $this->repository->create('Origin');
        $dest   = $this->repository->create('Dest');
        $att    = $this->createAttachmentWithSize(800);

        $this->assignments->assign($origin->getId(), [$att]);
        $this->assignments->assign($dest->getId(), [$att]);

        $this->assertSame('0', get_term_meta($origin->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('0', get_term_meta($origin->getId(), FolderModel::META_SIZE, true));
        $this->assertSame('1', get_term_meta($dest->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('800', get_term_meta($dest->getId(), FolderModel::META_SIZE, true));
    }

    /**
     * Test re-assigning the same media to the same folder is a no-op delta-wise
     *
     * @return void
     */
    public function test_assign_is_idempotent_on_same_folder(): void
    {
        $folder = $this->repository->create('Folder');
        $att    = $this->createAttachmentWithSize(500);

        $this->assignments->assign($folder->getId(), [$att]);
        $this->assignments->assign($folder->getId(), [$att]);

        $this->assertSame('1', get_term_meta($folder->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('500', get_term_meta($folder->getId(), FolderModel::META_SIZE, true));
    }

    /**
     * Test remove decrements the chain's counter meta
     *
     * @return void
     */
    public function test_remove_decrements_chain(): void
    {
        $parent = $this->repository->create('Parent');
        $child  = $this->repository->create('Child', $parent->getId());
        $att    = $this->createAttachmentWithSize(700);

        $this->assignments->assign($child->getId(), [$att]);
        $this->assignments->remove($child->getId(), [$att]);

        $this->assertSame('0', get_term_meta($child->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('0', get_term_meta($child->getId(), FolderModel::META_SIZE, true));
        $this->assertSame('0', get_term_meta($parent->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('0', get_term_meta($parent->getId(), FolderModel::META_SIZE, true));
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
