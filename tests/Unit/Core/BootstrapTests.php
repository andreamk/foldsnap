<?php

/**
 * Tests for Bootstrap class
 *
 * @package FoldSnap\Tests\Unit\Core
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Core;

use FoldSnap\Core\Bootstrap;
use FoldSnap\Services\TaxonomyService;
use WP_UnitTestCase;

class BootstrapTests extends WP_UnitTestCase
{
    /**
     * Test init registers onInit on init hook
     *
     * @return void
     */
    public function test_init_registers_onInit_on_init_hook(): void
    {
        $this->assertGreaterThan(
            0,
            has_action('init', [Bootstrap::class, 'onInit'])
        );
    }

    /**
     * Test onInit registers taxonomy
     *
     * @return void
     */
    public function test_onInit_registers_taxonomy(): void
    {
        Bootstrap::onInit();

        $this->assertTrue(taxonomy_exists(TaxonomyService::TAXONOMY_NAME));
    }

    /**
     * Test onInit registers menu hook in admin
     *
     * @return void
     */
    public function test_onInit_registers_menu_hook_in_admin(): void
    {
        set_current_screen('dashboard');

        Bootstrap::onInit();

        $menuHook = is_multisite() ? 'network_admin_menu' : 'admin_menu';

        $this->assertGreaterThan(
            0,
            has_action($menuHook, [Bootstrap::class, 'menu'])
        );
    }

    /**
     * Test menu does not throw
     *
     * @return void
     */
    public function test_menu_does_not_throw(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        Bootstrap::menu();

        $this->assertTrue(true);
    }
}
