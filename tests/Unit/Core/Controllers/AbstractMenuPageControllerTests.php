<?php

/**
 * Tests for AbstractMenuPageController using TestController
 *
 * @package FoldSnap\Tests\Unit\Core\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Core\Controllers;

use FoldSnap\Core\Controllers\AbstractMenuPageController;
use FoldSnap\Tests\TestsUtils\TestController;
use WP_UnitTestCase;

class AbstractMenuPageControllerTests extends WP_UnitTestCase
{
    private TestController $controller;

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

        $this->controller = TestController::create();
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
     * Test isMainPage returns true when no parent slug
     *
     * @return void
     */
    public function test_isMainPage_returns_true_when_no_parent_slug(): void
    {
        $ctrl = TestController::create('test-main', 'Main', 'Main', 'manage_options', '');

        $this->assertTrue($ctrl->isMainPage());
    }

    /**
     * Test isMainPage returns false when has parent slug
     *
     * @return void
     */
    public function test_isMainPage_returns_false_when_has_parent_slug(): void
    {
        $ctrl = TestController::create('test-sub', 'Sub', 'Sub', 'manage_options', 'upload.php');

        $this->assertFalse($ctrl->isMainPage());
    }

    /**
     * Test getPosition returns configured value
     *
     * @return void
     */
    public function test_getPosition_returns_configured_value(): void
    {
        $ctrl = TestController::create('test-pos', 'Page', 'Menu', 'manage_options', '', 75);

        $this->assertSame(75, $ctrl->getPosition());
    }

    /**
     * Test getPosition returns default 50
     *
     * @return void
     */
    public function test_getPosition_returns_default_50(): void
    {
        $this->assertSame(50, $this->controller->getPosition());
    }

    /**
     * Test getSlug returns configured slug
     *
     * @return void
     */
    public function test_getSlug_returns_configured_slug(): void
    {
        $ctrl = TestController::create('custom-slug');

        $this->assertSame('custom-slug', $ctrl->getSlug());
    }

    /**
     * Test getMenuLink contains slug
     *
     * @return void
     */
    public function test_getMenuLink_contains_slug(): void
    {
        $link = $this->controller->getMenuLink();

        $this->assertStringContainsString('page=test-page', $link);
    }

    /**
     * Test getMenuLink with l2 tab
     *
     * @return void
     */
    public function test_getMenuLink_with_l2_tab(): void
    {
        $link = $this->controller->getMenuLink('settings');

        $this->assertStringContainsString('page=test-page', $link);
        $this->assertStringContainsString('tab=settings', $link);
    }

    /**
     * Test getMenuLink with l2 and l3 tabs
     *
     * @return void
     */
    public function test_getMenuLink_with_l2_and_l3_tabs(): void
    {
        $link = $this->controller->getMenuLink('settings', 'advanced');

        $this->assertStringContainsString('tab=settings', $link);
        $this->assertStringContainsString('subtab=advanced', $link);
    }

    /**
     * Test getMenuLink with extra data
     *
     * @return void
     */
    public function test_getMenuLink_with_extra_data(): void
    {
        $link = $this->controller->getMenuLink(null, null, ['foo' => 'bar']);

        $this->assertStringContainsString('foo=bar', $link);
    }

    /**
     * Test getPageUrl contains slug
     *
     * @return void
     */
    public function test_getPageUrl_contains_slug(): void
    {
        $url = $this->controller->getPageUrl();

        $this->assertStringContainsString('page=test-page', $url);
    }

    /**
     * Test registerMenu returns string for top level page
     *
     * @return void
     */
    public function test_registerMenu_returns_string_for_top_level_page(): void
    {
        $ctrl   = TestController::create('test-toplevel', 'Top', 'Top', 'manage_options', '');
        $result = $ctrl->registerMenu();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test registerMenu returns string for submenu page
     *
     * @return void
     */
    public function test_registerMenu_returns_string_for_submenu_page(): void
    {
        $ctrl   = TestController::create('test-submenu', 'Sub', 'Sub', 'manage_options', 'upload.php');
        $result = $ctrl->registerMenu();

        $this->assertIsString($result);
        $this->assertNotEmpty($result);
    }

    /**
     * Test registerMenu sets menu hook suffix
     *
     * @return void
     */
    public function test_registerMenu_sets_menu_hook_suffix(): void
    {
        $this->controller->registerMenu();

        $this->assertIsString($this->controller->getMenuHookSuffix());
    }

    /**
     * Test registerMenu returns false for unauthorized user
     *
     * @return void
     */
    public function test_registerMenu_returns_false_for_unauthorized_user(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);

        $ctrl = TestController::create('test-noauth', 'Page', 'Menu', 'manage_options');

        $this->assertFalse($ctrl->registerMenu());
    }

    /**
     * Test registerMenu returns false when menu label is empty
     *
     * @return void
     */
    public function test_registerMenu_returns_false_when_menu_label_is_empty(): void
    {
        $ctrl = TestController::create('test-nolabel', 'Page', '', 'manage_options');

        $this->assertFalse($ctrl->registerMenu());
    }

    /**
     * Test registerMenu returns false for user without capability
     *
     * @return void
     */
    public function test_registerMenu_returns_false_for_user_without_capability(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);

        $ctrl = TestController::create('test-nocap', 'Page', 'Menu', 'manage_options');

        $this->assertFalse($ctrl->registerMenu());
    }

    /**
     * Test getMenuHookSuffix returns false before register
     *
     * @return void
     */
    public function test_getMenuHookSuffix_returns_false_before_register(): void
    {
        $this->assertFalse($this->controller->getMenuHookSuffix());
    }

    /**
     * Test getMenuHookSuffix returns string after register
     *
     * @return void
     */
    public function test_getMenuHookSuffix_returns_string_after_register(): void
    {
        $this->controller->registerMenu();

        $this->assertIsString($this->controller->getMenuHookSuffix());
    }

    /**
     * Test resetMenuHookSuffix resets to false
     *
     * @return void
     */
    public function test_resetMenuHookSuffix_resets_to_false(): void
    {
        $this->controller->registerMenu();
        $this->assertIsString($this->controller->getMenuHookSuffix());

        $this->controller->resetMenuHookSuffix();
        $this->assertFalse($this->controller->getMenuHookSuffix());
    }

    /**
     * Test isEnabled returns true by default
     *
     * @return void
     */
    public function test_isEnabled_returns_true_by_default(): void
    {
        $this->assertTrue($this->controller->isEnabled());
    }

    /**
     * Test hookWpInit does not throw
     *
     * @return void
     */
    public function test_hookWpInit_does_not_throw(): void
    {
        $this->controller->hookWpInit();

        $this->assertTrue(true);
    }

    /**
     * Test getActions returns empty array by default
     *
     * @return void
     */
    public function test_getActions_returns_empty_array_by_default(): void
    {
        $this->assertSame([], $this->controller->getActions());
    }

    /**
     * Test getActionByKey returns false when no actions
     *
     * @return void
     */
    public function test_getActionByKey_returns_false_when_no_actions(): void
    {
        $this->assertFalse($this->controller->getActionByKey('nonexistent'));
    }

    /**
     * Test isCurrentPage returns false when no page param
     *
     * @return void
     */
    public function test_isCurrentPage_returns_false_when_no_page_param(): void
    {
        $this->assertFalse($this->controller->isCurrentPage());
    }

    /**
     * Test registerMenu with upload files capability
     *
     * @return void
     */
    public function test_registerMenu_with_upload_files_capability(): void
    {
        $ctrl   = TestController::create('test-upload', 'Upload', 'Upload', 'upload_files');
        $result = $ctrl->registerMenu();

        $this->assertIsString($result);
    }

    /**
     * Test registerMenu with upload files capability denied for subscriber
     *
     * @return void
     */
    public function test_registerMenu_with_upload_files_capability_denied_for_subscriber(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);

        $ctrl = TestController::create('test-upload-denied', 'Upload', 'Upload', 'upload_files');

        $this->assertFalse($ctrl->registerMenu());
    }
}
