<?php

/**
 * Tests for PageAction class
 *
 * @package FoldSnap\Tests\Unit\Core\Controllers
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Core\Controllers;

use FoldSnap\Core\Controllers\ControllersManager;
use FoldSnap\Core\Controllers\PageAction;
use Exception;
use WP_UnitTestCase;

class PageActionTests extends WP_UnitTestCase
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
     * Test constructor throws for empty key
     *
     * @return void
     */
    public function test_constructor_throws_for_empty_key(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage("action key can't be empty");

        new PageAction('', fn() => [], ['foldsnap']);
    }

    /**
     * Test constructor throws for empty menuSlugs
     *
     * @return void
     */
    public function test_constructor_throws_for_empty_menuSlugs(): void
    {
        $this->expectException(Exception::class);
        $this->expectExceptionMessage('menuSlugs have to be array with at least one element');

        new PageAction('my_action', fn() => [], []);
    }

    /**
     * Test constructor sets properties
     *
     * @return void
     */
    public function test_constructor_sets_properties(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap', 'settings']);

        $this->assertSame('save', $action->getKey());
    }

    /**
     * Test getNonceKey includes slugs and action
     *
     * @return void
     */
    public function test_getNonceKey_includes_slugs_and_action(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap', 'settings']);

        $this->assertSame('foldsnap_nonce_foldsnap_settings_save', $action->getNonceKey());
    }

    /**
     * Test getNonceKey replaces special chars
     *
     * @return void
     */
    public function test_getNonceKey_replaces_special_chars(): void
    {
        $action = new PageAction('do-save', fn() => [], ['fold.snap']);

        $nonceKey = $action->getNonceKey();

        $this->assertStringNotContainsString('-', $nonceKey);
        $this->assertStringNotContainsString('.', $nonceKey);
    }

    /**
     * Test getNonce returns non empty string
     *
     * @return void
     */
    public function test_getNonce_returns_non_empty_string(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap']);

        $nonce = $action->getNonce();

        $this->assertIsString($nonce);
        $this->assertNotEmpty($nonce);
    }

    /**
     * Test getBaseUrl contains page slug
     *
     * @return void
     */
    public function test_getBaseUrl_contains_page_slug(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap', 'tab1']);

        $url = $action->getBaseUrl();

        $this->assertStringContainsString('page=foldsnap', $url);
        $this->assertStringContainsString('tab=tab1', $url);
        $this->assertStringNotContainsString('action=', $url);
    }

    /**
     * Test getBaseUrl includes inner page
     *
     * @return void
     */
    public function test_getBaseUrl_includes_inner_page(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap'], 'detail');

        $url = $action->getBaseUrl();

        $this->assertStringContainsString('inner_page=detail', $url);
    }

    /**
     * Test getUrl contains action and nonce
     *
     * @return void
     */
    public function test_getUrl_contains_action_and_nonce(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap']);

        $url = $action->getUrl();

        $this->assertStringContainsString('action=save', $url);
        $this->assertStringContainsString('_wpnonce=', $url);
    }

    /**
     * Test getUrl includes extra data
     *
     * @return void
     */
    public function test_getUrl_includes_extra_data(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap']);

        $url = $action->getUrl(['custom' => 'value']);

        $this->assertStringContainsString('custom=value', $url);
    }

    /**
     * Test isPageOfCurrentAction matches when slugs match
     *
     * @return void
     */
    public function test_isPageOfCurrentAction_matches_when_slugs_match(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap', 'settings']);

        $this->assertTrue($action->isPageOfCurrentAction(['foldsnap', 'settings', 'advanced']));
    }

    /**
     * Test isPageOfCurrentAction returns false when slugs differ
     *
     * @return void
     */
    public function test_isPageOfCurrentAction_returns_false_when_slugs_differ(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap', 'settings']);

        $this->assertFalse($action->isPageOfCurrentAction(['foldsnap', 'general']));
    }

    /**
     * Test isCurrentAction returns true for matching action and slugs
     *
     * @return void
     */
    public function test_isCurrentAction_returns_true_for_matching_action_and_slugs(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap', 'settings']);

        $this->assertTrue($action->isCurrentAction(['foldsnap', 'settings'], '', 'save'));
    }

    /**
     * Test isCurrentAction returns false for different action key
     *
     * @return void
     */
    public function test_isCurrentAction_returns_false_for_different_action_key(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap']);

        $this->assertFalse($action->isCurrentAction(['foldsnap'], '', 'delete'));
    }

    /**
     * Test isCurrentAction checks inner page
     *
     * @return void
     */
    public function test_isCurrentAction_checks_inner_page(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap'], 'detail');

        $this->assertTrue($action->isCurrentAction(['foldsnap'], 'detail', 'save'));
        $this->assertFalse($action->isCurrentAction(['foldsnap'], 'other', 'save'));
    }

    /**
     * Test isCurrentAction ignores inner page when not set
     *
     * @return void
     */
    public function test_isCurrentAction_ignores_inner_page_when_not_set(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap']);

        $this->assertTrue($action->isCurrentAction(['foldsnap'], 'anything', 'save'));
    }

    /**
     * Test userCan returns true when capability is true
     *
     * @return void
     */
    public function test_userCan_returns_true_when_capability_is_true(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap'], '', true);

        $this->assertTrue($action->userCan());
    }

    /**
     * Test userCan checks capability string
     *
     * @return void
     */
    public function test_userCan_checks_capability_string(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap'], '', 'manage_options');

        $this->assertTrue($action->userCan());
    }

    /**
     * Test userCan returns false for insufficient capability
     *
     * @return void
     */
    public function test_userCan_returns_false_for_insufficient_capability(): void
    {
        $userId = self::factory()->user->create(['role' => 'subscriber']);
        wp_set_current_user($userId);

        $action = new PageAction('save', fn() => [], ['foldsnap'], '', 'manage_options');

        $this->assertFalse($action->userCan());
    }

    /**
     * Test getActionNonceFileds contains nonce and action input
     *
     * @return void
     */
    public function test_getActionNonceFileds_contains_nonce_and_action_input(): void
    {
        $action = new PageAction('save', fn() => [], ['foldsnap']);

        $html = $action->getActionNonceFileds(false);

        $this->assertStringContainsString('_wpnonce', $html);
        $this->assertStringContainsString('name="action"', $html);
        $this->assertStringContainsString('value="save"', $html);
    }
}
