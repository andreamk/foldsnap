<?php

/**
 * Tests for TplMng template manager
 *
 * @package FoldSnap\Tests\Unit\Core\Views
 */

declare(strict_types=1);

namespace FoldSnap\Tests\Unit\Core\Views;

use FoldSnap\Core\Views\TplMng;
use WP_UnitTestCase;

class TplMngTests extends WP_UnitTestCase
{
    private TplMng $tplMng;

    /**
     * Set up test environment
     *
     * @return void
     */
    public function setUp(): void
    {
        parent::setUp();
        $this->tplMng = TplMng::getInstance();
        $this->resetGlobalData();
        $this->resetRenderData();
        TplMng::setStripSpaces(false);
    }

    /**
     * Tear down test environment
     *
     * @return void
     */
    public function tearDown(): void
    {
        $this->resetGlobalData();
        $this->resetRenderData();
        TplMng::setStripSpaces(false);
        parent::tearDown();
    }

    /**
     * Test getInstance returns same instance
     *
     * @return void
     */
    public function test_getInstance_returns_same_instance(): void
    {
        $this->assertSame(TplMng::getInstance(), TplMng::getInstance());
    }

    /**
     * Test setGlobalValue and getGlobalValue
     *
     * @return void
     */
    public function test_setGlobalValue_and_getGlobalValue(): void
    {
        $this->tplMng->setGlobalValue('key', 'value');

        $this->assertSame('value', $this->tplMng->getGlobalValue('key'));
    }

    /**
     * Test getGlobalValue returns default when not set
     *
     * @return void
     */
    public function test_getGlobalValue_returns_default_when_not_set(): void
    {
        $this->assertNull($this->tplMng->getGlobalValue('missing'));
        $this->assertSame('fallback', $this->tplMng->getGlobalValue('missing', 'fallback'));
    }

    /**
     * Test hasGlobalValue
     *
     * @return void
     */
    public function test_hasGlobalValue(): void
    {
        $this->assertFalse($this->tplMng->hasGlobalValue('key'));

        $this->tplMng->setGlobalValue('key', 'val');

        $this->assertTrue($this->tplMng->hasGlobalValue('key'));
    }

    /**
     * Test unsetGlobalValue removes key
     *
     * @return void
     */
    public function test_unsetGlobalValue_removes_key(): void
    {
        $this->tplMng->setGlobalValue('key', 'val');
        $this->tplMng->unsetGlobalValue('key');

        $this->assertFalse($this->tplMng->hasGlobalValue('key'));
    }

    /**
     * Test unsetGlobalValue does nothing for missing key
     *
     * @return void
     */
    public function test_unsetGlobalValue_does_nothing_for_missing_key(): void
    {
        $this->tplMng->unsetGlobalValue('nonexistent');

        $this->assertFalse($this->tplMng->hasGlobalValue('nonexistent'));
    }

    /**
     * Test updateGlobalData merges data
     *
     * @return void
     */
    public function test_updateGlobalData_merges_data(): void
    {
        $this->tplMng->setGlobalValue('existing', 'old');
        $this->tplMng->updateGlobalData(['existing' => 'new', 'added' => 'yes']);

        $this->assertSame('new', $this->tplMng->getGlobalValue('existing'));
        $this->assertSame('yes', $this->tplMng->getGlobalValue('added'));
    }

    /**
     * Test getGlobalData returns all
     *
     * @return void
     */
    public function test_getGlobalData_returns_all(): void
    {
        $this->tplMng->setGlobalValue('a', 1);
        $this->tplMng->setGlobalValue('b', 2);

        $data = $this->tplMng->getGlobalData();

        $this->assertSame(1, $data['a']);
        $this->assertSame(2, $data['b']);
    }

    /**
     * Test tplFileToHookSlug converts separators
     *
     * @return void
     */
    public function test_tplFileToHookSlug_converts_separators(): void
    {
        $this->assertSame('path_to_template_php', TplMng::tplFileToHookSlug('path/to/template.php'));
        $this->assertSame('back_slash_path', TplMng::tplFileToHookSlug('back\\slash\\path'));
    }

    /**
     * Test getDataHook returns prefixed slug
     *
     * @return void
     */
    public function test_getDataHook_returns_prefixed_slug(): void
    {
        $hook = TplMng::getDataHook('page/header');

        $this->assertSame('foldsnap_template_data_page_header', $hook);
    }

