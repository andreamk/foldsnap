<?php

/**
 * Tests for ExpireOptions utility class
 *
 * @package FoldSnap\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Utils;

use FoldSnap\Utils\ExpireOptions;
use WP_UnitTestCase;

class ExpireOptionsTests extends WP_UnitTestCase
{
    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->resetCache();
    }

    /**
     * Tear down test environment
     *
     * @return void
     */
    public function tearDown(): void
    {
        ExpireOptions::deleteAll();
        $this->resetCache();
        parent::tearDown();
    }

    /**
     * Test set and getString returns stored value
     *
     * @return void
     */
    public function test_set_and_getString_returns_stored_value(): void
    {
        ExpireOptions::set('test_key', 'hello');

        $this->assertSame('hello', ExpireOptions::getString('test_key'));
    }

    /**
     * Test getString returns default when key not exists
     *
     * @return void
     */
    public function test_getString_returns_default_when_key_not_exists(): void
    {
        $this->assertSame('', ExpireOptions::getString('nonexistent'));
        $this->assertSame('fallback', ExpireOptions::getString('nonexistent', 'fallback'));
    }

    /**
     * Test getString returns default for non string value
     *
     * @return void
     */
    public function test_getString_returns_default_for_non_string_value(): void
    {
        ExpireOptions::set('int_key', 42);

        $this->resetCache();

        $this->assertSame('default', ExpireOptions::getString('int_key', 'default'));
    }

    /**
     * Test getInt returns stored integer
     *
     * @return void
     */
    public function test_getInt_returns_stored_integer(): void
    {
        ExpireOptions::set('count', 42);

        $this->assertSame(42, ExpireOptions::getInt('count'));
    }

    /**
     * Test getInt returns default when key not exists
     *
     * @return void
     */
    public function test_getInt_returns_default_when_key_not_exists(): void
    {
        $this->assertSame(0, ExpireOptions::getInt('nonexistent'));
        $this->assertSame(-1, ExpireOptions::getInt('nonexistent', -1));
    }

    /**
     * Test getInt converts numeric string
     *
     * @return void
     */
    public function test_getInt_converts_numeric_string(): void
    {
        ExpireOptions::set('num_str', '99');

        $this->resetCache();

        $this->assertSame(99, ExpireOptions::getInt('num_str'));
    }

    /**
     * Test getBool returns stored boolean
     *
     * @return void
     */
    public function test_getBool_returns_stored_boolean(): void
    {
        ExpireOptions::set('flag', true);

        $this->assertTrue(ExpireOptions::getBool('flag'));
    }

    /**
     * Test getBool returns default when key not exists
     *
     * @return void
     */
    public function test_getBool_returns_default_when_key_not_exists(): void
    {
        $this->assertFalse(ExpireOptions::getBool('nonexistent'));
        $this->assertTrue(ExpireOptions::getBool('nonexistent', true));
    }

    /**
     * Test expired option returns null and is deleted
     *
     * @return void
     */
    public function test_expired_option_returns_null_and_is_deleted(): void
    {
        ExpireOptions::set('expiring', 'value', 1);

        $optionName = ExpireOptions::OPTION_PREFIX . 'expiring';
        $stored     = json_decode((string) get_option($optionName), true);

        $this->assertIsArray($stored);

        $stored['expire'] = time() - 100;
        update_option($optionName, (string) wp_json_encode($stored));

        $this->resetCache();

        $this->assertSame('', ExpireOptions::getString('expiring'));
    }

    /**
     * Test non expired option returns value
     *
     * @return void
     */
    public function test_non_expired_option_returns_value(): void
    {
        ExpireOptions::set('valid', 'still_here', 3600);

        $this->resetCache();

        $this->assertSame('still_here', ExpireOptions::getString('valid'));
    }

    /**
     * Test option without expiration never expires
     *
     * @return void
     */
    public function test_option_without_expiration_never_expires(): void
    {
        ExpireOptions::set('permanent', 'forever', 0);

        $this->resetCache();

        $this->assertSame('forever', ExpireOptions::getString('permanent'));
    }

    /**
     * Test getExpireTime returns negative for nonexistent key
     *
     * @return void
     */
    public function test_getExpireTime_returns_negative_for_nonexistent_key(): void
    {
        $this->assertSame(-1, ExpireOptions::getExpireTime('nonexistent'));
    }

    /**
     * Test getExpireTime returns zero for no expiration
     *
     * @return void
     */
    public function test_getExpireTime_returns_zero_for_no_expiration(): void
    {
        ExpireOptions::set('permanent', 'value', 0);

        $this->assertSame(0, ExpireOptions::getExpireTime('permanent'));
    }

    /**
     * Test getExpireTime returns future timestamp
     *
     * @return void
     */
    public function test_getExpireTime_returns_future_timestamp(): void
    {
        $before = time() + 3600;
        ExpireOptions::set('timed', 'value', 3600);
        $after = time() + 3600;

        $expire = ExpireOptions::getExpireTime('timed');

        $this->assertGreaterThanOrEqual($before, $expire);
        $this->assertLessThanOrEqual($after, $expire);
    }

    /**
     * Test delete removes option
     *
     * @return void
     */
    public function test_delete_removes_option(): void
    {
        ExpireOptions::set('to_delete', 'value');

        $this->assertTrue(ExpireOptions::delete('to_delete'));
        $this->assertSame('', ExpireOptions::getString('to_delete'));
    }

    /**
     * Test delete returns false for nonexistent key
     *
     * @return void
     */
    public function test_delete_returns_false_for_nonexistent_key(): void
    {
        $this->assertFalse(ExpireOptions::delete('never_existed'));
    }

    /**
     * Test deleteAll removes all options
     *
     * @return void
     */
    public function test_deleteAll_removes_all_options(): void
    {
        ExpireOptions::set('key1', 'val1');
        ExpireOptions::set('key2', 'val2');
        ExpireOptions::set('key3', 'val3');

        ExpireOptions::deleteAll();

        $this->assertSame('', ExpireOptions::getString('key1'));
        $this->assertSame('', ExpireOptions::getString('key2'));
        $this->assertSame('', ExpireOptions::getString('key3'));
    }

    /**
     * Test getUpdateString returns stored value when not expired
     *
     * @return void
     */
    public function test_getUpdateString_returns_stored_value_when_not_expired(): void
    {
        ExpireOptions::set('cached', 'original', 3600);

        $this->resetCache();

        $result = ExpireOptions::getUpdateString('cached', 'new_value', 3600);

        $this->assertSame('original', $result);
    }

    /**
     * Test getUpdateString sets new value when expired
     *
     * @return void
     */
    public function test_getUpdateString_sets_new_value_when_expired(): void
    {
        ExpireOptions::set('cached', 'old', 1);

        $optionName = ExpireOptions::OPTION_PREFIX . 'cached';
        $stored     = json_decode((string) get_option($optionName), true);

        $this->assertIsArray($stored);

        $stored['expire'] = time() - 100;
        update_option($optionName, (string) wp_json_encode($stored));

        $this->resetCache();

        $result = ExpireOptions::getUpdateString('cached', 'refreshed', 3600);

        $this->assertSame('', $result);

        $this->resetCache();

        $this->assertSame('refreshed', ExpireOptions::getString('cached'));
    }

    /**
     * Test getUpdateInt returns stored value when not expired
     *
     * @return void
     */
    public function test_getUpdateInt_returns_stored_value_when_not_expired(): void
    {
        ExpireOptions::set('counter', 10, 3600);

        $this->resetCache();

        $this->assertSame(10, ExpireOptions::getUpdateInt('counter', 99, 3600));
    }

    /**
     * Test getUpdateBool returns stored value when not expired
     *
     * @return void
     */
    public function test_getUpdateBool_returns_stored_value_when_not_expired(): void
    {
        ExpireOptions::set('toggle', true, 3600);

        $this->resetCache();

        $this->assertTrue(ExpireOptions::getUpdateBool('toggle', false, 3600));
    }

    /**
     * Test set overwrites existing value
     *
     * @return void
     */
    public function test_set_overwrites_existing_value(): void
    {
        ExpireOptions::set('overwrite', 'first');
        ExpireOptions::set('overwrite', 'second');

        $this->assertSame('second', ExpireOptions::getString('overwrite'));
    }

    /**
     * Reset the internal static cache via reflection
     *
     * @return void
     */
    private function resetCache(): void
    {
        $reflection = new \ReflectionClass(ExpireOptions::class);
        $property   = $reflection->getProperty('cacheOptions');
        $property->setAccessible(true);
        $property->setValue(null, []);
    }
}
