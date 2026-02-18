<?php

/**
 * Tests for MediaLibraryController
 *
 * @package FoldSnap\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Controllers;

use FoldSnap\Controllers\MediaLibraryController;
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

        $this->controller = MediaLibraryController::getInstance();
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
        $this->assertStringContainsString('restNonce', $scriptData);
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
}