    /**
     * Test getRenderHook returns prefixed slug
     *
     * @return void
     */
    public function test_getRenderHook_returns_prefixed_slug(): void
    {
        $hook = TplMng::getRenderHook('page/header');

        $this->assertSame('foldsnap_template_render_page_header', $hook);
    }

    /**
     * Test getInputName without subindex
     *
     * @return void
     */
    public function test_getInputName_without_subindex(): void
    {
        $this->assertSame('foldsnap_input_myfield', TplMng::getInputName('myfield'));
    }

    /**
     * Test getInputName with subindex
     *
     * @return void
     */
    public function test_getInputName_with_subindex(): void
    {
        $this->assertSame('foldsnap_input_myfield_sub', TplMng::getInputName('myfield', 'sub'));
    }

    /**
     * Test getInputId matches getInputName
     *
     * @return void
     */
    public function test_getInputId_matches_getInputName(): void
    {
        $this->assertSame(
            TplMng::getInputName('field', 'idx'),
            TplMng::getInputId('field', 'idx')
        );
    }

    /**
     * Test render returns template content when echo false
     *
     * @return void
     */
    public function test_render_returns_template_content_when_echo_false(): void
    {
        $tplDir  = FOLDSNAP_PATH . '/template/';
        $tplFile = $tplDir . '_test_unit.php';

        if (!is_dir($tplDir)) {
            mkdir($tplDir, 0777, true);
        }

        file_put_contents($tplFile, '<div>hello</div>');

        try {
            $result = $this->tplMng->render('_test_unit', [], false);

            $this->assertSame('<div>hello</div>', $result);
        } finally {
            @unlink($tplFile);
        }
    }

    /**
     * Test render strips spaces between tags when enabled
     *
     * @return void
     */
    public function test_render_strips_spaces_between_tags_when_enabled(): void
    {
        $tplDir  = FOLDSNAP_PATH . '/template/';
        $tplFile = $tplDir . '_test_strip.php';

        if (!is_dir($tplDir)) {
            mkdir($tplDir, 0777, true);
        }

        file_put_contents($tplFile, "<div>  \n  </div>  \n  <span>ok</span>");

        try {
            TplMng::setStripSpaces(true);
            $result = $this->tplMng->render('_test_strip', [], false);

            $this->assertSame('<div></div><span>ok</span>', $result);
        } finally {
            @unlink($tplFile);
        }
    }

    /**
     * Test render makes args available in template
     *
     * @return void
     */
    public function test_render_makes_args_available_in_template(): void
    {
        $tplDir  = FOLDSNAP_PATH . '/template/';
        $tplFile = $tplDir . '_test_args.php';

        if (!is_dir($tplDir)) {
            mkdir($tplDir, 0777, true);
        }

        file_put_contents($tplFile, '<?php echo esc_html($tplMng->getDataValueString("name")); ?>');

        try {
            $result = $this->tplMng->render('_test_args', ['name' => 'World'], false);

            $this->assertSame('World', $result);
        } finally {
            @unlink($tplFile);
        }
    }

    /**
     * Test render shows error for missing template
     *
     * @return void
     */
    public function test_render_shows_error_for_missing_template(): void
    {
        $result = $this->tplMng->render('nonexistent_template_xyz', [], false);

        $this->assertStringContainsString('FILE TPL NOT FOUND', $result);
    }

    /**
     * Test getDataValueInt returns default without render context
     *
     * @return void
     */
    public function test_getDataValueInt_returns_default_without_render_context(): void
    {
        $this->assertSame(0, $this->tplMng->getDataValueInt('missing'));
        $this->assertSame(42, $this->tplMng->getDataValueInt('missing', 42));
    }

    /**
     * Test getDataValueString returns default without render context
     *
     * @return void
     */
    public function test_getDataValueString_returns_default_without_render_context(): void
    {
        $this->assertSame('', $this->tplMng->getDataValueString('missing'));
        $this->assertSame('def', $this->tplMng->getDataValueString('missing', 'def'));
    }

    /**
     * Test getDataValueBool returns default without render context
     *
     * @return void
     */
    public function test_getDataValueBool_returns_default_without_render_context(): void
    {
        $this->assertFalse($this->tplMng->getDataValueBool('missing'));
        $this->assertTrue($this->tplMng->getDataValueBool('missing', true));
    }

    /**
     * Test getDataValueArray returns default without render context
     *
     * @return void
     */
    public function test_getDataValueArray_returns_default_without_render_context(): void
    {
        $this->assertSame([], $this->tplMng->getDataValueArray('missing'));
    }

