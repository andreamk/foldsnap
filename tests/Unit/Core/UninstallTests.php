<?php

/**
 * Tests for Uninstall class
 *
 * @package FoldSnap\Tests\Unit\Core
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Core;

use FoldSnap\Core\Uninstall;
use FoldSnap\Utils\ExpireOptions;
use WP_UnitTestCase;

class UninstallTests extends WP_UnitTestCase
{
    /**
     * Tear down test environment
     *
     * @return void
     */
    public function tearDown(): void
    {
        delete_option('foldsnap_opt_setting_a');
        delete_option('foldsnap_opt_setting_b');
        delete_option('unrelated_option');
        delete_transient('foldsnap_cache_data');
        delete_transient('foldsnap_another');
        delete_transient('other_plugin_transient');
        ExpireOptions::deleteAll();

        parent::tearDown();
    }

    /**
     * Test constants are defined
     *
     * @return void
     */
    public function test_constants_are_defined(): void
    {
        $this->assertSame('foldsnap_opt_', Uninstall::OPTIONS_PREFIX);
        $this->assertSame('foldsnap_', Uninstall::TRANSIENTS_PREFIX);
    }

    /**
     * Test run deletes plugin options
     *
     * @return void
     */
    public function test_run_deletes_plugin_options(): void
    {
        update_option('foldsnap_opt_setting_a', 'value_a');
        update_option('foldsnap_opt_setting_b', 'value_b');

        Uninstall::run();

        $this->assertFalse(get_option('foldsnap_opt_setting_a'));
        $this->assertFalse(get_option('foldsnap_opt_setting_b'));
    }

    /**
     * Test run preserves unrelated options
     *
     * @return void
     */
    public function test_run_preserves_unrelated_options(): void
    {
        update_option('unrelated_option', 'keep_me');
        update_option('foldsnap_opt_setting_a', 'delete_me');

        Uninstall::run();

        $this->assertSame('keep_me', get_option('unrelated_option'));
    }

    /**
     * Test run deletes plugin transients
     *
     * @return void
     */
    public function test_run_deletes_plugin_transients(): void
    {
        set_transient('foldsnap_cache_data', 'cached_value', 3600);
        set_transient('foldsnap_another', 'another_value', 3600);

        Uninstall::run();

        $this->assertFalse(get_transient('foldsnap_cache_data'));
        $this->assertFalse(get_transient('foldsnap_another'));
    }

    /**
     * Test run preserves unrelated transients
     *
     * @return void
     */
    public function test_run_preserves_unrelated_transients(): void
    {
        set_transient('other_plugin_transient', 'keep_me', 3600);
        set_transient('foldsnap_cache_data', 'delete_me', 3600);

        Uninstall::run();

        $this->assertSame('keep_me', get_transient('other_plugin_transient'));
    }

    /**
     * Test run deletes both options and transients together
     *
     * @return void
     */
    public function test_run_deletes_both_options_and_transients(): void
    {
        update_option('foldsnap_opt_setting_a', 'opt_value');
        set_transient('foldsnap_cache_data', 'trans_value', 3600);

        Uninstall::run();

        $this->assertFalse(get_option('foldsnap_opt_setting_a'));
        $this->assertFalse(get_transient('foldsnap_cache_data'));
    }

    /**
     * Test run deletes expire options
     *
     * @return void
     */
    public function test_run_deletes_expire_options(): void
    {
        ExpireOptions::set('test_cache', 'cached_value', 3600);

        $this->assertSame('cached_value', ExpireOptions::getString('test_cache'));

        Uninstall::run();

        $this->assertFalse(get_option(ExpireOptions::OPTION_PREFIX . 'test_cache'));
    }

    /**
     * Test run does not fail when no plugin data exists
     *
     * @return void
     */
    public function test_run_does_not_fail_when_no_plugin_data_exists(): void
    {
        Uninstall::run();

        $this->assertTrue(true);
    }
}
