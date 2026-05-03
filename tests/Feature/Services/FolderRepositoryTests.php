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
use FoldSnap\Tests\TestsUtils\FolderRepositoryTestHelper;
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
     * Test repository starts empty (no folders exist)
     *
     * @return void
     */
    public function test_no_folders_exist_initially(): void
    {
        $this->assertSame([], FolderRepositoryTestHelper::getAll());
    }

    /**
     * Test create persists folders so they show up in the term store
     *
     * @return void
     */
    public function test_create_persists_folders(): void
    {
        $this->repository->create('Photos');
        $this->repository->create('Documents');

        $result = FolderRepositoryTestHelper::getAll();

        $this->assertCount(2, $result);
        $this->assertInstanceOf(FolderModel::class, $result[0]);
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
        $this->assertNull($this->repository->getById(999999));
    }

    /**
     * Test getByIds returns models for multiple terms
     *
     * @return void
     */
    public function test_get_by_ids_returns_multiple_models(): void
    {
        $a = $this->createTerm('A');
        $b = $this->createTerm('B');
        $c = $this->createTerm('C');

        $result = $this->repository->getByIds([$a, $c]);

        $this->assertCount(2, $result);
        $ids = array_map(static fn (FolderModel $m): int => $m->getId(), $result);
        $this->assertContains($a, $ids);
        $this->assertContains($c, $ids);
        $this->assertNotContains($b, $ids);
    }

    /**
     * Test getByIds returns empty when input is empty
     *
     * @return void
     */
    public function test_get_by_ids_empty_input(): void
    {
        $this->assertSame([], $this->repository->getByIds([]));
    }

    /**
     * Test getByIds skips invalid (non-positive) IDs
     *
     * @return void
     */
    public function test_get_by_ids_skips_invalid(): void
    {
        $a = $this->createTerm('A');

        $result = $this->repository->getByIds([$a, 0, -5]);

        $this->assertCount(1, $result);
        $this->assertSame($a, $result[0]->getId());
    }

    /**
     * Test getByParent returns direct children sorted by position then name
     *
     * @return void
     */
    public function test_get_by_parent_sorts_children(): void
    {
        $parent = $this->createTerm('Parent');
        $b      = $this->createTerm('Bravo', ['parent' => $parent]);
        $a      = $this->createTerm('Alpha', ['parent' => $parent]);
        $c      = $this->createTerm('Charlie', ['parent' => $parent]);

        update_term_meta($a, FolderModel::META_POSITION, '2');
        update_term_meta($b, FolderModel::META_POSITION, '2');
        update_term_meta($c, FolderModel::META_POSITION, '1');

        $children = $this->repository->getByParent($parent);

        $this->assertCount(3, $children);
        // Position 1 first.
        $this->assertSame('Charlie', $children[0]->getName());
        // Same position: alphabetical (Alpha before Bravo).
        $this->assertSame('Alpha', $children[1]->getName());
        $this->assertSame('Bravo', $children[2]->getName());
    }

    /**
     * Test getByParent returns root-level folders for parent 0
     *
     * @return void
     */
    public function test_get_by_parent_returns_roots(): void
    {
        $rootA = $this->createTerm('Root A');
        $rootB = $this->createTerm('Root B');
        $this->createTerm('Nested', ['parent' => $rootA]);

        $children = $this->repository->getByParent(0);

        $this->assertCount(2, $children);
        $names = array_map(static fn (FolderModel $m): string => $m->getName(), $children);
        $this->assertContains('Root A', $names);
        $this->assertContains('Root B', $names);
    }

    /**
     * Test getByParent returns empty array for parent with no children
     *
     * @return void
     */
    public function test_get_by_parent_no_children(): void
    {
        $parent = $this->createTerm('Lonely');

        $this->assertSame([], $this->repository->getByParent($parent));
    }

    /**
     * Test getByParents returns map keyed by parent ID
     *
     * @return void
     */
    public function test_get_by_parents_returns_map(): void
    {
        $rootA  = $this->createTerm('A');
        $rootB  = $this->createTerm('B');
        $childA = $this->createTerm('A1', ['parent' => $rootA]);
        $childB = $this->createTerm('B1', ['parent' => $rootB]);

        $result = $this->repository->getByParents([$rootA, $rootB]);

        $this->assertArrayHasKey($rootA, $result);
        $this->assertArrayHasKey($rootB, $result);
        $this->assertCount(1, $result[$rootA]);
        $this->assertCount(1, $result[$rootB]);
        $this->assertSame($childA, $result[$rootA][0]->getId());
        $this->assertSame($childB, $result[$rootB][0]->getId());
    }

    /**
     * Test getByParents includes empty arrays for parents without children
     *
     * @return void
     */
    public function test_get_by_parents_includes_empty_results(): void
    {
        $rootA = $this->createTerm('A');
        $rootB = $this->createTerm('B');

        $result = $this->repository->getByParents([$rootA, $rootB]);

        $this->assertSame([], $result[$rootA]);
        $this->assertSame([], $result[$rootB]);
    }

    /**
     * Test search returns matching folders paginated
     *
     * @return void
     */
    public function test_search_returns_matching_folders(): void
    {
        $this->repository->create('Photos 2024');
        $this->repository->create('Photos 2025');
        $this->repository->create('Documents');

        $result = $this->repository->search('Photos', 1, 50);

        $this->assertSame(2, $result['total']);
        $this->assertCount(2, $result['folders']);
        $this->assertSame(1, $result['total_pages']);
    }

    /**
     * Test search paginates correctly
     *
     * @return void
     */
    public function test_search_paginates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repository->create('Photo ' . $i);
        }

        $page1 = $this->repository->search('Photo', 1, 2);
        $page2 = $this->repository->search('Photo', 2, 2);
        $page3 = $this->repository->search('Photo', 3, 2);

        $this->assertSame(5, $page1['total']);
        $this->assertSame(3, $page1['total_pages']);
        $this->assertCount(2, $page1['folders']);
        $this->assertCount(2, $page2['folders']);
        $this->assertCount(1, $page3['folders']);
    }

    /**
     * Test search returns empty result for empty query
     *
     * @return void
     */
    public function test_search_empty_query(): void
    {
        $this->repository->create('Photos');

        $result = $this->repository->search('', 1, 50);

        $this->assertSame(0, $result['total']);
        $this->assertSame([], $result['folders']);
        $this->assertSame(0, $result['total_pages']);
    }

    /**
     * Test search is case-insensitive substring match
     *
     * @return void
     */
    public function test_search_case_insensitive_substring(): void
    {
        $this->repository->create('My Photos');
        $this->repository->create('Photo Album');

        $result = $this->repository->search('photo', 1, 50);

        $this->assertSame(2, $result['total']);
    }

    /**
     * Test getPath returns ancestor chain root → target
     *
     * @return void
     */
    public function test_get_path_returns_ancestor_chain(): void
    {
        $rootId       = $this->createTerm('Root');
        $childId      = $this->createTerm('Child', ['parent' => $rootId]);
        $grandchildId = $this->createTerm('GC', ['parent' => $childId]);

        $path = $this->repository->getPath($grandchildId);

        $this->assertCount(3, $path);
        $this->assertSame($rootId, $path[0]->getId());
        $this->assertSame($childId, $path[1]->getId());
        $this->assertSame($grandchildId, $path[2]->getId());
    }

    /**
     * Test getPath returns single-element list for root folder
     *
     * @return void
     */
    public function test_get_path_for_root_folder(): void
    {
        $rootId = $this->createTerm('Root');

        $path = $this->repository->getPath($rootId);

        $this->assertCount(1, $path);
        $this->assertSame($rootId, $path[0]->getId());
    }

    /**
     * Test getPath returns empty array for non-existent folder
     *
     * @return void
     */
    public function test_get_path_returns_empty_for_missing_folder(): void
    {
        $this->assertSame([], $this->repository->getPath(999999));
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
     * Test assignMedia returns previous folder IDs for cache refresh
     *
     * @return void
     */
    public function test_assign_media_returns_previous_folder_ids(): void
    {
        $origin1Id = $this->createTerm('Origin 1');
        $origin2Id = $this->createTerm('Origin 2');
        $destId    = $this->createTerm('Dest');
        $att1      = $this->factory()->attachment->create();
        $att2      = $this->factory()->attachment->create();

        $this->repository->assignMedia($origin1Id, [$att1]);
        $this->repository->assignMedia($origin2Id, [$att2]);

        $previous = $this->repository->assignMedia($destId, [$att1, $att2]);

        sort($previous);
        $expected = [
            $origin1Id,
            $origin2Id,
        ];
        sort($expected);
        $this->assertSame($expected, $previous);
    }

    /**
     * Test assignMedia returns empty array for unassigned media
     *
     * @return void
     */
    public function test_assign_media_returns_empty_for_unassigned(): void
    {
        $folderId = $this->createTerm('Folder');
        $att      = $this->factory()->attachment->create();

        $this->assertSame([], $this->repository->assignMedia($folderId, [$att]));
    }

    /**
     * Test assignMedia excludes destination folder from returned IDs
     *
     * Reassigning a media to the same folder it already lives in must not
     * surface that folder as a "previous" ID — there's no origin chain to
     * refresh in that case.
     *
     * @return void
     */
    public function test_assign_media_excludes_destination_from_previous(): void
    {
        $folderId = $this->createTerm('Folder');
        $att      = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$att]);
        $previous = $this->repository->assignMedia($folderId, [$att]);

        $this->assertSame([], $previous);
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
        $this->repository->removeMedia($folderId, [$attachmentId]);

        $folderAfter = $this->repository->getById($folderId);
        $this->assertSame(0, $folderAfter->getMediaCount());
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

        $this->assertSame(2, $this->repository->getRootMediaCount());
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
     * Test getRootTotalSize returns size of unassigned media
     *
     * @return void
     */
    public function test_get_root_total_size_returns_unassigned_size(): void
    {
        $this->createAttachmentWithSize(1500);
        $this->createAttachmentWithSize(2500);

        $this->assertSame(4000, $this->repository->getRootTotalSize());
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

        $this->assertSame(1000, $this->repository->getRootTotalSize());
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
     * @return void
     */
    public function test_remove_media_partial_updates_count(): void
    {
        $folderId = $this->createTerm('Photos');
        $media1   = $this->factory()->attachment->create();
        $media2   = $this->factory()->attachment->create();
        $media3   = $this->factory()->attachment->create();

        $this->repository->assignMedia($folderId, [$media1, $media2, $media3]);

        $this->repository->removeMedia($folderId, [$media2]);

        $folderAfter = $this->repository->getById($folderId);
        $this->assertSame(2, $folderAfter->getMediaCount());
    }

    /**
     * Test deleting a parent folder orphans children to root and releases its direct media
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

        $this->repository->assignMedia($parentId, [$media1, $media2]);
        $this->repository->assignMedia($childId, [$media3, $media4]);

        $this->repository->delete($parentId);

        $this->assertNull($this->repository->getById($parentId));

        $child = $this->repository->getById($childId);
        $this->assertNotNull($child);
        $this->assertSame(2, $child->getMediaCount());
        $this->assertSame(0, $child->getParentId());

        // The 2 media that were directly on the deleted parent are now unassigned.
        $this->assertSame(2, $this->repository->getRootMediaCount());
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
}
