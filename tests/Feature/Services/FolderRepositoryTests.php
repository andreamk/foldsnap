<?php

/**
 * Tests for FolderRepository class
 *
 * @package FoldSnap\Tests\Feature\Services
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Services;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\TaxonomyService;
use InvalidArgumentException;
use WP_UnitTestCase;

class FolderRepositoryTests extends WP_UnitTestCase
{
    private FolderRepository $repository;

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
    }

    /**
     * Test getAll returns empty array when no folders exist
     *
     * @return void
     */
    public function test_get_all_returns_empty_when_no_folders(): void
    {
        $result = $this->repository->getAll();

        $this->assertSame([], $result);
    }

    /**
     * Test getAll returns all folders as flat list
     *
     * @return void
     */
    public function test_get_all_returns_all_folders(): void
    {
        $this->createTerm('Photos');
        $this->createTerm('Documents');

        $result = $this->repository->getAll();

        $this->assertCount(2, $result);
        $this->assertInstanceOf(FolderModel::class, $result[0]);
        $this->assertInstanceOf(FolderModel::class, $result[1]);
    }

    /**
     * Test getTree returns nested structure
     *
     * @return void
     */
    public function test_get_tree_returns_nested_structure(): void
    {
        $parentId = $this->createTerm('Parent');
        $this->createTerm('Child', ['parent' => $parentId]);

        $tree = $this->repository->getTree();

        $this->assertCount(1, $tree);
        $this->assertSame('Parent', $tree[0]->getName());
        $this->assertCount(1, $tree[0]->getChildren());
        $this->assertSame('Child', $tree[0]->getChildren()[0]->getName());
    }

    /**
     * Test getTree sorts by position
     *
     * @return void
     */
    public function test_get_tree_sorts_by_position(): void
    {
        $id1 = $this->createTerm('Second');
        $id2 = $this->createTerm('First');

        update_term_meta($id1, FolderModel::META_POSITION, '2');
        update_term_meta($id2, FolderModel::META_POSITION, '1');

        $tree = $this->repository->getTree();

        $this->assertCount(2, $tree);
        $this->assertSame('First', $tree[0]->getName());
        $this->assertSame('Second', $tree[1]->getName());
    }

    /**
     * Test getTree handles multi-level nesting
     *
     * @return void
     */
    public function test_get_tree_handles_multi_level_nesting(): void
    {
        $rootId  = $this->createTerm('Root');
        $childId = $this->createTerm('Child', ['parent' => $rootId]);
        $this->createTerm('Grandchild', ['parent' => $childId]);

        $tree = $this->repository->getTree();

        $this->assertCount(1, $tree);
        $this->assertCount(1, $tree[0]->getChildren());
        $this->assertCount(1, $tree[0]->getChildren()[0]->getChildren());
        $this->assertSame('Grandchild', $tree[0]->getChildren()[0]->getChildren()[0]->getName());
    }

    /**
     * Test getById returns folder model
     *
     * @return void
     */
    public function test_get_by_id_returns_folder(): void
    {
        $termId = $this->createTerm('Photos');

        $result = $this->repository->getById($termId);

        $this->assertInstanceOf(FolderModel::class, $result);
        $this->assertSame($termId, $result->getId());
        $this->assertSame('Photos', $result->getName());
    }

    /**
     * Test getById returns null for non-existent term
     *
     * @return void
     */
    public function test_get_by_id_returns_null_for_missing_term(): void
    {
        $result = $this->repository->getById(999999);

        $this->assertNull($result);
    }

    /**
     * Test create inserts folder and returns model
     *
     * @return void
     */
    public function test_create_inserts_folder(): void
    {
        $model = $this->repository->create('Photos');

        $this->assertInstanceOf(FolderModel::class, $model);
        $this->assertSame('Photos', $model->getName());
        $this->assertSame(0, $model->getParentId());
        $this->assertSame('', $model->getColor());
        $this->assertSame(0, $model->getPosition());
    }

    /**
     * Test create with all optional parameters
     *
     * @return void
     */
    public function test_create_with_all_options(): void
    {
        $parentId = $this->createTerm('Parent');

        $model = $this->repository->create('Child', $parentId, '#ff0000', 5);

        $this->assertSame('Child', $model->getName());
        $this->assertSame($parentId, $model->getParentId());
        $this->assertSame('#ff0000', $model->getColor());
        $this->assertSame(5, $model->getPosition());
    }

    /**
     * Test create auto-renames on duplicate name under same parent
     *
     * @return void
     */
    public function test_create_auto_renames_on_duplicate_name(): void
    {
        $first  = $this->repository->create('Photos');
        $second = $this->repository->create('Photos');

        $this->assertSame('Photos', $first->getName());
        $this->assertSame('Photos (2)', $second->getName());
    }

    /**
     * Test create auto-renames increments correctly
     *
     * @return void
     */
    public function test_create_auto_renames_increments(): void
    {
        $this->repository->create('Photos');
        $this->repository->create('Photos');
        $third = $this->repository->create('Photos');

        $this->assertSame('Photos (3)', $third->getName());
    }

    /**
     * Test create allows duplicate names under different parents
     *
     * @return void
     */
    public function test_create_allows_duplicate_name_under_different_parent(): void
    {
        $parent1 = $this->repository->create('Parent A');
        $parent2 = $this->repository->create('Parent B');

        $child1 = $this->repository->create('Photos', $parent1->getId());
        $child2 = $this->repository->create('Photos', $parent2->getId());

        $this->assertSame('Photos', $child1->getName());
        $this->assertSame('Photos', $child2->getName());
    }

    /**
     * Test create sanitizes dangerous leading characters
     *
     * @return void
     */
    public function test_create_sanitizes_dangerous_leading_chars(): void
    {
        $model = $this->repository->create('=SUM(A1)');

        $this->assertSame('SUM(A1)', $model->getName());
    }

    /**
     * Test create throws on empty name after sanitization
     *
     * @return void
     */
    public function test_create_throws_on_empty_name(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->create('');
    }

    /**
     * Test create truncates long names to 200 characters
     *
     * @return void
     */
    public function test_create_truncates_long_name(): void
    {
        $longName = str_repeat('a', 300);

        $model = $this->repository->create($longName);

        $this->assertSame(200, mb_strlen($model->getName()));
    }

    /**
     * Test update changes folder name
     *
     * @return void
     */
    public function test_update_changes_name(): void
    {
        $model = $this->repository->create('Old Name');

        $updated = $this->repository->update($model->getId(), 'New Name');

        $this->assertSame('New Name', $updated->getName());
    }

    /**
     * Test update changes parent
     *
     * @return void
     */
    public function test_update_changes_parent(): void
    {
        $parentId = $this->createTerm('New Parent');
        $model    = $this->repository->create('Child');

        $updated = $this->repository->update($model->getId(), '', $parentId);

        $this->assertSame($parentId, $updated->getParentId());
    }

    /**
     * Test moving a subtree updates recursive media counts
     *
     * Setup: A(media1,media2) > B(media3,media4), C(media5)
     * Move B under C: A(media1,media2), C(media5) > B(media3,media4)
     * Verify: A total=2, C total=3, B total=2
     *
     * @return void
     */
    public function test_update_parent_updates_recursive_media_counts(): void
    {
        $folderA = $this->createTerm('A');
        $folderB = $this->createTerm('B', ['parent' => $folderA]);
        $folderC = $this->createTerm('C');

        $media1 = $this->factory()->attachment->create();
        $media2 = $this->factory()->attachment->create();
        $media3 = $this->factory()->attachment->create();
        $media4 = $this->factory()->attachment->create();
        $media5 = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderA, [$media1, $media2]);
        $this->repository->assignMedia($folderB, [$media3, $media4]);
        $this->repository->assignMedia($folderC, [$media5]);

        // Before move: A total=4 (2 direct + B's 2), B total=2, C total=1
        $treeBefore = $this->repository->getTree();
        $aBefore    = $this->findInTree($treeBefore, $folderA);
        $cBefore    = $this->findInTree($treeBefore, $folderC);

        $this->assertSame(2, $aBefore->getMediaCount());
        $this->assertSame(4, $aBefore->getTotalMediaCount());
        $this->assertSame(1, $cBefore->getMediaCount());
        $this->assertSame(1, $cBefore->getTotalMediaCount());

        // Move B under C
        $this->repository->update($folderB, '', $folderC);

        // After move: A total=2, C total=3 (1 direct + B's 2), B total=2
        $treeAfter = $this->repository->getTree();
        $aAfter    = $this->findInTree($treeAfter, $folderA);
        $cAfter    = $this->findInTree($treeAfter, $folderC);
        $bAfter    = $this->findInTree($cAfter->getChildren(), $folderB);

        $this->assertSame(2, $aAfter->getMediaCount());
        $this->assertSame(2, $aAfter->getTotalMediaCount());
        $this->assertSame(0, count($aAfter->getChildren()));

        $this->assertSame(1, $cAfter->getMediaCount());
        $this->assertSame(3, $cAfter->getTotalMediaCount());

        $this->assertNotNull($bAfter);
        $this->assertSame(2, $bAfter->getMediaCount());
        $this->assertSame(2, $bAfter->getTotalMediaCount());
    }

    /**
     * Test update changes color
     *
     * @return void
     */
    public function test_update_changes_color(): void
    {
        $model = $this->repository->create('Folder');

        $updated = $this->repository->update($model->getId(), '', -1, '#00ff00');

        $this->assertSame('#00ff00', $updated->getColor());
    }

    /**
     * Test update changes position
     *
     * @return void
     */
    public function test_update_changes_position(): void
    {
        $model = $this->repository->create('Folder');

        $updated = $this->repository->update($model->getId(), '', -1, '', 10);

        $this->assertSame(10, $updated->getPosition());
    }

    /**
     * Test update with sentinel values leaves fields unchanged
     *
     * @return void
     */
    public function test_update_sentinel_values_preserve_fields(): void
    {
        $model = $this->repository->create('Original', 0, '#ff0000', 5);

        $updated = $this->repository->update($model->getId());

        $this->assertSame('Original', $updated->getName());
        $this->assertSame(0, $updated->getParentId());
        $this->assertSame('#ff0000', $updated->getColor());
        $this->assertSame(5, $updated->getPosition());
    }

    /**
     * Test update throws for non-existent term
     *
     * @return void
     */
    public function test_update_throws_for_missing_term(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->update(999999, 'Name');
    }

    /**
     * Test delete removes folder
     *
     * @return void
     */
    public function test_delete_removes_folder(): void
    {
        $model = $this->repository->create('To Delete');

        $result = $this->repository->delete($model->getId());

        $this->assertTrue($result);
        $this->assertNull($this->repository->getById($model->getId()));
    }

    /**
     * Test delete throws for non-existent term
     *
     * @return void
     */
    public function test_delete_throws_for_missing_term(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->delete(999999);
    }

    /**
     * Test assignMedia sets term relationship
     *
     * @return void
     */
    public function test_assign_media_sets_relationship(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$attachmentId]);

        $terms = wp_get_object_terms($attachmentId, TaxonomyService::TAXONOMY_NAME);
        $this->assertCount(1, $terms);
        $this->assertSame($folderId, $terms[0]->term_id);

        $folder = $this->repository->getById($folderId);
        $this->assertSame(1, $folder->getMediaCount());
    }

    /**
     * Test assignMedia replaces existing folder assignment
     *
     * @return void
     */
    public function test_assign_media_replaces_existing_assignment(): void
    {
        $folder1Id    = $this->createTerm('Folder A');
        $folder2Id    = $this->createTerm('Folder B');
        $attachmentId = $this->factory()->attachment->create();

        $this->repository->assignMedia($folder1Id, [$attachmentId]);
        $this->repository->assignMedia($folder2Id, [$attachmentId]);

        $terms = wp_get_object_terms($attachmentId, TaxonomyService::TAXONOMY_NAME);
        $this->assertCount(1, $terms);
        $this->assertSame($folder2Id, $terms[0]->term_id);

        $folder1 = $this->repository->getById($folder1Id);
        $folder2 = $this->repository->getById($folder2Id);
        $this->assertSame(0, $folder1->getMediaCount());
        $this->assertSame(1, $folder2->getMediaCount());
    }

    /**
     * Test assignMedia handles multiple media items
     *
     * @return void
     */
    public function test_assign_media_handles_multiple_items(): void
    {
        $folderId      = $this->createTerm('Photos');
        $attachmentId1 = $this->factory()->attachment->create();
        $attachmentId2 = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$attachmentId1, $attachmentId2]);

        $terms1 = wp_get_object_terms($attachmentId1, TaxonomyService::TAXONOMY_NAME);
        $terms2 = wp_get_object_terms($attachmentId2, TaxonomyService::TAXONOMY_NAME);

        $this->assertCount(1, $terms1);
        $this->assertSame($folderId, $terms1[0]->term_id);
        $this->assertCount(1, $terms2);
        $this->assertSame($folderId, $terms2[0]->term_id);

        $folder = $this->repository->getById($folderId);
        $this->assertSame(2, $folder->getMediaCount());
    }

    /**
     * Test assignMedia throws for non-existent folder
     *
     * @return void
     */
    public function test_assign_media_throws_for_missing_folder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->assignMedia(999999, [1]);
    }

    /**
     * Test removeMedia removes term relationship
     *
     * @return void
     */
    public function test_remove_media_removes_relationship(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$attachmentId]);

        $folderAfterAssign = $this->repository->getById($folderId);
        $this->assertSame(1, $folderAfterAssign->getMediaCount());

        $this->repository->removeMedia($folderId, [$attachmentId]);

        $terms = wp_get_object_terms($attachmentId, TaxonomyService::TAXONOMY_NAME);
        $this->assertCount(0, $terms);

        $folderAfterRemove = $this->repository->getById($folderId);
        $this->assertSame(0, $folderAfterRemove->getMediaCount());
    }

    /**
     * Test removeMedia throws for non-existent folder
     *
     * @return void
     */
    public function test_remove_media_throws_for_missing_folder(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->removeMedia(999999, [1]);
    }

    /**
     * Test getRootMediaCount counts unassigned media
     *
     * @return void
     */
    public function test_get_root_media_count_counts_unassigned(): void
    {
        $this->factory()->attachment->create();
        $this->factory()->attachment->create();

        $count = $this->repository->getRootMediaCount();

        $this->assertSame(2, $count);
    }

    /**
     * Test getRootMediaCount excludes assigned media
     *
     * @return void
     */
    public function test_get_root_media_count_excludes_assigned(): void
    {
        $folderId = $this->createTerm('Photos');
        $assigned = $this->factory()->attachment->create();
        $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$assigned]);

        $this->assertSame(1, $this->repository->getRootMediaCount());

        $folder = $this->repository->getById($folderId);
        $this->assertSame(1, $folder->getMediaCount());
    }

    /**
     * Test getRootMediaCount returns zero when all media assigned
     *
     * @return void
     */
    public function test_get_root_media_count_returns_zero_when_all_assigned(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$attachmentId]);

        $this->assertSame(0, $this->repository->getRootMediaCount());

        $folder = $this->repository->getById($folderId);
        $this->assertSame(1, $folder->getMediaCount());
    }

    /**
     * Test delete releases media back to root
     *
     * @return void
     */
    public function test_delete_releases_media_to_root(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$attachmentId]);

        $folder = $this->repository->getById($folderId);
        $this->assertSame(1, $folder->getMediaCount());
        $this->assertSame(0, $this->repository->getRootMediaCount());

        $this->repository->delete($folderId);

        $this->assertSame(1, $this->repository->getRootMediaCount());
    }

    /**
     * Test update sanitizes name
     *
     * @return void
     */
    public function test_update_sanitizes_name(): void
    {
        $model = $this->repository->create('Original');

        $updated = $this->repository->update($model->getId(), '+Dangerous');

        $this->assertSame('Dangerous', $updated->getName());
    }

    /**
     * Test update auto-renames on conflict with sibling
     *
     * @return void
     */
    public function test_update_auto_renames_on_sibling_conflict(): void
    {
        $this->repository->create('Existing');
        $model = $this->repository->create('Other');

        $updated = $this->repository->update($model->getId(), 'Existing');

        $this->assertSame('Existing (2)', $updated->getName());
    }

    /**
     * Test update allows keeping the same name
     *
     * @return void
     */
    public function test_update_allows_keeping_same_name(): void
    {
        $model = $this->repository->create('Photos');

        $updated = $this->repository->update($model->getId(), 'Photos');

        $this->assertSame('Photos', $updated->getName());
    }

    /**
     * Test getTree injects direct sizes
     *
     * @return void
     */
    public function test_get_tree_injects_direct_sizes(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->createAttachmentWithSize(2048);

        $this->repository->assignMedia($folderId, [$attachmentId]);

        $tree = $this->repository->getTree();

        $this->assertCount(1, $tree);
        $this->assertSame(2048, $tree[0]->getDirectSize());
        $this->assertSame(2048, $tree[0]->getTotalSize());
    }

    /**
     * Test getTree computes recursive total size
     *
     * @return void
     */
    public function test_get_tree_computes_recursive_total_size(): void
    {
        $parentId = $this->createTerm('Parent');
        $childId  = $this->createTerm('Child', ['parent' => $parentId]);

        $attachment1 = $this->createAttachmentWithSize(1000);
        $attachment2 = $this->createAttachmentWithSize(2000);

        $this->repository->assignMedia($parentId, [$attachment1]);
        $this->repository->assignMedia($childId, [$attachment2]);

        $tree = $this->repository->getTree();

        $this->assertSame(1000, $tree[0]->getDirectSize());
        $this->assertSame(3000, $tree[0]->getTotalSize());
        $this->assertSame(2000, $tree[0]->getChildren()[0]->getDirectSize());
        $this->assertSame(2000, $tree[0]->getChildren()[0]->getTotalSize());
    }

    /**
     * Test getRootTotalSize returns size of unassigned media
     *
     * @return void
     */
    public function test_get_root_total_size_returns_unassigned_size(): void
    {
        $this->createAttachmentWithSize(1500);
        $this->createAttachmentWithSize(2500);

        $size = $this->repository->getRootTotalSize();

        $this->assertSame(4000, $size);
    }

    /**
     * Test getRootTotalSize excludes assigned media
     *
     * @return void
     */
    public function test_get_root_total_size_excludes_assigned(): void
    {
        $folderId = $this->createTerm('Photos');
        $assigned = $this->createAttachmentWithSize(3000);
        $this->createAttachmentWithSize(1000);

        $this->repository->assignMedia($folderId, [$assigned]);

        $size = $this->repository->getRootTotalSize();

        $this->assertSame(1000, $size);
    }

    /**
     * Test getRootTotalSize returns zero when no unassigned media
     *
     * @return void
     */
    public function test_get_root_total_size_returns_zero_when_all_assigned(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->createAttachmentWithSize(5000);

        $this->repository->assignMedia($folderId, [$attachmentId]);

        $size = $this->repository->getRootTotalSize();

        $this->assertSame(0, $size);
    }

    /**
     * Test create throws on invalid color
     *
     * @return void
     */
    public function test_create_throws_on_invalid_color(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->create('Folder', 0, 'not-a-color');
    }

    /**
     * Test update throws on invalid color
     *
     * @return void
     */
    public function test_update_throws_on_invalid_color(): void
    {
        $model = $this->repository->create('Folder');

        $this->expectException(InvalidArgumentException::class);

        $this->repository->update($model->getId(), '', -1, 'invalid');
    }

    /**
     * Test assignMedia throws on non-attachment post IDs
     *
     * @return void
     */
    public function test_assign_media_throws_on_non_attachment_ids(): void
    {
        $folderId = $this->createTerm('Photos');
        $pageId   = $this->factory()->post->create(['post_type' => 'page']);

        $this->expectException(InvalidArgumentException::class);

        $this->repository->assignMedia($folderId, [$pageId]);
    }

    /**
     * Test assignMedia throws when mixing valid and invalid IDs
     *
     * @return void
     */
    public function test_assign_media_throws_on_mixed_valid_and_invalid_ids(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();
        $pageId       = $this->factory()->post->create(['post_type' => 'page']);

        $this->expectException(InvalidArgumentException::class);

        $this->repository->assignMedia($folderId, [$attachmentId, $pageId]);
    }

    /**
     * Test assignMedia accepts empty array without error
     *
     * @return void
     */
    public function test_assign_media_accepts_empty_array(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$attachmentId]);
        $this->repository->assignMedia($folderId, []);

        $folder = $this->repository->getById($folderId);
        $this->assertSame(1, $folder->getMediaCount());
    }

    /**
     * Test removeMedia partial removal updates count correctly
     *
     * Assigns 3 media to a folder, removes 1, verifies count drops
     * from 3 to 2 and the remaining 2 are still assigned.
     *
     * @return void
     */
    public function test_remove_media_partial_updates_count(): void
    {
        $folderId = $this->createTerm('Photos');
        $media1   = $this->factory()->attachment->create();
        $media2   = $this->factory()->attachment->create();
        $media3   = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$media1, $media2, $media3]);

        $folder = $this->repository->getById($folderId);
        $this->assertSame(3, $folder->getMediaCount());

        $this->repository->removeMedia($folderId, [$media2]);

        $folderAfter = $this->repository->getById($folderId);
        $this->assertSame(2, $folderAfter->getMediaCount());

        $terms1 = wp_get_object_terms($media1, TaxonomyService::TAXONOMY_NAME);
        $terms3 = wp_get_object_terms($media3, TaxonomyService::TAXONOMY_NAME);
        $this->assertCount(1, $terms1);
        $this->assertCount(1, $terms3);
        $this->assertSame($folderId, $terms1[0]->term_id);
        $this->assertSame($folderId, $terms3[0]->term_id);

        $terms2 = wp_get_object_terms($media2, TaxonomyService::TAXONOMY_NAME);
        $this->assertCount(0, $terms2);
    }

    /**
     * Test deleting a parent folder orphans children to root
     *
     * Setup: Parent(media1,media2) > Child(media3,media4,media5)
     * Delete Parent → Child becomes root-level, keeps its 3 media.
     * Parent's 2 media become unassigned (root).
     *
     * @return void
     */
    public function test_delete_parent_orphans_children_and_releases_media(): void
    {
        $parentId = $this->createTerm('Parent');
        $childId  = $this->createTerm('Child', ['parent' => $parentId]);

        $media1 = $this->factory()->attachment->create();
        $media2 = $this->factory()->attachment->create();
        $media3 = $this->factory()->attachment->create();
        $media4 = $this->factory()->attachment->create();
        $media5 = $this->factory()->attachment->create();

        $this->repository->assignMedia($parentId, [$media1, $media2]);
        $this->repository->assignMedia($childId, [$media3, $media4, $media5]);

        // Before: parent total=5, child total=3, root unassigned=0
        $treeBefore = $this->repository->getTree();
        $this->assertCount(1, $treeBefore);
        $this->assertSame(5, $treeBefore[0]->getTotalMediaCount());
        $this->assertSame(0, $this->repository->getRootMediaCount());

        $this->repository->delete($parentId);

        // After: parent gone, child is root-level with 3 media, 2 media unassigned
        $this->assertNull($this->repository->getById($parentId));

        $child = $this->repository->getById($childId);
        $this->assertNotNull($child);
        $this->assertSame(3, $child->getMediaCount());
        $this->assertSame(0, $child->getParentId());

        $this->assertSame(2, $this->repository->getRootMediaCount());

        $treeAfter = $this->repository->getTree();
        $this->assertCount(1, $treeAfter);
        $this->assertSame('Child', $treeAfter[0]->getName());
        $this->assertSame(3, $treeAfter[0]->getTotalMediaCount());
    }

    /**
     * Test create throws when parent ID does not exist
     *
     * @return void
     */
    public function test_create_throws_on_nonexistent_parent(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->repository->create('Orphan', 999999);
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

    /**
     * Find a folder by ID in a tree (searches root level only)
     *
     * @param FolderModel[] $tree     Array of folder models
     * @param int           $folderId Folder ID to find
     *
     * @return FolderModel|null
     */
    private function findInTree(array $tree, int $folderId): ?FolderModel
    {
        foreach ($tree as $folder) {
            if ($folder->getId() === $folderId) {
                return $folder;
            }
        }
        return null;
    }
}