    /**
     * Test getDataValueFloat returns default without render context
     *
     * @return void
     */
    public function test_getDataValueFloat_returns_default_without_render_context(): void
    {
        $this->assertSame(0.0, $this->tplMng->getDataValueFloat('missing'));
    }

    /**
     * Test dataValueExists returns false without render context
     *
     * @return void
     */
    public function test_dataValueExists_returns_false_without_render_context(): void
    {
        $this->assertFalse($this->tplMng->dataValueExists('anything'));
    }

    /**
     * Test getDataValueIntRequired throws when missing
     *
     * @return void
     */
    public function test_getDataValueIntRequired_throws_when_missing(): void
    {
        $this->expectException(\Exception::class);

        $this->tplMng->getDataValueIntRequired('missing');
    }

    /**
     * Test getDataValueStringRequired throws when missing
     *
     * @return void
     */
    public function test_getDataValueStringRequired_throws_when_missing(): void
    {
        $this->expectException(\Exception::class);

        $this->tplMng->getDataValueStringRequired('missing');
    }

    /**
     * Test getDataValueBoolRequired throws when missing
     *
     * @return void
     */
    public function test_getDataValueBoolRequired_throws_when_missing(): void
    {
        $this->expectException(\Exception::class);

        $this->tplMng->getDataValueBoolRequired('missing');
    }

    /**
     * Test getDataValueArrayRequired throws when missing
     *
     * @return void
     */
    public function test_getDataValueArrayRequired_throws_when_missing(): void
    {
        $this->expectException(\Exception::class);

        $this->tplMng->getDataValueArrayRequired('missing');
    }

    /**
     * Test getDataValueFloatRequired throws when missing
     *
     * @return void
     */
    public function test_getDataValueFloatRequired_throws_when_missing(): void
    {
        $this->expectException(\Exception::class);

        $this->tplMng->getDataValueFloatRequired('missing');
    }

    /**
     * Test getDataValueObjRequired throws when missing
     *
     * @return void
     */
    public function test_getDataValueObjRequired_throws_when_missing(): void
    {
        $this->expectException(\Exception::class);

        $this->tplMng->getDataValueObjRequired('missing', \stdClass::class);
    }

    /**
     * Test actionExists returns false without render context
     *
     * @return void
     */
    public function test_actionExists_returns_false_without_render_context(): void
    {
        $this->assertFalse($this->tplMng->actionExists('any_action'));
    }

    /**
     * Test getAction throws when not in render
     *
     * @return void
     */
    public function test_getAction_throws_when_not_in_render(): void
    {
        $this->expectException(\Exception::class);

        $this->tplMng->getAction('any_action');
    }

    /**
     * Test renderJson returns json encoded template
     *
     * @return void
     */
    public function test_renderJson_returns_json_encoded_template(): void
    {
        $tplDir  = FOLDSNAP_PATH . '/template/';
        $tplFile = $tplDir . '_test_json.php';

        if (!is_dir($tplDir)) {
            mkdir($tplDir, 0777, true);
        }

        file_put_contents($tplFile, '<b>test</b>');

        try {
            $result = $this->tplMng->renderJson('_test_json', [], false);

            $this->assertSame('"<b>test<\/b>"', $result);
        } finally {
            @unlink($tplFile);
        }
    }

    /**
     * Test renderEscAttr returns escaped template
     *
     * @return void
     */
    public function test_renderEscAttr_returns_escaped_template(): void
    {
        $tplDir  = FOLDSNAP_PATH . '/template/';
        $tplFile = $tplDir . '_test_escattr.php';

        if (!is_dir($tplDir)) {
            mkdir($tplDir, 0777, true);
        }

        file_put_contents($tplFile, 'value="test"');

        try {
            $result = $this->tplMng->renderEscAttr('_test_escattr', [], false);

            $this->assertSame('value=&quot;test&quot;', $result);
        } finally {
            @unlink($tplFile);
        }
    }

    /**
     * Reset global data via reflection
     *
     * @return void
     */
    private function resetGlobalData(): void
    {
        $ref  = new \ReflectionClass(TplMng::class);
        $prop = $ref->getProperty('globalData');
        $prop->setAccessible(true);
        $prop->setValue($this->tplMng, []);
    }

    /**
     * Reset render data via reflection
     *
     * @return void
     */
    private function resetRenderData(): void
    {
        $ref  = new \ReflectionClass(TplMng::class);
        $prop = $ref->getProperty('renderData');
        $prop->setAccessible(true);
        $prop->setValue($this->tplMng, null);
    }
}
