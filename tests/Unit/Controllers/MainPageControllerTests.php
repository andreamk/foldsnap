<?php

/**
 * Tests for MainPageController
 *
 * Tests specific to the MainPageController configuration.
 * Abstract controller behavior is tested in AbstractMenuPageControllerTests.
 *
 * @package FoldSnap\Tests\Unit\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Controllers;

use FoldSnap\Controllers\MainPageController;
use FoldSnap\Core\Controllers\AbstractMenuPageController;
use FoldSnap\Core\Controllers\ControllersManager;
use WP_UnitTestCase;

class MainPageControllerTests extends WP_UnitTestCase
{
    private MainPageController $controller;

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

        $this->controller = MainPageController::getInstance();
    }

    /**
     * Test getInstance returns singleton
     *
     * @return void
     */
    public function test_getInstance_returns_singleton(): void
    {
        $this->assertSame(
            MainPageController::getInstance(),
            MainPageController::getInstance()
        );
    }

    /**
     * Test is instance of abstract menu page controller
     *
     * @return void
     */
    public function test_is_instance_of_abstract_menu_page_controller(): void
    {
        $this->assertInstanceOf(AbstractMenuPageController::class, $this->controller);
    }

    /**
     * Test slug is main menu slug
     *
     * @return void
     */
    public function test_slug_is_main_menu_slug(): void
    {
        $this->assertSame(ControllersManager::MAIN_MENU_SLUG, $this->controller->getSlug());
    }

    /**
     * Test position is 10
     *
     * @return void
     */
    public function test_position_is_10(): void
    {
        $this->assertSame(10, $this->controller->getPosition());
    }

    /**
     * Test is not main page because has parent slug
     *
     * @return void
     */
    public function test_is_not_main_page_because_has_parent_slug(): void
    {
        $this->assertFalse($this->controller->isMainPage());
    }

    /**
     * Test is enabled returns true
     *
     * @return void
     */
    public function test_is_enabled_returns_true(): void
    {
        $this->assertTrue($this->controller->isEnabled());
    }

    /**
     * Test getMenuLink contains slug
     *
     * @return void
     */
    public function test_getMenuLink_contains_slug(): void
    {
        $link = $this->controller->getMenuLink();

        $this->assertStringContainsString('page=foldsnap', $link);
    }

    /**
     * Test getPageUrl contains slug
     *
     * @return void
     */
    public function test_getPageUrl_contains_slug(): void
    {
        $url = $this->controller->getPageUrl();

        $this->assertStringContainsString('page=foldsnap', $url);
    }
}
