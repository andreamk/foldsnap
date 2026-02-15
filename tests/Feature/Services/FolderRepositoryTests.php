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
        $this->repository->removeMedia($folderId, [$attachmentId]);

        $terms = wp_get_object_terms($attachmentId, TaxonomyService::TAXONOMY_NAME);
        $this->assertCount(0, $terms);
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

        $count = $this->repository->getRootMediaCount();

        $this->assertSame(1, $count);
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

        $count = $this->repository->getRootMediaCount();

        $this->assertSame(0, $count);
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
