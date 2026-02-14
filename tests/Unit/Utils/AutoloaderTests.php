<?php

/**
 * Tests for Autoloader utility class
 *
 * @package FoldSnap\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Utils;

use FoldSnap\Utils\Autoloader;
use WP_UnitTestCase;

class AutoloaderTests extends WP_UnitTestCase
{
    /**
     * Test load ignores non foldsnap namespace
     *
     * @return void
     */
    public function test_load_ignores_non_foldsnap_namespace(): void
    {
        Autoloader::load('SomeOther\\Namespace\\ClassName');

        $this->assertTrue(true);
    }

    /**
     * Test load resolves correct file path for existing class
     *
     * @return void
     */
    public function test_load_resolves_correct_file_path_for_existing_class(): void
    {
        Autoloader::load('FoldSnap\\Utils\\Sanitize');

        $this->assertTrue(class_exists('FoldSnap\\Utils\\Sanitize', false));
    }

    /**
     * Test load does nothing for nonexistent class
     *
     * @return void
     */
    public function test_load_does_nothing_for_nonexistent_class(): void
    {
        Autoloader::load('FoldSnap\\NonExistent\\FakeClass');

        $this->assertFalse(class_exists('FoldSnap\\NonExistent\\FakeClass', false));
    }

    /**
     * Test register adds autoloader to spl
     *
     * @return void
     */
    public function test_register_adds_autoloader_to_spl(): void
    {
        $autoloaders = spl_autoload_functions();

        $found = false;
        foreach ($autoloaders as $autoloader) {
            if (is_array($autoloader) && $autoloader[0] === Autoloader::class && $autoloader[1] === 'load') {
                $found = true;
                break;
            }
        }

        $this->assertTrue($found);
    }
}
