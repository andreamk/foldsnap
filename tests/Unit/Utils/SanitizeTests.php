<?php

/**
 * Tests for Sanitize utility class
 *
 * @package FoldSnap\Tests\Unit\Utils
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Utils;

use FoldSnap\Utils\Sanitize;
use PHPUnit\Framework\TestCase;

class SanitizeTests extends TestCase
{
    /**
     * Test str removes control characters
     *
     * @return void
     */
    public function test_str_removes_control_characters(): void
    {
        $input    = "hello\x00\x01\x02\x03world";
        $expected = 'helloworld';

        $this->assertSame($expected, Sanitize::str($input));
    }

    /**
     * Test str preserves newlines by default
     *
     * @return void
     */
    public function test_str_preserves_newlines_by_default(): void
    {
        $input = "line1\nline2\rline3";

        $this->assertSame($input, Sanitize::str($input));
    }

    /**
     * Test str strips newlines with flag
     *
     * @return void
     */
    public function test_str_strips_newlines_with_flag(): void
    {
        $input    = "line1\nline2\rline3\r\n";
        $expected = 'line1line2line3';

        $this->assertSame($expected, Sanitize::str($input, Sanitize::STRIP_NEWLINES));
    }

    /**
     * Test str strips all whitespace with flag
     *
     * @return void
     */
    public function test_str_strips_all_whitespace_with_flag(): void
    {
        $input    = "hello world\ttab\nnew";
        $expected = 'helloworldtabnew';

        $this->assertSame($expected, Sanitize::str($input, Sanitize::STRIP_WHITESPACE));
    }

    /**
     * Test str trims with flag
     *
     * @return void
     */
    public function test_str_trims_with_flag(): void
    {
        $input    = '  hello world  ';
        $expected = 'hello world';

        $this->assertSame($expected, Sanitize::str($input, Sanitize::STRIP_TRIM));
    }

    /**
     * Test str trim also strips newlines
     *
     * @return void
     */
    public function test_str_trim_also_strips_newlines(): void
    {
        $input    = "\n  hello\nworld  \n";
        $expected = 'helloworld';

        $this->assertSame($expected, Sanitize::str($input, Sanitize::STRIP_TRIM));
    }

    /**
     * Test str html escape with flag
     *
     * @return void
     */
    public function test_str_html_escape_with_flag(): void
    {
        $input    = '<script>alert("xss")</script>';
        $expected = '&lt;script&gt;alert(&quot;xss&quot;)&lt;/script&gt;';

        $this->assertSame($expected, Sanitize::str($input, Sanitize::STRIP_HTML_ESCAPE));
    }

    /**
     * Test str combined flags
     *
     * @return void
     */
    public function test_str_combined_flags(): void
    {
        $input    = "  <b>hello\nworld</b>  ";
        $expected = '&lt;b&gt;helloworld&lt;/b&gt;';

        $this->assertSame($expected, Sanitize::str($input, Sanitize::STRIP_TRIM | Sanitize::STRIP_HTML_ESCAPE));
    }

    /**
     * Test str preserves tab and space without flags
     *
     * @return void
     */
    public function test_str_preserves_tab_and_space_without_flags(): void
    {
        $input = "hello\tworld test";

        $this->assertSame($input, Sanitize::str($input));
    }

    /**
     * Test str removes delete character
     *
     * @return void
     */
    public function test_str_removes_delete_character(): void
    {
        $input    = "hello\x7Fworld";
        $expected = 'helloworld';

        $this->assertSame($expected, Sanitize::str($input));
    }

    /**
     * Test str removes c1 control characters
     *
     * @return void
     */
    public function test_str_removes_c1_control_characters(): void
    {
        $input    = "hello\xC2\x80\xC2\x9Fworld";
        $expected = 'helloworld';

        $this->assertSame($expected, Sanitize::str($input));
    }

    /**
     * Test str returns empty string for empty input
     *
     * @return void
     */
    public function test_str_returns_empty_string_for_empty_input(): void
    {
        $this->assertSame('', Sanitize::str(''));
    }

    /**
     * Test strictStr keeps only alphanumeric and spaces
     *
     * @return void
     */
    public function test_strictStr_keeps_only_alphanumeric_and_spaces(): void
    {
        $input    = 'Hello World 123!@#';
        $expected = 'Hello World 123';

        $this->assertSame($expected, Sanitize::strictStr($input));
    }

    /**
     * Test strictStr with extra accepted chars
     *
     * @return void
     */
    public function test_strictStr_with_extra_accepted_chars(): void
    {
        $input    = 'file-name_v2.txt';
        $expected = 'file-name_v2.txt';

        $this->assertSame($expected, Sanitize::strictStr($input, '-_.'));
    }

    /**
     * Test strictStr removes all special characters
     *
     * @return void
     */
    public function test_strictStr_removes_all_special_characters(): void
    {
        $input    = '!@#$%^&*()';
        $expected = '';

        $this->assertSame($expected, Sanitize::strictStr($input));
    }

    /**
     * Test strictStr returns empty for empty input
     *
     * @return void
     */
    public function test_strictStr_returns_empty_for_empty_input(): void
    {
        $this->assertSame('', Sanitize::strictStr(''));
    }

    /**
     * Test strictArray sanitizes each element
     *
     * @return void
     */
    public function test_strictArray_sanitizes_each_element(): void
    {
        $input    = [
            'hello!',
            'world@',
            'test#123',
        ];
        $expected = [
            'hello',
            'world',
            'test123',
        ];

        $this->assertSame($expected, Sanitize::strictArray($input));
    }

    /**
     * Test strictArray with extra accepted chars
     *
     * @return void
     */
    public function test_strictArray_with_extra_accepted_chars(): void
    {
        $input    = [
            'file-1.txt',
            'dir/path',
        ];
        $expected = [
            'file-1.txt',
            'dirpath',
        ];

        $this->assertSame($expected, Sanitize::strictArray($input, '-.'));
    }

    /**
     * Test strictArray flattens nested arrays
     *
     * @return void
     */
    public function test_strictArray_flattens_nested_arrays(): void
    {
        $input    = [
            'key' => [
                'nested!',
                'values@',
            ],
        ];
        $expected = ['key' => 'nestedvalues'];

        $this->assertSame($expected, Sanitize::strictArray($input));
    }

    /**
     * Test strictArray preserves keys
     *
     * @return void
     */
    public function test_strictArray_preserves_keys(): void
    {
        $input    = [
            'name' => 'test!',
            'id'   => '123#',
        ];
        $expected = [
            'name' => 'test',
            'id'   => '123',
        ];

        $this->assertSame($expected, Sanitize::strictArray($input));
    }

    /**
     * Test strictArray handles non scalar values
     *
     * @return void
     */
    public function test_strictArray_handles_non_scalar_values(): void
    {
        $input    = ['key' => null];
        $expected = ['key' => ''];

        $this->assertSame($expected, Sanitize::strictArray($input));
    }

    /**
     * Test strictArray returns empty for empty input
     *
     * @return void
     */
    public function test_strictArray_returns_empty_for_empty_input(): void
    {
        $this->assertSame([], Sanitize::strictArray([]));
    }

    /**
     * Test toInt converts valid integer string
     *
     * @return void
     */
    public function test_toInt_converts_valid_integer_string(): void
    {
        $this->assertSame(42, Sanitize::toInt('42'));
    }

    /**
     * Test toInt returns default for non numeric string
     *
     * @return void
     */
    public function test_toInt_returns_default_for_non_numeric_string(): void
    {
        $this->assertSame(0, Sanitize::toInt('abc'));
    }

    /**
     * Test toInt returns custom default for invalid input
     *
     * @return void
     */
    public function test_toInt_returns_custom_default_for_invalid_input(): void
    {
        $this->assertSame(-1, Sanitize::toInt('abc', -1));
    }

    /**
     * Test toInt handles negative numbers
     *
     * @return void
     */
    public function test_toInt_handles_negative_numbers(): void
    {
        $this->assertSame(-5, Sanitize::toInt('-5'));
    }

    /**
     * Test toInt returns default for float string
     *
     * @return void
     */
    public function test_toInt_returns_default_for_float_string(): void
    {
        $this->assertSame(0, Sanitize::toInt('3.14'));
    }

    /**
     * Test toInt handles zero
     *
     * @return void
     */
    public function test_toInt_handles_zero(): void
    {
        $this->assertSame(0, Sanitize::toInt('0'));
    }

    /**
     * Test toInt returns default for empty string
     *
     * @return void
     */
    public function test_toInt_returns_default_for_empty_string(): void
    {
        $this->assertSame(0, Sanitize::toInt(''));
    }

    /**
     * Test toBool returns true for true values
     *
     * @return void
     */
    public function test_toBool_returns_true_for_true_values(): void
    {
        $this->assertTrue(Sanitize::toBool('true'));
        $this->assertTrue(Sanitize::toBool('1'));
        $this->assertTrue(Sanitize::toBool('yes'));
        $this->assertTrue(Sanitize::toBool('on'));
    }

    /**
     * Test toBool returns false for false values
     *
     * @return void
     */
    public function test_toBool_returns_false_for_false_values(): void
    {
        $this->assertFalse(Sanitize::toBool('false'));
        $this->assertFalse(Sanitize::toBool('0'));
        $this->assertFalse(Sanitize::toBool('no'));
        $this->assertFalse(Sanitize::toBool('off'));
    }

    /**
     * Test toBool returns false for arbitrary strings
     *
     * @return void
     */
    public function test_toBool_returns_false_for_arbitrary_strings(): void
    {
        $this->assertFalse(Sanitize::toBool('hello'));
        $this->assertFalse(Sanitize::toBool(''));
    }
}
