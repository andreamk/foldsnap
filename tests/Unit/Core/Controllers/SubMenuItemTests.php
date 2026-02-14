<?php

/**
 * Tests for SubMenuItem class
 *
 * @package FoldSnap\Tests\Unit\Core\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Core\Controllers;

use FoldSnap\Core\Controllers\SubMenuItem;
use WP_UnitTestCase;

class SubMenuItemTests extends WP_UnitTestCase
{
    /**
     * Test constructor sets properties
     *
     * @return void
     */
    public function test_constructor_sets_properties(): void
    {
        $item = new SubMenuItem('my-slug', 'My Label', 'parent-slug', 'manage_options', 20);

        $this->assertSame('my-slug', $item->slug);
        $this->assertSame('My Label', $item->label);
        $this->assertSame('parent-slug', $item->parent);
        $this->assertSame('manage_options', $item->capatibility);
        $this->assertSame(20, $item->position);
    }

    /**
     * Test constructor defaults
     *
     * @return void
     */
    public function test_constructor_defaults(): void
    {
        $item = new SubMenuItem('slug');

        $this->assertSame('slug', $item->slug);
        $this->assertSame('', $item->label);
        $this->assertSame('', $item->parent);
        $this->assertTrue($item->capatibility);
        $this->assertSame(10, $item->position);
        $this->assertSame('', $item->link);
        $this->assertFalse($item->active);
        $this->assertSame([], $item->attributes);
    }

    /**
     * Test userCan returns true when capability is true
     *
     * @return void
     */
    public function test_userCan_returns_true_when_capability_is_true(): void
    {
        $item = new SubMenuItem('slug', 'Label', '', true);

        $this->assertTrue($item->userCan());
    }

    /**
     * Test userCan returns true for admin with manage options
     *
     * @return void
     */
    public function test_userCan_returns_true_for_admin_with_manage_options(): void
    {
        $userId = self::factory()->user->create(['role' => 'administrator']);
        wp_set_current_user($userId);

        $item = new SubMenuItem('slug', 'Label', '', 'manage_options');

        $this->assertTrue($item->userCan());
    }

    /**
     * Test userCan returns false for subscriber with manage options
     *
     * @return void
     */
    public function test_userCan_returns_false_for_subscriber_with_manage_options(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);

        $item = new SubMenuItem('slug', 'Label', '', 'manage_options');

        $this->assertFalse($item->userCan());
    }

    /**
     * Test userCan returns false for non string non bool capability
     *
     * @return void
     */
    public function test_userCan_returns_false_for_non_string_non_bool_capability(): void
    {
        $item = new SubMenuItem('slug', 'Label', '', false);

        $this->assertFalse($item->userCan());
    }
}
