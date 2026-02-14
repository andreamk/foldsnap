<?php

/**
 * Tests for SanitizeInput utility class
 *
 * Note: filter_input() reads from the actual PHP input stream, not from superglobals.
 * In CLI/PHPUnit, filter_input(INPUT_GET/INPUT_POST, ...) always returns null even if
 * $_GET/$_POST are populated. Therefore, methods that use filter_input() can only be
 * tested for default-value behavior and INPUT_SERVER paths (which use $_SERVER directly).
 * The strictArray() method reads from superglobals via getInputFromType() and is fully testable.
 *
 * @package FoldSnap\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Utils;

use FoldSnap\Utils\SanitizeInput;
use FoldSnap\Utils\Sanitize;
use WP_UnitTestCase;

class SanitizeInputTests extends WP_UnitTestCase
{
    /** @var string[] */
    private array $serverKeysToClean = [];

    /** @var string[] */
    private array $getKeysToClean = [];

    /** @var string[] */
    private array $postKeysToClean = [];

    /**
     * Tear down test environment
     *
     * @return void
     */
    public function tearDown(): void
    {
        foreach ($this->serverKeysToClean as $key) {
            unset($_SERVER[$key]);
        }

        foreach ($this->getKeysToClean as $key) {
            unset($_GET[$key]);
        }

        foreach ($this->postKeysToClean as $key) {
            unset($_POST[$key]);
        }

        $this->serverKeysToClean = [];
        $this->getKeysToClean    = [];
        $this->postKeysToClean   = [];

        parent::tearDown();
    }

    /**
     * Test INPUT_REQUEST constant value
     *
     * @return void
     */
    public function test_INPUT_REQUEST_constant_is_defined(): void
    {
        $this->assertSame(10000, SanitizeInput::INPUT_REQUEST);
    }

    /**
     * Test str returns default when variable not exists
     *
     * @return void
     */
    public function test_str_returns_default_when_variable_not_exists(): void
    {
        $this->assertSame('', SanitizeInput::str(INPUT_GET, 'nonexistent_var_xyz'));
    }

    /**
     * Test str returns custom default when variable not exists
     *
     * @return void
     */
    public function test_str_returns_custom_default_when_variable_not_exists(): void
    {
        $this->assertSame('fallback', SanitizeInput::str(INPUT_GET, 'nonexistent_var_xyz', 'fallback'));
    }

    /**
     * Test str reads from SERVER superglobal
     *
     * @return void
     */
    public function test_str_reads_from_SERVER_superglobal(): void
    {
        $_SERVER['FOLDSNAP_TEST_STR'] = 'hello world';
        $this->serverKeysToClean[]    = 'FOLDSNAP_TEST_STR';

        $this->assertSame('hello world', SanitizeInput::str(INPUT_SERVER, 'FOLDSNAP_TEST_STR'));
    }

    /**
     * Test str sanitizes control characters from SERVER
     *
     * @return void
     */
    public function test_str_sanitizes_control_characters_from_SERVER(): void
    {
        $_SERVER['FOLDSNAP_TEST_CTRL'] = "hello\x00world";
        $this->serverKeysToClean[]     = 'FOLDSNAP_TEST_CTRL';

        $this->assertSame('helloworld', SanitizeInput::str(INPUT_SERVER, 'FOLDSNAP_TEST_CTRL'));
    }

    /**
     * Test str applies flags from SERVER
     *
     * @return void
     */
    public function test_str_applies_flags_from_SERVER(): void
    {
        $_SERVER['FOLDSNAP_TEST_TRIM'] = '  spaced  ';
        $this->serverKeysToClean[]     = 'FOLDSNAP_TEST_TRIM';

        $this->assertSame('spaced', SanitizeInput::str(INPUT_SERVER, 'FOLDSNAP_TEST_TRIM', '', Sanitize::STRIP_TRIM));
    }

    /**
     * Test str returns default for missing SERVER variable
     *
     * @return void
     */
    public function test_str_returns_default_for_missing_SERVER_variable(): void
    {
        $this->assertSame('default', SanitizeInput::str(INPUT_SERVER, 'FOLDSNAP_NONEXISTENT_XYZ', 'default'));
    }

    /**
     * Test strictStr returns default when variable not exists
     *
     * @return void
     */
    public function test_strictStr_returns_default_when_variable_not_exists(): void
    {
        $this->assertSame('', SanitizeInput::strictStr(INPUT_GET, 'nonexistent_var_xyz'));
    }

    /**
     * Test strictStr returns custom default when variable not exists
     *
     * @return void
     */
    public function test_strictStr_returns_custom_default_when_variable_not_exists(): void
    {
        $this->assertSame('fallback', SanitizeInput::strictStr(INPUT_GET, 'nonexistent_var_xyz', 'fallback'));
    }

    /**
     * Test strictStr reads from SERVER and strips special chars
     *
     * @return void
     */
    public function test_strictStr_reads_from_SERVER_and_strips_special_chars(): void
    {
        $_SERVER['FOLDSNAP_TEST_STRICT'] = 'hello!@#world';
        $this->serverKeysToClean[]       = 'FOLDSNAP_TEST_STRICT';

        $this->assertSame('helloworld', SanitizeInput::strictStr(INPUT_SERVER, 'FOLDSNAP_TEST_STRICT'));
    }

    /**
     * Test strictStr with extra accepted chars from SERVER
     *
     * @return void
     */
    public function test_strictStr_with_extra_accepted_chars_from_SERVER(): void
    {
        $_SERVER['FOLDSNAP_TEST_EXTRA'] = 'file-name_v2.txt';
        $this->serverKeysToClean[]      = 'FOLDSNAP_TEST_EXTRA';

        $this->assertSame('file-name_v2.txt', SanitizeInput::strictStr(INPUT_SERVER, 'FOLDSNAP_TEST_EXTRA', '', '-_.'));
    }

    /**
     * Test strictStr returns default when result is empty after sanitization
     *
     * @return void
     */
    public function test_strictStr_returns_default_when_result_is_empty_after_sanitization(): void
    {
        $_SERVER['FOLDSNAP_TEST_EMPTY'] = '!@#$%';
        $this->serverKeysToClean[]      = 'FOLDSNAP_TEST_EMPTY';

        $this->assertSame('fallback', SanitizeInput::strictStr(INPUT_SERVER, 'FOLDSNAP_TEST_EMPTY', 'fallback'));
    }

    /**
     * Test toInt returns default when variable not exists
     *
     * @return void
     */
    public function test_toInt_returns_default_when_variable_not_exists(): void
    {
        $this->assertSame(0, SanitizeInput::toInt(INPUT_GET, 'nonexistent_var_xyz'));
    }

    /**
     * Test toInt returns custom default when variable not exists
     *
     * @return void
     */
    public function test_toInt_returns_custom_default_when_variable_not_exists(): void
    {
        $this->assertSame(-1, SanitizeInput::toInt(INPUT_GET, 'nonexistent_var_xyz', -1));
    }

    /**
     * Test toInt reads integer from SERVER
     *
     * @return void
     */
    public function test_toInt_reads_integer_from_SERVER(): void
    {
        $_SERVER['FOLDSNAP_TEST_INT'] = '42';
        $this->serverKeysToClean[]    = 'FOLDSNAP_TEST_INT';

        $this->assertSame(42, SanitizeInput::toInt(INPUT_SERVER, 'FOLDSNAP_TEST_INT'));
    }

    /**
     * Test toInt returns default for non numeric SERVER value
     *
     * @return void
     */
    public function test_toInt_returns_default_for_non_numeric_SERVER_value(): void
    {
        $_SERVER['FOLDSNAP_TEST_NAN'] = 'abc';
        $this->serverKeysToClean[]    = 'FOLDSNAP_TEST_NAN';

        $this->assertSame(0, SanitizeInput::toInt(INPUT_SERVER, 'FOLDSNAP_TEST_NAN'));
    }

    /**
     * Test toBool returns default when variable not exists
     *
     * @return void
     */
    public function test_toBool_returns_default_when_variable_not_exists(): void
    {
        $this->assertFalse(SanitizeInput::toBool(INPUT_GET, 'nonexistent_var_xyz'));
    }

    /**
     * Test toBool returns custom default when variable not exists
     *
     * @return void
     */
    public function test_toBool_returns_custom_default_when_variable_not_exists(): void
    {
        $this->assertTrue(SanitizeInput::toBool(INPUT_GET, 'nonexistent_var_xyz', true));
    }

    /**
     * Test toBool reads true value from SERVER
     *
     * @return void
     */
    public function test_toBool_reads_true_value_from_SERVER(): void
    {
        $_SERVER['FOLDSNAP_TEST_BOOL'] = 'true';
        $this->serverKeysToClean[]     = 'FOLDSNAP_TEST_BOOL';

        $this->assertTrue(SanitizeInput::toBool(INPUT_SERVER, 'FOLDSNAP_TEST_BOOL'));
    }

    /**
     * Test toBool reads false value from SERVER
     *
     * @return void
     */
    public function test_toBool_reads_false_value_from_SERVER(): void
    {
        $_SERVER['FOLDSNAP_TEST_BOOL_F'] = 'false';
        $this->serverKeysToClean[]       = 'FOLDSNAP_TEST_BOOL_F';

        $this->assertFalse(SanitizeInput::toBool(INPUT_SERVER, 'FOLDSNAP_TEST_BOOL_F'));
    }

    /**
     * Test strictArray returns default when variable not exists in GET
     *
     * @return void
     */
    public function test_strictArray_returns_default_when_variable_not_exists(): void
    {
        $this->assertSame([], SanitizeInput::strictArray(INPUT_GET, 'nonexistent_var_xyz'));
    }

    /**
     * Test strictArray returns custom default when variable not exists
     *
     * @return void
     */
    public function test_strictArray_returns_custom_default_when_variable_not_exists(): void
    {
        $default = ['fallback'];

        $this->assertSame($default, SanitizeInput::strictArray(INPUT_GET, 'nonexistent_var_xyz', $default));
    }

    /**
     * Test strictArray reads and sanitizes array from GET
     *
     * @return void
     */
    public function test_strictArray_reads_and_sanitizes_array_from_GET(): void
    {
        $_GET['foldsnap_test_arr'] = [
            'hello!',
            'world@',
        ];
        $this->getKeysToClean[]    = 'foldsnap_test_arr';

        $this->assertSame(['hello', 'world'], SanitizeInput::strictArray(INPUT_GET, 'foldsnap_test_arr'));
    }

    /**
     * Test strictArray reads from POST
     *
     * @return void
     */
    public function test_strictArray_reads_from_POST(): void
    {
        $_POST['foldsnap_test_arr'] = ['test123'];
        $this->postKeysToClean[]    = 'foldsnap_test_arr';

        $this->assertSame(['test123'], SanitizeInput::strictArray(INPUT_POST, 'foldsnap_test_arr'));
    }

    /**
     * Test strictArray returns default when value is not array
     *
     * @return void
     */
    public function test_strictArray_returns_default_when_value_is_not_array(): void
    {
        $_GET['foldsnap_test_str'] = 'not_an_array';
        $this->getKeysToClean[]    = 'foldsnap_test_str';

        $this->assertSame([], SanitizeInput::strictArray(INPUT_GET, 'foldsnap_test_str'));
    }

    /**
     * Test strictArray with extra accepted chars
     *
     * @return void
     */
    public function test_strictArray_with_extra_accepted_chars(): void
    {
        $_GET['foldsnap_test_ext'] = [
            'file-1.txt',
            'dir/path',
        ];
        $this->getKeysToClean[]    = 'foldsnap_test_ext';

        $expected = [
            'file-1.txt',
            'dirpath',
        ];

        $this->assertSame($expected, SanitizeInput::strictArray(INPUT_GET, 'foldsnap_test_ext', [], '-.'));
    }

    /**
     * Test strictArray with INPUT_REQUEST reads from GET
     *
     * @return void
     */
    public function test_strictArray_with_INPUT_REQUEST_reads_from_GET(): void
    {
        $_GET['foldsnap_test_req'] = ['from get'];
        $this->getKeysToClean[]    = 'foldsnap_test_req';

        $this->assertSame(['from get'], SanitizeInput::strictArray(SanitizeInput::INPUT_REQUEST, 'foldsnap_test_req'));
    }

    /**
     * Test strictArray with INPUT_REQUEST POST overrides GET
     *
     * @return void
     */
    public function test_strictArray_with_INPUT_REQUEST_POST_overrides_GET(): void
    {
        $_GET['foldsnap_test_ovr']  = ['from get'];
        $_POST['foldsnap_test_ovr'] = ['from post'];
        $this->getKeysToClean[]     = 'foldsnap_test_ovr';
        $this->postKeysToClean[]    = 'foldsnap_test_ovr';

        $result = SanitizeInput::strictArray(SanitizeInput::INPUT_REQUEST, 'foldsnap_test_ovr');

        $this->assertSame(['from post'], $result);
    }
}
