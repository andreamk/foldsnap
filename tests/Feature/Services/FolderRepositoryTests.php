<?php

/**
 * Tests for FolderRepository class
 *
 * Covers folder CRUD only. Media-folder assignment lives in
 * MediaFolderAssignmentServiceTests; counter math lives in
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
use FoldSnap\Tests\TestsUtils\FolderRepositoryTestHelper;
use InvalidArgumentException;
use WP_UnitTestCase;

class FolderRepositoryTests extends WP_UnitTestCase
{
    private FolderRepository $repository;
    private MediaFolderAssignmentService $assignments;
    private FolderCounterService $counters;

    /**
     * Set up test environment
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
     * Test getByIds skips negative IDs but accepts 0 as virtual Root
     *
     * @return void
     */
    public function test_get_by_ids_skips_negative_and_includes_root(): void
    {
        $a = $this->createTerm('A');

        $result = $this->repository->getByIds([$a, 0, -5]);

        // 0 = Root, -5 = invalid → 2 results: Root + A.
        $this->assertCount(2, $result);
        $ids = array_map(static fn ($m): int => $m->getId(), $result);
        $this->assertContains(0, $ids);
        $this->assertContains($a, $ids);
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
     * Test getPath returns ancestor chain Root → target
     *
     * @return void
     */
    public function test_get_path_returns_ancestor_chain(): void
    {
        $topId        = $this->createTerm('Top');
        $childId      = $this->createTerm('Child', ['parent' => $topId]);
        $grandchildId = $this->createTerm('GC', ['parent' => $childId]);

        $path = $this->repository->getPath($grandchildId);

        // Root is always the first entry, before the user-created folders.
        $this->assertCount(4, $path);
        $this->assertTrue($path[0]->isRoot());
        $this->assertSame($topId, $path[1]->getId());
        $this->assertSame($childId, $path[2]->getId());
        $this->assertSame($grandchildId, $path[3]->getId());
    }

    /**
     * Test getPath returns Root + folder for a top-level folder
     *
     * @return void
     */
    public function test_get_path_for_top_level_folder(): void
    {
        $topId = $this->createTerm('Top');

        $path = $this->repository->getPath($topId);

        $this->assertCount(2, $path);
        $this->assertTrue($path[0]->isRoot());
        $this->assertSame($topId, $path[1]->getId());
    }

    /**
     * Test getPath for the virtual Root returns just Root
     *
     * @return void
     */
    public function test_get_path_for_virtual_root(): void
    {
        $path = $this->repository->getPath(0);

        $this->assertCount(1, $path);
        $this->assertTrue($path[0]->isRoot());
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

        $updated = $this->repository->update($model->getId(), null, $parentId);

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

        $updated = $this->repository->update($model->getId(), null, null, '#00ff00');

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

        $updated = $this->repository->update($model->getId(), null, null, null, 10);

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
     * Test update throws on invalid color
     *
     * @return void
     */
    public function test_update_throws_on_invalid_color(): void
    {
        $model = $this->repository->create('Folder');

        $this->expectException(InvalidArgumentException::class);

        $this->repository->update($model->getId(), null, null, 'invalid');
    }

    /**
     * Test reparenting a folder shifts the subtree totals between ancestor chains
     *
     * @return void
     */
    public function test_update_reparent_shifts_subtree_totals(): void
    {
        $oldParent = $this->repository->create('Old');
        $newParent = $this->repository->create('New');
        $moving    = $this->repository->create('Moving', $oldParent->getId());

        $att = $this->createAttachmentWithSize(2000);
        $this->assignments->assign($moving->getId(), [$att]);

        // Old chain contains Moving's totals before the move.
        $this->assertSame('1', get_term_meta($oldParent->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('2000', get_term_meta($oldParent->getId(), FolderModel::META_SIZE, true));

        $this->repository->update($moving->getId(), null, $newParent->getId());

        $this->assertSame('0', get_term_meta($oldParent->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('0', get_term_meta($oldParent->getId(), FolderModel::META_SIZE, true));
        $this->assertSame('1', get_term_meta($newParent->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('2000', get_term_meta($newParent->getId(), FolderModel::META_SIZE, true));
        // The moved folder itself keeps its own totals.
        $this->assertSame('1', get_term_meta($moving->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('2000', get_term_meta($moving->getId(), FolderModel::META_SIZE, true));
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
     * Test delete releases media back to root
     *
     * @return void
     */
    public function test_delete_releases_media_to_root(): void
    {
        $folderId     = $this->createTerm('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->assignments->assign($folderId, [$attachmentId]);

        $this->assertSame(0, $this->counters->getUnassignedCount());

        $this->repository->delete($folderId);

        $this->assertSame(1, $this->counters->getUnassignedCount());
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

        $this->assignments->assign($parentId, [$media1, $media2]);
        $this->assignments->assign($childId, [$media3, $media4]);

        $this->repository->delete($parentId);

        $this->assertNull($this->repository->getById($parentId));

        $child = $this->repository->getById($childId);
        $this->assertNotNull($child);
        $this->assertSame(2, $child->getMediaCount());
        $this->assertSame(0, $child->getParentId());

        // The 2 media that were directly on the deleted parent are now unassigned.
        $this->assertSame(2, $this->counters->getUnassignedCount());
    }

    /**
     * Test deleting a folder subtracts its direct totals from ancestors
     *
     * Sub-folder counters stay invariant: their subtrees are intact, only the
     * promotion to grandparent changes — and ancestors already counted them
     * via the deleted folder.
     *
     * @return void
     */
    public function test_delete_subtracts_direct_totals_from_ancestors(): void
    {
        $grand  = $this->repository->create('Grand');
        $parent = $this->repository->create('Parent', $grand->getId());
        $child  = $this->repository->create('Child', $parent->getId());

        $direct = $this->createAttachmentWithSize(400);
        $deep   = $this->createAttachmentWithSize(600);
        $this->assignments->assign($parent->getId(), [$direct]);
        $this->assignments->assign($child->getId(), [$deep]);

        // Pre-delete: grand sees both attachments via parent.
        $this->assertSame('2', get_term_meta($grand->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('1000', get_term_meta($grand->getId(), FolderModel::META_SIZE, true));

        $this->repository->delete($parent->getId());

        // Grand loses parent's direct media (1 / 400) but still aggregates the
        // promoted Child's subtree (1 / 600).
        $this->assertSame('1', get_term_meta($grand->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('600', get_term_meta($grand->getId(), FolderModel::META_SIZE, true));

        // Child's own counters are unchanged.
        $this->assertSame('1', get_term_meta($child->getId(), FolderModel::META_COUNT, true));
        $this->assertSame('600', get_term_meta($child->getId(), FolderModel::META_SIZE, true));
    }

    /**
     * Test create() initializes count + size meta to zero
     *
     * @return void
     */
    public function test_create_initializes_counter_meta(): void
    {
        $folder = $this->repository->create('Folder');

        $this->assertSame('0', get_term_meta($folder->getId(), FolderModel::META_SIZE, true));
        $this->assertSame('0', get_term_meta($folder->getId(), FolderModel::META_COUNT, true));
    }

    // -------------------------------------------------------------------------
    // Virtual Root behavior
    // -------------------------------------------------------------------------

    /**
     * Test getById(0) returns the virtual Root folder
     *
     * @return void
     */
    public function test_get_by_id_returns_virtual_root(): void
    {
        $root = $this->repository->getById(0);

        $this->assertNotNull($root);
        $this->assertSame(0, $root->getId());
        $this->assertTrue($root->isRoot());
    }

    /**
     * Test Root's direct media count equals the unassigned count
     *
     * @return void
     */
    public function test_root_media_count_matches_unassigned_count(): void
    {
        $folder = $this->createTerm('Photos');
        $att1   = $this->factory()->attachment->create(); // assigned
        $this->factory()->attachment->create_many(2);     // 2 unassigned
        $this->assignments->assign($folder, [$att1]);

        $root = $this->repository->getById(0);

        $this->assertNotNull($root);
        $this->assertSame(2, $root->getMediaCount());
    }

    /**
     * Test update() on Root throws InvalidArgumentException
     *
     * @return void
     */
    public function test_update_root_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->update(0, 'New Name');
    }

    /**
     * Test delete() on Root throws InvalidArgumentException
     *
     * @return void
     */
    public function test_delete_root_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->delete(0);
    }

    /**
     * Test getByIds includes the Root entry when 0 is requested
     *
     * @return void
     */
    public function test_get_by_ids_includes_root_when_requested(): void
    {
        $a = $this->createTerm('A');

        $result = $this->repository->getByIds([0, $a]);
        $ids    = array_map(static fn ($m): int => $m->getId(), $result);

        $this->assertContains(0, $ids);
        $this->assertContains($a, $ids);
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
