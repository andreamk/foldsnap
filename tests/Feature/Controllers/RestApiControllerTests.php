<?php

/**
 * Tests for RestApiController
 *
 * @package FoldSnap\Tests\Feature\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Controllers;

use FoldSnap\Services\CountersRecalculator;
use FoldSnap\Services\FolderCounterService;
use FoldSnap\Services\FolderNameSanitizer;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\MediaFolderAssignmentService;
use FoldSnap\Services\TaxonomyService;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

class RestApiControllerTests extends WP_UnitTestCase
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

        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        /** @var \WP_REST_Server $wp_rest_server */
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');
    }

    // -------------------------------------------------------------------------
    // Auth
    // -------------------------------------------------------------------------

    /**
     * Test unauthorized user gets 401 on folders endpoint
     *
     * @return void
     */
    public function test_unauthorized_user_cannot_access_folders(): void
    {
        wp_set_current_user(0);

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/folders'));

        $this->assertSame(401, $response->get_status());
    }

    /**
     * Test subscriber (no upload_files capability) gets 403
     *
     * @return void
     */
    public function test_subscriber_cannot_access_folders(): void
    {
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriberId);

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/folders'));

        $this->assertSame(403, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // GET /folders — children mode
    // -------------------------------------------------------------------------

    /**
     * Test default GET /folders returns root-level children with mode=children
     *
     * @return void
     */
    public function test_get_folders_default_returns_children_mode(): void
    {
        $this->repository->create('Alpha');
        $this->repository->create('Bravo');

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/folders'));
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('children', $data['mode']);
        $this->assertCount(2, $data['folders']);
        $this->assertSame([0], $data['requested_parent_ids']);
        $this->assertArrayHasKey('root', $data);
    }

    /**
     * Test GET /folders includes the decorated Root folder in the envelope
     *
     * Root is exposed alongside the children list so the client can hydrate
     * `foldersById[0]` without a separate round-trip.
     *
     * @return void
     */
    public function test_get_folders_envelope_includes_root_folder(): void
    {
        $this->repository->create('Alpha');

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/folders'));
        $data     = $response->get_data();

        $this->assertArrayHasKey('root', $data);
        $this->assertNotNull($data['root']);
        $this->assertSame(0, $data['root']['id']);
        $this->assertTrue($data['root']['is_root']);
        $this->assertArrayHasKey('total_media_count', $data['root']);
        $this->assertArrayHasKey('has_children', $data['root']);
    }

    /**
     * Test GET /folders does NOT return nested children inline
     *
     * @return void
     */
    public function test_get_folders_does_not_nest_children(): void
    {
        $parent = $this->repository->create('Parent');
        $this->repository->create('Child', $parent->getId());

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/folders'));
        $data     = $response->get_data();

        // Only the root-level Parent is returned; Child must be fetched via
        // a separate parent_ids[]=Parent.id request.
        $this->assertCount(1, $data['folders']);
        $this->assertSame('Parent', $data['folders'][0]['name']);
        $this->assertArrayNotHasKey('children', $data['folders'][0]);
    }

    /**
     * Test folder rows include decorated fields total_media_count, total_size, has_children
     *
     * @return void
     */
    public function test_get_folders_decorates_each_folder(): void
    {
        $parent = $this->repository->create('Parent');
        $child  = $this->repository->create('Child', $parent->getId());

        $att = $this->createAttachmentWithSize(1500);
        $this->assignments->assign($child->getId(), [$att]);

        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/folders'));
        $data     = $response->get_data();

        $parentRow = $data['folders'][0];
        $this->assertSame(1, $parentRow['total_media_count']);
        $this->assertSame(1500, $parentRow['total_size']);
        $this->assertTrue($parentRow['has_children']);
    }

    /**
     * Test parent_ids[] filter returns children of the requested parents
     *
     * @return void
     */
    public function test_get_folders_filters_by_parent_ids(): void
    {
        $parent = $this->repository->create('Parent');
        $this->repository->create('A', $parent->getId());
        $this->repository->create('B', $parent->getId());
        $this->repository->create('Other');

        $request = new WP_REST_Request('GET', '/foldsnap/v1/folders');
        $request->set_param('parent_ids', [$parent->getId()]);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertCount(2, $data['folders']);
        $names = array_map(static fn (array $f): string => $f['name'], $data['folders']);
        $this->assertContains('A', $names);
        $this->assertContains('B', $names);
        $this->assertSame([$parent->getId()], $data['requested_parent_ids']);
    }

    /**
     * Test pagination of children mode
     *
     * @return void
     */
    public function test_get_folders_paginates(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->repository->create('Folder ' . $i);
        }

        $request = new WP_REST_Request('GET', '/foldsnap/v1/folders');
        $request->set_param('per_page', '2');
        $request->set_param('page', '2');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(5, $data['total']);
        $this->assertSame(3, $data['total_pages']);
        $this->assertCount(2, $data['folders']);
    }

    // -------------------------------------------------------------------------
    // GET /folders — search mode
    // -------------------------------------------------------------------------

    /**
     * Test search mode returns paginated matches with breadcrumbs
     *
     * @return void
     */
    public function test_get_folders_search_returns_results(): void
    {
        $parent = $this->repository->create('Photos');
        $this->repository->create('Photos 2024', $parent->getId());
        $this->repository->create('Documents');

        $request = new WP_REST_Request('GET', '/foldsnap/v1/folders');
        $request->set_param('search', 'Photo');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('search', $data['mode']);
        $this->assertSame('Photo', $data['query']);
        $this->assertSame(2, $data['total']);
        $this->assertCount(2, $data['results']);

        // Each result has folder + breadcrumb (ancestors only).
        foreach ($data['results'] as $r) {
            $this->assertArrayHasKey('folder', $r);
            $this->assertArrayHasKey('breadcrumb', $r);
        }

        // The nested "Photos 2024" should have one ancestor in its breadcrumb.
        $nested = null;
        foreach ($data['results'] as $r) {
            if ('Photos 2024' === $r['folder']['name']) {
                $nested = $r;
                break;
            }
        }
        $this->assertNotNull($nested);
        // Breadcrumb is ancestors (Root + Photos), excluding the folder itself.
        $this->assertCount(2, $nested['breadcrumb']);
        $this->assertTrue($nested['breadcrumb'][0]['is_root']);
        $this->assertSame('Photos', $nested['breadcrumb'][1]['name']);
    }

    /**
     * Test search returns mode=search even with no matches
     *
     * @return void
     */
    public function test_get_folders_search_no_matches(): void
    {
        $this->repository->create('Photos');

        $request = new WP_REST_Request('GET', '/foldsnap/v1/folders');
        $request->set_param('search', 'xyz');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame('search', $data['mode']);
        $this->assertSame(0, $data['total']);
        $this->assertSame([], $data['results']);
    }

    // -------------------------------------------------------------------------
    // GET /folders/{id}/path
    // -------------------------------------------------------------------------

    /**
     * Test path endpoint returns ancestor chain (Root → ... → target)
     *
     * @return void
     */
    public function test_get_folder_path_returns_chain(): void
    {
        $top   = $this->repository->create('Top');
        $child = $this->repository->create('Child', $top->getId());
        $grand = $this->repository->create('Grand', $child->getId());

        $request  = new WP_REST_Request('GET', '/foldsnap/v1/folders/' . $grand->getId() . '/path');
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(4, $data['path']);
        $this->assertSame(0, $data['path'][0]['id']);
        $this->assertTrue($data['path'][0]['is_root']);
        $this->assertSame($top->getId(), $data['path'][1]['id']);
        $this->assertSame($child->getId(), $data['path'][2]['id']);
        $this->assertSame($grand->getId(), $data['path'][3]['id']);
    }

    /**
     * Test path endpoint returns just Root for id=0
     *
     * @return void
     */
    public function test_get_folder_path_for_root(): void
    {
        $request  = new WP_REST_Request('GET', '/foldsnap/v1/folders/0/path');
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $data['path']);
        $this->assertTrue($data['path'][0]['is_root']);
    }

    /**
     * Test path endpoint returns empty path for missing folder
     *
     * @return void
     */
    public function test_get_folder_path_missing_returns_empty(): void
    {
        $request  = new WP_REST_Request('GET', '/foldsnap/v1/folders/999999/path');
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $data['path']);
    }

    // -------------------------------------------------------------------------
    // POST /folders (create)
    // -------------------------------------------------------------------------

    /**
     * Test create folder returns mutation envelope (folder + paths + affected_parents)
     *
     * @return void
     */
    public function test_create_folder_returns_mutation_envelope(): void
    {
        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders');
        $request->set_param('name', 'My Folder');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(201, $response->get_status());
        $this->assertSame('My Folder', $data['folder']['name']);
        $this->assertArrayHasKey('paths', $data);
        $this->assertArrayHasKey('affected_parents', $data);
        $this->assertArrayHasKey('root', $data);
        // Path is [Root, MyFolder] — Root is always the first ancestor.
        $this->assertCount(1, $data['paths']);
        $this->assertCount(2, $data['paths'][0]);
        $this->assertTrue($data['paths'][0][0]['is_root']);
        $this->assertSame('My Folder', $data['paths'][0][1]['name']);
    }

    /**
     * Test create folder under a parent flips parent's has_children
     *
     * @return void
     */
    public function test_create_folder_under_parent_marks_affected(): void
    {
        $parent = $this->repository->create('Parent');

        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders');
        $request->set_param('name', 'Child');
        $request->set_param('parent_id', (string) $parent->getId());

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(201, $response->get_status());
        $this->assertCount(1, $data['affected_parents']);
        $this->assertSame($parent->getId(), $data['affected_parents'][0]['id']);
        $this->assertTrue($data['affected_parents'][0]['has_children']);
    }

    /**
     * Test create folder requires name
     *
     * @return void
     */
    public function test_create_folder_requires_name(): void
    {
        $request  = new WP_REST_Request('POST', '/foldsnap/v1/folders');
        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // PUT /folders/{id} (update)
    // -------------------------------------------------------------------------

    /**
     * Test update changes name
     *
     * @return void
     */
    public function test_update_folder_changes_name(): void
    {
        $folder = $this->repository->create('Old');

        $request = new WP_REST_Request('PUT', '/foldsnap/v1/folders/' . $folder->getId());
        $request->set_param('name', 'New');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('New', $data['folder']['name']);
    }

    /**
     * Test reparenting reports both old and new parents in affected_parents
     *
     * @return void
     */
    public function test_update_folder_reparent_marks_both_parents_affected(): void
    {
        $oldParent = $this->repository->create('Old Parent');
        $newParent = $this->repository->create('New Parent');
        $child     = $this->repository->create('Child', $oldParent->getId());

        $request = new WP_REST_Request('PUT', '/foldsnap/v1/folders/' . $child->getId());
        $request->set_param('parent_id', (string) $newParent->getId());

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());

        $affectedIds = array_map(static fn (array $p): int => $p['id'], $data['affected_parents']);
        $this->assertContains($oldParent->getId(), $affectedIds);
        $this->assertContains($newParent->getId(), $affectedIds);
    }

    // -------------------------------------------------------------------------
    // DELETE /folders/{id}
    // -------------------------------------------------------------------------

    /**
     * Test delete folder returns deleted=true and old parent in affected
     *
     * @return void
     */
    public function test_delete_folder_returns_envelope(): void
    {
        $parent = $this->repository->create('Parent');
        $child  = $this->repository->create('Child', $parent->getId());

        $request  = new WP_REST_Request('DELETE', '/foldsnap/v1/folders/' . $child->getId());
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['deleted']);
        $this->assertSame($child->getId(), $data['id']);

        $affectedIds = array_map(static fn (array $p): int => $p['id'], $data['affected_parents']);
        $this->assertContains($parent->getId(), $affectedIds);

        // Parent no longer has children after the delete.
        foreach ($data['affected_parents'] as $entry) {
            if ($entry['id'] === $parent->getId()) {
                $this->assertFalse($entry['has_children']);
            }
        }
    }

    /**
     * Test delete root-level folder skips parent ID 0 in affected
     *
     * @return void
     */
    public function test_delete_root_folder_no_affected_parents(): void
    {
        $folder = $this->repository->create('Root');

        $request  = new WP_REST_Request('DELETE', '/foldsnap/v1/folders/' . $folder->getId());
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $data['affected_parents']);
    }

    /**
     * Test delete missing folder returns 400
     *
     * @return void
     */
    public function test_delete_missing_folder_returns_error(): void
    {
        $response = $this->dispatchRequest(new WP_REST_Request('DELETE', '/foldsnap/v1/folders/999999'));

        $this->assertSame(400, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // Media assignment
    // -------------------------------------------------------------------------

    /**
     * Test assignMedia returns mutation envelope with destination chain
     *
     * @return void
     */
    public function test_assign_media_returns_envelope_with_destination_chain(): void
    {
        $parent = $this->repository->create('Parent');
        $child  = $this->repository->create('Child', $parent->getId());
        $att    = $this->createAttachmentWithSize(800);

        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders/' . $child->getId() . '/media');
        $request->set_param('media_ids', [$att]);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['assigned']);
        // Chain is Root → Parent → Child.
        $this->assertCount(1, $data['paths']);
        $this->assertCount(3, $data['paths'][0]);
        $this->assertTrue($data['paths'][0][0]['is_root']);
        $this->assertSame($parent->getId(), $data['paths'][0][1]['id']);
        $this->assertSame($child->getId(), $data['paths'][0][2]['id']);
        // Parent's total includes the just-assigned media.
        $this->assertSame(1, $data['paths'][0][1]['total_media_count']);
        $this->assertSame(800, $data['paths'][0][1]['total_size']);
    }

    /**
     * Test assignMedia includes origin chains so previous folders refresh
     *
     * Moving a media from one folder to another must surface BOTH ancestor
     * chains in `paths`: the destination chain (gained the media) and the
     * origin chain (lost it). Without this the origin's total_media_count
     * stays stale until a full refetch.
     *
     * @return void
     */
    public function test_assign_media_includes_origin_chain_when_reassigning(): void
    {
        $originParent = $this->repository->create('OriginParent');
        $origin       = $this->repository->create('Origin', $originParent->getId());
        $destParent   = $this->repository->create('DestParent');
        $dest         = $this->repository->create('Dest', $destParent->getId());

        $att = $this->createAttachmentWithSize(500);
        $this->assignments->assign($origin->getId(), [$att]);

        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders/' . $dest->getId() . '/media');
        $request->set_param('media_ids', [$att]);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(2, $data['paths'], 'expected destination + origin chains');

        // First chain is always the destination: Root → DestParent → Dest.
        $destChain = $data['paths'][0];
        $this->assertCount(3, $destChain);
        $this->assertTrue($destChain[0]['is_root']);
        $this->assertSame($destParent->getId(), $destChain[1]['id']);
        $this->assertSame($dest->getId(), $destChain[2]['id']);
        $this->assertSame(1, $destChain[1]['total_media_count']);
        $this->assertSame(500, $destChain[1]['total_size']);

        // Second chain is the origin: Root → OriginParent → Origin, totals at 0.
        $originChain = $data['paths'][1];
        $this->assertCount(3, $originChain);
        $this->assertTrue($originChain[0]['is_root']);
        $this->assertSame($originParent->getId(), $originChain[1]['id']);
        $this->assertSame($origin->getId(), $originChain[2]['id']);
        $this->assertSame(0, $originChain[1]['total_media_count']);
        $this->assertSame(0, $originChain[1]['total_size']);

        // Origin's parent should also appear in affected_parents so the UI
        // can refresh its has_children chevron if needed.
        $affectedIds = array_column($data['affected_parents'], 'id');
        $this->assertContains($origin->getId(), $affectedIds);
    }

    /**
     * Test assignMedia rejects missing media_ids
     *
     * @return void
     */
    public function test_assign_media_requires_media_ids(): void
    {
        $folder = $this->repository->create('Folder');

        $request  = new WP_REST_Request('POST', '/foldsnap/v1/folders/' . $folder->getId() . '/media');
        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    /**
     * Test removeMedia returns updated path totals
     *
     * @return void
     */
    public function test_remove_media_returns_envelope(): void
    {
        $folder = $this->repository->create('Folder');
        $att    = $this->createAttachmentWithSize(500);
        $this->assignments->assign($folder->getId(), [$att]);

        $request = new WP_REST_Request('DELETE', '/foldsnap/v1/folders/' . $folder->getId() . '/media');
        $request->set_param('media_ids', [$att]);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['removed']);
        $this->assertSame(0, $data['folder']['total_media_count']);
        $this->assertSame(0, $data['folder']['total_size']);
    }

    // -------------------------------------------------------------------------
    // GET /media
    // -------------------------------------------------------------------------

    /**
     * Test get media for an assigned folder returns its attachments
     *
     * @return void
     */
    public function test_get_media_returns_assigned(): void
    {
        $folder = $this->repository->create('Photos');
        $att    = $this->factory()->attachment->create();
        $this->assignments->assign($folder->getId(), [$att]);

        $request = new WP_REST_Request('GET', '/foldsnap/v1/media');
        $request->set_param('folder_id', (string) $folder->getId());

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame(1, $data['total']);
        $this->assertCount(1, $data['media']);
        $this->assertSame($att, $data['media'][0]['id']);
    }

    /**
     * Test get media with folder_id=0 returns unassigned
     *
     * @return void
     */
    public function test_get_media_folder_zero_returns_unassigned(): void
    {
        $folder   = $this->repository->create('Photos');
        $assigned = $this->factory()->attachment->create();
        $this->factory()->attachment->create();

        $this->assignments->assign($folder->getId(), [$assigned]);

        $request = new WP_REST_Request('GET', '/foldsnap/v1/media');
        $request->set_param('folder_id', '0');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(1, $data['total']);
    }

    /**
     * Test get media requires folder_id parameter
     *
     * @return void
     */
    public function test_get_media_requires_folder_id(): void
    {
        $response = $this->dispatchRequest(new WP_REST_Request('GET', '/foldsnap/v1/media'));

        $this->assertSame(400, $response->get_status());
    }

    /**
     * Test get media returns empty for empty folder
     *
     * @return void
     */
    public function test_get_media_returns_empty_for_empty_folder(): void
    {
        $folder = $this->repository->create('Empty');

        $request = new WP_REST_Request('GET', '/foldsnap/v1/media');
        $request->set_param('folder_id', (string) $folder->getId());

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame([], $data['media']);
        $this->assertSame(0, $data['total']);
    }

    /**
     * Test POST /folders/recalculate runs and reports completion on empty tree
     *
     * @return void
     */
    public function test_recalculate_endpoint_runs_to_completion(): void
    {
        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders/recalculate');
        $request->set_param('limit', 10);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['done']);
        $this->assertSame(0, $data['processed']);
        $this->assertSame(0, $data['remaining']);
    }

    /**
     * Test POST /folders/recalculate with reset=true clears the pending stack
     *
     * @return void
     */
    public function test_recalculate_endpoint_reset_clears_pending_stack(): void
    {
        // Pre-populate the pending stack and the "already initialized" flag
        // as if a previous run had been interrupted halfway.
        update_option(CountersRecalculator::OPT_STACK, [42, 99], false);
        update_option(CountersRecalculator::OPT_INITIALIZED, '1');

        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders/recalculate');
        $request->set_param('limit', 10);
        $request->set_param('reset', true);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['done']);

        // After reset on an empty tree the stack is rebuilt from scratch and
        // immediately drained — and the initialized flag is set back to '1'
        // by the completion path. The key invariant the reset path
        // guarantees: the stale [42, 99] entries are gone.
        $stack = get_option(CountersRecalculator::OPT_STACK, null);
        $this->assertNotSame([42, 99], $stack);
    }

    /**
     * Test POST /folders/recalculate is forbidden for non-admins
     *
     * @return void
     */
    public function test_recalculate_endpoint_forbidden_for_subscriber(): void
    {
        $subscriberId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($subscriberId);

        $response = $this->dispatchRequest(new WP_REST_Request('POST', '/foldsnap/v1/folders/recalculate'));

        $this->assertSame(403, $response->get_status());
    }

    /**
     * Dispatch a REST request through the server
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response
     */
    private function dispatchRequest(WP_REST_Request $request): WP_REST_Response
    {
        return rest_do_request($request);
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
