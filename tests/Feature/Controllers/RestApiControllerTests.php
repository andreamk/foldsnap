<?php

/**
 * Tests for RestApiController
 *
 * @package FoldSnap\Tests\Feature\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Controllers;

use FoldSnap\Models\FolderModel;
use FoldSnap\Services\FolderRepository;
use FoldSnap\Services\TaxonomyService;
use WP_REST_Request;
use WP_REST_Response;
use WP_UnitTestCase;

class RestApiControllerTests extends WP_UnitTestCase
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

        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        /** @var \WP_REST_Server $wp_rest_server */
        global $wp_rest_server;
        $wp_rest_server = new \WP_REST_Server();
        do_action('rest_api_init');
    }

    /**
     * Test unauthorized user gets 403 on folders endpoint
     *
     * @return void
     */
    public function test_unauthorized_user_cannot_access_folders(): void
    {
        wp_set_current_user(0);

        $request  = new WP_REST_Request('GET', '/foldsnap/v1/folders');
        $response = $this->dispatchRequest($request);

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

        $request  = new WP_REST_Request('GET', '/foldsnap/v1/folders');
        $response = $this->dispatchRequest($request);

        $this->assertSame(403, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // GET /foldsnap/v1/folders
    // -------------------------------------------------------------------------

    /**
     * Test get folders returns empty tree
     *
     * @return void
     */
    public function test_get_folders_returns_empty_tree(): void
    {
        $request  = new WP_REST_Request('GET', '/foldsnap/v1/folders');
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertIsArray($data);
        $this->assertSame([], $data['folders']);
        $this->assertArrayHasKey('root_media_count', $data);
        $this->assertArrayHasKey('root_total_size', $data);
    }

    /**
     * Test get folders returns tree structure
     *
     * @return void
     */
    public function test_get_folders_returns_tree(): void
    {
        $parent = $this->repository->create('Parent');
        $this->repository->create('Child', $parent->getId());

        $request  = new WP_REST_Request('GET', '/foldsnap/v1/folders');
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $data['folders']);
        $this->assertSame('Parent', $data['folders'][0]['name']);
        $this->assertCount(1, $data['folders'][0]['children']);
        $this->assertSame('Child', $data['folders'][0]['children'][0]['name']);
    }

    /**
     * Test get folders includes root media count and size
     *
     * @return void
     */
    public function test_get_folders_includes_root_stats(): void
    {
        $attachmentId = $this->createAttachmentWithSize(1024);

        $request  = new WP_REST_Request('GET', '/foldsnap/v1/folders');
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(1, $data['root_media_count']);
        $this->assertSame(1024, $data['root_total_size']);
    }

    // -------------------------------------------------------------------------
    // POST /foldsnap/v1/folders
    // -------------------------------------------------------------------------

    /**
     * Test create folder with valid name
     *
     * @return void
     */
    public function test_create_folder_succeeds(): void
    {
        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders');
        $request->set_body_params(['name' => 'Photos']);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(201, $response->get_status());
        $this->assertSame('Photos', $data['name']);
        $this->assertSame(0, $data['parent_id']);
    }

    /**
     * Test create folder with all optional fields
     *
     * @return void
     */
    public function test_create_folder_with_options(): void
    {
        $parent = $this->repository->create('Parent');

        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders');
        $request->set_body_params([
            'name'      => 'Child',
            'parent_id' => (string) $parent->getId(),
            'color'     => '#ff0000',
            'position'  => '5',
        ]);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(201, $response->get_status());
        $this->assertSame('Child', $data['name']);
        $this->assertSame($parent->getId(), $data['parent_id']);
        $this->assertSame('#ff0000', $data['color']);
        $this->assertSame(5, $data['position']);
    }

    /**
     * Test create folder without name returns 400
     *
     * @return void
     */
    public function test_create_folder_without_name_returns_400(): void
    {
        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders');
        $request->set_body_params([]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    /**
     * Test create folder with invalid parent returns 400
     *
     * @return void
     */
    public function test_create_folder_with_invalid_parent_returns_400(): void
    {
        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders');
        $request->set_body_params([
            'name'      => 'Orphan',
            'parent_id' => '999999',
        ]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    /**
     * Test create folder with invalid color returns 400
     *
     * @return void
     */
    public function test_create_folder_with_invalid_color_returns_400(): void
    {
        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders');
        $request->set_body_params([
            'name'  => 'Folder',
            'color' => 'not-a-color',
        ]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // PUT /foldsnap/v1/folders/{id}
    // -------------------------------------------------------------------------

    /**
     * Test update folder name
     *
     * @return void
     */
    public function test_update_folder_name(): void
    {
        $folder = $this->repository->create('Old Name');

        $request = new WP_REST_Request('PUT', '/foldsnap/v1/folders/' . $folder->getId());
        $request->set_body_params(['name' => 'New Name']);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('New Name', $data['name']);
    }

    /**
     * Test update folder color and position
     *
     * @return void
     */
    public function test_update_folder_color_and_position(): void
    {
        $folder = $this->repository->create('Folder');

        $request = new WP_REST_Request('PUT', '/foldsnap/v1/folders/' . $folder->getId());
        $request->set_body_params([
            'color'    => '#00ff00',
            'position' => '10',
        ]);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('#00ff00', $data['color']);
        $this->assertSame(10, $data['position']);
    }

    /**
     * Test update non-existent folder returns 400
     *
     * @return void
     */
    public function test_update_nonexistent_folder_returns_400(): void
    {
        $request = new WP_REST_Request('PUT', '/foldsnap/v1/folders/999999');
        $request->set_body_params(['name' => 'Name']);

        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    /**
     * Test update without optional params preserves values (sentinel -1)
     *
     * @return void
     */
    public function test_update_without_optional_params_preserves_values(): void
    {
        $folder = $this->repository->create('Folder', 0, '#ff0000', 5);

        $request = new WP_REST_Request('PUT', '/foldsnap/v1/folders/' . $folder->getId());
        $request->set_body_params(['name' => 'Renamed']);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertSame('Renamed', $data['name']);
        $this->assertSame(0, $data['parent_id']);
        $this->assertSame('#ff0000', $data['color']);
        $this->assertSame(5, $data['position']);
    }

    // -------------------------------------------------------------------------
    // DELETE /foldsnap/v1/folders/{id}
    // -------------------------------------------------------------------------

    /**
     * Test delete folder
     *
     * @return void
     */
    public function test_delete_folder_succeeds(): void
    {
        $folder = $this->repository->create('To Delete');

        $request  = new WP_REST_Request('DELETE', '/foldsnap/v1/folders/' . $folder->getId());
        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['deleted']);
        $this->assertNull($this->repository->getById($folder->getId()));
    }

    /**
     * Test delete non-existent folder returns 400
     *
     * @return void
     */
    public function test_delete_nonexistent_folder_returns_400(): void
    {
        $request  = new WP_REST_Request('DELETE', '/foldsnap/v1/folders/999999');
        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // POST /foldsnap/v1/folders/{id}/media
    // -------------------------------------------------------------------------

    /**
     * Test assign media to folder
     *
     * @return void
     */
    public function test_assign_media_succeeds(): void
    {
        $folder       = $this->repository->create('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders/' . $folder->getId() . '/media');
        $request->set_body_params(['media_ids' => [$attachmentId]]);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['assigned']);

        $updated = $this->repository->getById($folder->getId());
        $this->assertSame(1, $updated->getMediaCount());
    }

    /**
     * Test assign media without media_ids returns 400
     *
     * @return void
     */
    public function test_assign_media_without_ids_returns_400(): void
    {
        $folder = $this->repository->create('Photos');

        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders/' . $folder->getId() . '/media');
        $request->set_body_params([]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    /**
     * Test assign media to non-existent folder returns 400
     *
     * @return void
     */
    public function test_assign_media_to_nonexistent_folder_returns_400(): void
    {
        $attachmentId = $this->factory()->attachment->create();

        $request = new WP_REST_Request('POST', '/foldsnap/v1/folders/999999/media');
        $request->set_body_params(['media_ids' => [$attachmentId]]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // DELETE /foldsnap/v1/folders/{id}/media
    // -------------------------------------------------------------------------

    /**
     * Test remove media from folder
     *
     * @return void
     */
    public function test_remove_media_succeeds(): void
    {
        $folder       = $this->repository->create('Photos');
        $attachmentId = $this->factory()->attachment->create();

        $this->repository->assignMedia($folder->getId(), [$attachmentId]);

        $request = new WP_REST_Request('DELETE', '/foldsnap/v1/folders/' . $folder->getId() . '/media');
        $request->set_body_params(['media_ids' => [$attachmentId]]);

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertTrue($data['removed']);

        $updated = $this->repository->getById($folder->getId());
        $this->assertSame(0, $updated->getMediaCount());
    }

    /**
     * Test remove media without media_ids returns 400
     *
     * @return void
     */
    public function test_remove_media_without_ids_returns_400(): void
    {
        $folder = $this->repository->create('Photos');

        $request = new WP_REST_Request('DELETE', '/foldsnap/v1/folders/' . $folder->getId() . '/media');
        $request->set_body_params([]);

        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    // -------------------------------------------------------------------------
    // GET /foldsnap/v1/media
    // -------------------------------------------------------------------------

    /**
     * Test get media without folder_id returns 400
     *
     * @return void
     */
    public function test_get_media_without_folder_id_returns_400(): void
    {
        $request  = new WP_REST_Request('GET', '/foldsnap/v1/media');
        $response = $this->dispatchRequest($request);

        $this->assertSame(400, $response->get_status());
    }

    /**
     * Test get unassigned media (folder_id=0)
     *
     * @return void
     */
    public function test_get_unassigned_media(): void
    {
        $id1 = $this->factory()->attachment->create();
        $id2 = $this->factory()->attachment->create();

        $request = new WP_REST_Request('GET', '/foldsnap/v1/media');
        $request->set_param('folder_id', '0');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());

        $returnedIds = array_column($data['media'], 'id');
        $this->assertContains($id1, $returnedIds);
        $this->assertContains($id2, $returnedIds);
        $this->assertGreaterThanOrEqual(2, $data['total']);
    }

    /**
     * Test get media in specific folder
     *
     * @return void
     */
    public function test_get_media_in_folder(): void
    {
        $folder     = $this->repository->create('Photos');
        $attachment = $this->factory()->attachment->create();
        $this->factory()->attachment->create(); // unassigned

        $this->repository->assignMedia($folder->getId(), [$attachment]);

        $request = new WP_REST_Request('GET', '/foldsnap/v1/media');
        $request->set_param('folder_id', (string) $folder->getId());

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $data['media']);
        $this->assertSame($attachment, $data['media'][0]['id']);
    }

    /**
     * Test media response includes expected fields
     *
     * @return void
     */
    public function test_media_item_has_expected_fields(): void
    {
        $this->factory()->attachment->create();

        $request = new WP_REST_Request('GET', '/foldsnap/v1/media');
        $request->set_param('folder_id', '0');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();
        $item     = $data['media'][0];

        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('filename', $item);
        $this->assertArrayHasKey('thumbnail_url', $item);
        $this->assertArrayHasKey('url', $item);
        $this->assertArrayHasKey('file_size', $item);
        $this->assertArrayHasKey('mime_type', $item);
        $this->assertArrayHasKey('date', $item);
    }

    /**
     * Test media pagination
     *
     * @return void
     */
    public function test_get_media_pagination(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->factory()->attachment->create();
        }

        $request = new WP_REST_Request('GET', '/foldsnap/v1/media');
        $request->set_param('folder_id', '0');
        $request->set_param('per_page', '2');
        $request->set_param('page', '1');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(2, $data['media']);
        $this->assertSame(5, $data['total']);
        $this->assertSame(3, $data['total_pages']);
        $this->assertSame('5', $response->get_headers()['X-WP-Total']);
        $this->assertSame('3', $response->get_headers()['X-WP-TotalPages']);
    }

    /**
     * Test media pagination second page
     *
     * @return void
     */
    public function test_get_media_second_page(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->factory()->attachment->create();
        }

        $request = new WP_REST_Request('GET', '/foldsnap/v1/media');
        $request->set_param('folder_id', '0');
        $request->set_param('per_page', '2');
        $request->set_param('page', '3');

        $response = $this->dispatchRequest($request);
        $data     = $response->get_data();

        $this->assertSame(200, $response->get_status());
        $this->assertCount(1, $data['media']);
    }

    /**
     * Test get media returns empty for folder with no media
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
     * Dispatch a REST request through the server
     *
     * @param WP_REST_Request $request REST request object
     *
     * @return WP_REST_Response
     */
    private function dispatchRequest(WP_REST_Request $request): WP_REST_Response
    {
        /** @var \WP_REST_Server $wp_rest_server */
        global $wp_rest_server;

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
