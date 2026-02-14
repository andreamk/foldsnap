<?php

/**
 * Tests for ControllersManager class
 *
 * @package FoldSnap\Tests\Unit\Core\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Core\Controllers;

use FoldSnap\Core\Controllers\ControllersManager;
use WP_UnitTestCase;

class ControllersManagerTests extends WP_UnitTestCase
{
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
    }

    /**
     * Test getInstance returns same instance
     *
     * @return void
     */
    public function test_getInstance_returns_same_instance(): void
    {
        $this->assertSame(
            ControllersManager::getInstance(),
            ControllersManager::getInstance()
        );
    }

    /**
     * Test getPageUniqueId with page only
     *
     * @return void
     */
    public function test_getPageUniqueId_with_page_only(): void
    {
        $this->assertSame('foldsnap_id_main', ControllersManager::getPageUniqueId('main'));
    }

    /**
     * Test getPageUniqueId with page and tab
     *
     * @return void
     */
    public function test_getPageUniqueId_with_page_and_tab(): void
    {
        $this->assertSame('foldsnap_id_main_settings', ControllersManager::getPageUniqueId('main', 'settings'));
    }

    /**
     * Test getPageUniqueId with all levels
     *
     * @return void
     */
    public function test_getPageUniqueId_with_all_levels(): void
    {
        $this->assertSame(
            'foldsnap_id_main_settings_advanced',
            ControllersManager::getPageUniqueId('main', 'settings', 'advanced')
        );
    }

    /**
     * Test getPageUniqueId ignores empty tab
     *
     * @return void
     */
    public function test_getPageUniqueId_ignores_empty_tab(): void
    {
        $this->assertSame('foldsnap_id_main', ControllersManager::getPageUniqueId('main', ''));
    }

    /**
     * Test getPageUniqueId ignores subtab when tab empty
     *
     * @return void
     */
    public function test_getPageUniqueId_ignores_subtab_when_tab_empty(): void
    {
        $this->assertSame('foldsnap_id_main', ControllersManager::getPageUniqueId('main', '', 'sub'));
    }

    /**
     * Test getMenuLink relative contains page
     *
     * @return void
     */
    public function test_getMenuLink_relative_contains_page(): void
    {
        $link = ControllersManager::getMenuLink('foldsnap');

        $this->assertStringContainsString('admin.php', $link);
        $this->assertStringContainsString('page=foldsnap', $link);
    }

    /**
     * Test getMenuLink with subtabs
     *
     * @return void
     */
    public function test_getMenuLink_with_subtabs(): void
    {
        $link = ControllersManager::getMenuLink('foldsnap', 'settings', 'advanced');

        $this->assertStringContainsString('page=foldsnap', $link);
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
        $link = ControllersManager::getMenuLink('foldsnap', null, null, ['custom' => 'val']);

        $this->assertStringContainsString('custom=val', $link);
    }

    /**
     * Test getMenuLink absolute contains full url
     *
     * @return void
     */
    public function test_getMenuLink_absolute_contains_full_url(): void
    {
        $link = ControllersManager::getMenuLink('foldsnap', null, null, [], false);

        $this->assertStringContainsString('http', $link);
        $this->assertStringContainsString('admin.php', $link);
    }

    /**
     * Test getMenuPages returns main page controller
     *
     * @return void
     */
    public function test_getMenuPages_returns_main_page_controller(): void
    {
        $pages = ControllersManager::getMenuPages();

        $this->assertNotEmpty($pages);
        $this->assertSame('foldsnap', $pages[0]->getSlug());
    }

    /**
     * Test getPageControlleBySlug returns controller for valid slug
     *
     * @return void
     */
    public function test_getPageControlleBySlug_returns_controller_for_valid_slug(): void
    {
        $controller = ControllersManager::getPageControlleBySlug('foldsnap');

        $this->assertNotFalse($controller);
        $this->assertSame('foldsnap', $controller->getSlug());
    }

    /**
     * Test getPageControlleBySlug returns false for invalid slug
     *
     * @return void
     */
    public function test_getPageControlleBySlug_returns_false_for_invalid_slug(): void
    {
        $this->assertFalse(ControllersManager::getPageControlleBySlug('nonexistent'));
    }

    /**
     * Test constants are defined
     *
     * @return void
     */
    public function test_constants_are_defined(): void
    {
        $this->assertSame('foldsnap', ControllersManager::MAIN_MENU_SLUG);
        $this->assertSame('page', ControllersManager::QUERY_STRING_MENU_KEY_L1);
        $this->assertSame('tab', ControllersManager::QUERY_STRING_MENU_KEY_L2);
        $this->assertSame('subtab', ControllersManager::QUERY_STRING_MENU_KEY_L3);
        $this->assertSame('action', ControllersManager::QUERY_STRING_MENU_KEY_ACTION);
        $this->assertSame('inner_page', ControllersManager::QUERY_STRING_INNER_PAGE);
    }

    /**
     * Test getAction returns false when no action set
     *
     * @return void
     */
    public function test_getAction_returns_false_when_no_action_set(): void
    {
        $this->assertFalse(ControllersManager::getAction());
    }
}
