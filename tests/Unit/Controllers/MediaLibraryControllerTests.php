<?php

/**
 * Tests for MediaLibraryController
 *
 * @package FoldSnap\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Controllers;

use FoldSnap\Controllers\MediaLibraryController;
use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class MediaLibraryControllerTests extends WP_UnitTestCase
{
    private MediaLibraryController $controller;

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();

        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        TaxonomyService::register();
        $this->controller = MediaLibraryController::getInstance();
    }

    /**
     * Clean up superglobals after each test
     *
     * @return void
     */
    public function tearDown(): void
    {
        unset($_GET['mode'], $_GET['foldsnap_folder_id']);
        unset($_REQUEST['query']);
        parent::tearDown();
    }

    /**
     * Test getInstance returns singleton
     *
     * @return void
     */
    public function test_getInstance_returns_singleton(): void
    {
        $this->assertSame(
            MediaLibraryController::getInstance(),
            MediaLibraryController::getInstance()
        );
    }

    /**
     * Test assets are not enqueued on non-upload screens
     *
     * @return void
     */
    public function test_enqueueAssets_does_nothing_on_non_upload_screens(): void
    {
        set_current_screen('dashboard');

        $this->controller->enqueueAssets();

        $this->assertFalse(wp_script_is('foldsnap-admin', 'enqueued'));
        $this->assertFalse(wp_style_is('foldsnap-admin', 'enqueued'));
    }

    /**
     * Test enqueueAssets enqueues script and localizes data on upload screen
     *
     * @return void
     */
    public function test_enqueueAssets_enqueues_script_on_upload_screen(): void
    {
        set_current_screen('upload');

        $this->controller->enqueueAssets();

        if (! wp_script_is('foldsnap-admin', 'enqueued')) {
            $this->markTestSkipped('Asset file not available (build required).');
        }

        /** @var \WP_Scripts $wp_scripts */
        global $wp_scripts;
        $scriptData = $wp_scripts->get_data('foldsnap-admin', 'data');

        $this->assertIsString($scriptData);
        $this->assertStringContainsString('foldsnap_data', $scriptData);
        $this->assertStringContainsString('restUrl', $scriptData);
    }

    /**
     * Test enqueueAssets enqueues drag-drop bridge script on upload screen
     *
     * @return void
     */
    public function test_enqueueAssets_enqueues_dragdrop_script_on_upload_screen(): void
    {
        set_current_screen('upload');

        $this->controller->enqueueAssets();

        if (! wp_script_is('foldsnap-admin', 'enqueued')) {
            $this->markTestSkipped('Asset file not available (build required).');
        }

        $this->assertTrue(wp_script_is('foldsnap-dragdrop', 'enqueued'));
    }

    /**
     * Test enqueueAssets enqueues styles on upload screen
     *
     * @return void
     */
    public function test_enqueueAssets_enqueues_styles_on_upload_screen(): void
    {
        set_current_screen('upload');

        $this->controller->enqueueAssets();

        if (! wp_script_is('foldsnap-admin', 'enqueued')) {
            $this->markTestSkipped('Asset file not available (build required).');
        }

        $this->assertTrue(wp_style_is('wp-components', 'enqueued'));
        $this->assertTrue(wp_style_is('foldsnap-admin', 'enqueued'));
    }

    // ── getMediaMode ──────────────────────────────────────────────────

    /**
     * Test getMediaMode returns 'grid' by default
     *
     * @return void
     */
    public function test_getMediaMode_returns_grid_by_default(): void
    {
        $this->assertSame('grid', MediaLibraryController::getMediaMode());
    }

    /**
     * Test getMediaMode returns URL mode parameter when set to 'list'
     *
     * @return void
     */
    public function test_getMediaMode_returns_url_mode_when_list(): void
    {
        $_GET['mode'] = 'list';
        $this->assertSame('list', MediaLibraryController::getMediaMode());
    }

    /**
     * Test getMediaMode returns URL mode parameter when set to 'grid'
     *
     * @return void
     */
    public function test_getMediaMode_returns_url_mode_when_grid(): void
    {
        $_GET['mode'] = 'grid';
        $this->assertSame('grid', MediaLibraryController::getMediaMode());
    }

    /**
     * Test getMediaMode ignores invalid URL mode values
     *
     * @return void
     */
    public function test_getMediaMode_ignores_invalid_url_mode(): void
    {
        $_GET['mode'] = 'invalid';
        $this->assertSame('grid', MediaLibraryController::getMediaMode());
    }

    /**
     * Test getMediaMode falls back to user option when no URL parameter
     *
     * @return void
     */
    public function test_getMediaMode_falls_back_to_user_option(): void
    {
        update_user_option(get_current_user_id(), 'media_library_mode', 'list');
        $this->assertSame('list', MediaLibraryController::getMediaMode());
    }

    /**
     * Test getMediaMode URL parameter takes priority over user option
     *
     * @return void
     */
    public function test_getMediaMode_url_overrides_user_option(): void
    {
        update_user_option(get_current_user_id(), 'media_library_mode', 'list');
        $_GET['mode'] = 'grid';
        $this->assertSame('grid', MediaLibraryController::getMediaMode());
    }

    // ── filterAttachmentsByFolder ─────────────────────────────────────

    /**
     * Test filterAttachmentsByFolder does not modify query when no folder is set
     *
     * @return void
     */
    public function test_filterAttachmentsByFolder_no_change_without_folder(): void
    {
        $query  = ['post_type' => 'attachment'];
        $result = $this->controller->filterAttachmentsByFolder($query);

        $this->assertArrayNotHasKey('tax_query', $result);
    }

    /**
     * Test filterAttachmentsByFolder adds tax_query for root folder (id=0)
     *
     * @return void
     */
    public function test_filterAttachmentsByFolder_adds_tax_query_for_root(): void
    {
        // SanitizeInput::INPUT_REQUEST reads from array_merge($_GET, $_POST)
        $_GET['query'] = ['foldsnap_folder_id' => '0'];

        $query  = ['post_type' => 'attachment'];
        $result = $this->controller->filterAttachmentsByFolder($query);

        $this->assertArrayHasKey('tax_query', $result);
        $this->assertSame('NOT EXISTS', $result['tax_query'][0]['operator']);
    }

    /**
     * Test filterAttachmentsByFolder adds tax_query for specific folder
     *
     * @return void
     */
    public function test_filterAttachmentsByFolder_adds_tax_query_for_folder(): void
    {
        $_GET['query'] = ['foldsnap_folder_id' => '5'];

        $query  = ['post_type' => 'attachment'];
        $result = $this->controller->filterAttachmentsByFolder($query);

        $this->assertArrayHasKey('tax_query', $result);
        $this->assertSame('term_id', $result['tax_query'][0]['field']);
        $this->assertSame(5, $result['tax_query'][0]['terms']);
    }

    // ── filterListModeByFolder ────────────────────────────────────────

    /**
     * Test filterListModeByFolder does nothing in grid mode
     *
     * @return void
     */
    public function test_filterListModeByFolder_skips_grid_mode(): void
    {
        $_GET['mode']               = 'grid';
        $_GET['foldsnap_folder_id'] = '3';

        set_current_screen('upload');

        $query = new \WP_Query();
        $query->set('post_type', 'attachment');

        $this->controller->filterListModeByFolder($query);

        $this->assertEmpty($query->get('tax_query'));
    }

    /**
     * Test filterListModeByFolder does nothing for non-attachment queries
     *
     * @return void
     */
    public function test_filterListModeByFolder_skips_non_attachment_query(): void
    {
        $_GET['mode']               = 'list';
        $_GET['foldsnap_folder_id'] = '3';

        set_current_screen('upload');

        $query = new \WP_Query();
        $query->set('post_type', 'post');

        $this->controller->filterListModeByFolder($query);

        $this->assertEmpty($query->get('tax_query'));
    }
}
