<?php

/**
 * Tests for Sanitize::hexColor() method
 *
 * @package FoldSnap\Tests\Feature\Utils
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Feature\Utils;

use FoldSnap\Utils\Sanitize;
use InvalidArgumentException;
use WP_UnitTestCase;

class SanitizeHexColorTests extends WP_UnitTestCase
{
    /**
     * Test hexColor accepts valid 6-digit hex color
     *
     * @return void
     */
    public function test_hex_color_accepts_six_digit_color(): void
    {
        $this->assertSame('#ff0000', Sanitize::hexColor('#ff0000'));
        $this->assertSame('#00FF00', Sanitize::hexColor('#00FF00'));
        $this->assertSame('#0000ff', Sanitize::hexColor('#0000ff'));
    }

    /**
     * Test hexColor accepts valid 3-digit hex color
     *
     * @return void
     */
    public function test_hex_color_accepts_three_digit_color(): void
    {
        $this->assertSame('#fff', Sanitize::hexColor('#fff'));
        $this->assertSame('#ABC', Sanitize::hexColor('#ABC'));
    }

    /**
     * Test hexColor throws on missing hash prefix
     *
     * @return void
     */
    public function test_hex_color_throws_on_missing_hash(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sanitize::hexColor('ff0000');
    }

    /**
     * Test hexColor throws on invalid characters
     *
     * @return void
     */
    public function test_hex_color_throws_on_invalid_characters(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sanitize::hexColor('#gggggg');
    }

    /**
     * Test hexColor throws on empty string
     *
     * @return void
     */
    public function test_hex_color_throws_on_empty_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sanitize::hexColor('');
    }

    /**
     * Test hexColor throws on arbitrary string
     *
     * @return void
     */
    public function test_hex_color_throws_on_arbitrary_string(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sanitize::hexColor('not-a-color');
    }

    /**
     * Test hexColor throws on wrong length
     *
     * @return void
     */
    public function test_hex_color_throws_on_wrong_length(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Sanitize::hexColor('#abcd');
    }
}
