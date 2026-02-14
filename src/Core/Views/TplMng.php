<?php

/**
 * Template view manager
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Core\Views;

use FoldSnap\Core\Controllers\PageAction;
use Exception;

final class TplMng
{
    /** @var ?self */
    private static ?self $instance = null;
    private string $mainFolder;
    private static bool $stripSpaces = false;
    /** @var array<string, mixed> */
    private array $globalData = [];
    /** @var ?array<string, mixed> */
    private ?array $renderData = null;

    /**
     * Return singleton instance
     *
     * @return self
     */
    public static function getInstance(): self
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    /**
     * Class constructor
     */
    private function __construct()
    {
        $this->mainFolder = FOLDSNAP_PATH . '/template/';
    }

    /**
     * If strip spaces is true in render method spaces between tags are removed
     *
     * @param bool $strip if true strip spaces
     *
     * @return void
     */
    public static function setStripSpaces(bool $strip): void
    {
        self::$stripSpaces = (bool) $strip;
    }

    /**
     * Set template global value in template data
     *
     * @param string $key global value key
     * @param mixed  $val value
     *
     * @return void
     */
    public function setGlobalValue(string $key, $val): void
    {
        $this->globalData[$key] = $val;
    }

    /**
     * Remove global value if exist
     *
     * @param string $key global value key
     *
     * @return void
     */
    public function unsetGlobalValue(string $key): void
    {
        if (isset($this->globalData[$key])) {
            unset($this->globalData[$key]);
        }
    }

    /**
     * Return true if global values exists
     *
     * @param string $key global value key
     *
     * @return bool
     */
    public function hasGlobalValue(string $key): bool
    {
        return isset($this->globalData[$key]);
    }

    /**
     * Multiple global data set
     *
     * @param array<string, mixed> $data data to set in global data
     *
     * @return void
     */
    public function updateGlobalData(array $data = []): void
    {
        $this->globalData = array_merge($this->globalData, (array) $data);
    }

    /**
     * Return global data
     *
     * @return array<string, mixed>
     */
    public function getGlobalData(): array
    {
        return $this->globalData;
    }

    /**
     * Return global value
     *
     * @param string $key     global value key
     * @param mixed  $default default value if global value not exists
     *
     * @return mixed
     */
    public function getGlobalValue(string $key, $default = null)
    {
        return $this->globalData[$key] ?? $default;
    }

    /**
     * Render template
     *
     * @param string               $slugTpl template file is a relative path from root template folder
     * @param array<string, mixed> $args    array key / val where key is the var name in template
     * @param bool                 $echo    if false return template in string
     *
     * @return string
     */
    public function render(string $slugTpl, array $args = [], bool $echo = true): string
    {
        ob_start();
        if (($renderFile = $this->getFileTemplate($slugTpl)) !== false) {
            $origRenderData = $this->renderData;
            if (is_null($this->renderData)) {
                $this->renderData = array_merge($this->globalData, $args);
            } else {
                $this->renderData = array_merge($this->renderData, $args);
            }

            /** @var array<string, mixed> $filteredData */
            $filteredData     = apply_filters(self::getDataHook($slugTpl), $this->renderData);
            $this->renderData = $filteredData;

            $tplMng = $this;
            require($renderFile);
            $this->renderData = $origRenderData;
        } else {
            echo '<p>FILE TPL NOT FOUND: ' . esc_html($slugTpl) . '</p>';
        }

        $obContent = ob_get_clean();
        /** @var string $renderResult */
        $renderResult = apply_filters(self::getRenderHook($slugTpl), is_string($obContent) ? $obContent : '');

        if (self::$stripSpaces) {
            $renderResult = (string) preg_replace('~>[\n\s]+<~', '><', $renderResult);
        }
        if ($echo) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Template output, already processed
            echo $renderResult;
            return '';
        } else {
            return $renderResult;
        }
    }

    /**
     * Check if action is set on current render data
     *
     * @param string $key action key
     *
     * @return bool
     */
    public function actionExists(string $key): bool
    {
        if (!is_array($this->renderData) || !isset($this->renderData['actions'])) {
            return false;
        }

        /** @var array<string, PageAction> $actions */
        $actions = $this->renderData['actions'];
        return isset($actions[$key]);
    }

    /**
     * Get action by key if exists or throw exception if not exists
     *
     * @param string $key action key
     *
     * @return PageAction
     */
    public function getAction(string $key): PageAction
    {
        if (!$this->actionExists($key)) {
            throw new Exception('Action ' . esc_html($key) . ' not found');
        }

        /** @var array<string, PageAction> $actions */
        $actions = $this->renderData['actions'] ?? [];
        return $actions[$key];
    }

    /**
     * Render data exists
     *
     * @param string $key render data key
     *
     * @return bool
     */
    public function dataValueExists(string $key): bool
    {
        return is_array($this->renderData) && isset($this->renderData[$key]);
    }

    /**
     * Get render data int value
     *
     * @param string $key     render data key
     * @param int    $default default value if key not exists
     *
     * @return int
     */
    public function getDataValueInt(string $key, int $default = 0): int
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            return $default;
        }

        return is_numeric($this->renderData[$key]) ? (int) $this->renderData[$key] : $default;
    }

    /**
     * Get render data string value
     *
     * @param string $key     render data key
     * @param string $default default value if key not exists
     *
     * @return string
     */
    public function getDataValueString(string $key, string $default = ''): string
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            return $default;
        }

        return is_scalar($this->renderData[$key]) ? (string) $this->renderData[$key] : $default;
    }

    /**
     * Get render data bool value
     *
     * @param string $key     render data key
     * @param bool   $default default value if key not exists
     *
     * @return bool
     */
    public function getDataValueBool(string $key, bool $default = false): bool
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            return $default;
        }

        return is_scalar($this->renderData[$key]) ? (bool) $this->renderData[$key] : $default;
    }

    /**
     * Get render data array value
     *
     * @param string             $key     render data key
     * @param array<mixed,mixed> $default default value if key not exists
     *
     * @return array<mixed,mixed>
     */
    public function getDataValueArray(string $key, array $default = []): array
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            return $default;
        }

        return is_array($this->renderData[$key]) ? $this->renderData[$key] : $default;
    }

    /**
     * Get render data float value
     *
     * @param string $key     render data key
     * @param float  $default default value if key not exists
     *
     * @return float
     */
    public function getDataValueFloat(string $key, float $default = 0.0): float
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            return $default;
        }

        return is_numeric($this->renderData[$key]) ? (float) $this->renderData[$key] : $default;
    }

    /**
     * Get render data object class
     *
     * @template T of object
     *
     * @param string          $key     render data key
     * @param class-string<T> $class   class name
     * @param ?T              $default default value if key not exists
     *
     * @return ($default is null ? ?T : T)
     */
    public function getDataValueObj(string $key, string $class, ?object $default = null): ?object
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            return $default;
        }

        $value = $this->renderData[$key];
        return is_object($value) && is_a($value, $class) ? $value : $default;
    }

    /**
     * Get render data object class, the object is required or throw exception if not exists
     *
     * @template T of object
     *
     * @param string          $key   render data key
     * @param class-string<T> $class class name
     *
     * @return T
     */
    public function getDataValueObjRequired(string $key, string $class): object
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            throw new Exception('Object ' . esc_html($key) . ' not found');
        }

        $value = $this->renderData[$key];
        if (!is_object($value) || !is_a($value, $class)) {
            throw new Exception('Object ' . esc_html($key) . ' is not an instance of ' . esc_html($class) . ' or its child classes');
        }

        return $value;
    }

    /**
     * Get render data int value, the value is required or throw exception if not exists
     *
     * @param string $key render data key
     *
     * @return int
     */
    public function getDataValueIntRequired(string $key): int
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            throw new Exception('Integer value ' . esc_html($key) . ' not found');
        }

        if (!is_numeric($this->renderData[$key])) {
            throw new Exception('Value ' . esc_html($key) . ' is not a valid integer');
        }

        return (int) $this->renderData[$key];
    }

    /**
     * Get render data string value, the value is required or throw exception if not exists
     *
     * @param string $key render data key
     *
     * @return string
     */
    public function getDataValueStringRequired(string $key): string
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            throw new Exception('String value ' . esc_html($key) . ' not found');
        }

        return is_scalar($this->renderData[$key]) ? (string) $this->renderData[$key] : '';
    }

    /**
     * Get render data bool value, the value is required or throw exception if not exists
     *
     * @param string $key render data key
     *
     * @return bool
     */
    public function getDataValueBoolRequired(string $key): bool
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            throw new Exception('Boolean value ' . esc_html($key) . ' not found');
        }

        return is_scalar($this->renderData[$key]) ? (bool) $this->renderData[$key] : false;
    }

    /**
     * Get render data array value, the value is required or throw exception if not exists
     *
     * @param string $key render data key
     *
     * @return array<mixed,mixed>
     */
    public function getDataValueArrayRequired(string $key): array
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            throw new Exception('Array value ' . esc_html($key) . ' not found');
        }

        if (!is_array($this->renderData[$key])) {
            throw new Exception('Value ' . esc_html($key) . ' is not an array');
        }

        return $this->renderData[$key];
    }

    /**
     * Get render data float value, the value is required or throw exception if not exists
     *
     * @param string $key render data key
     *
     * @return float
     */
    public function getDataValueFloatRequired(string $key): float
    {
        if (!is_array($this->renderData) || !isset($this->renderData[$key])) {
            throw new Exception('Float value ' . esc_html($key) . ' not found');
        }

        if (!is_numeric($this->renderData[$key])) {
            throw new Exception('Value ' . esc_html($key) . ' is not a valid float');
        }

        return (float) $this->renderData[$key];
    }

    /**
     * Render template in json string
     *
     * @param string              $slugTpl template file is a relative path from root template folder
     * @param array<string,mixed> $args    array key / val where key is the var name in template
     * @param bool                $echo    if false return template in string
     *
     * @return string
     */
    public function renderJson(string $slugTpl, array $args = [], bool $echo = true): string
    {
        $renderResult = wp_json_encode($this->render($slugTpl, $args, false));
        if (false === $renderResult) {
            $renderResult = '';
        }
        if ($echo) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded template output
            echo $renderResult;
            return '';
        } else {
            return $renderResult;
        }
    }

    /**
     * Render template apply esc attr
     *
     * @param string               $slugTpl template file is a relative path from root template folder
     * @param array<string, mixed> $args    array key / val where key is the var name in template
     * @param bool                 $echo    if false return template in string
     *
     * @return string
     */
    public function renderEscAttr(string $slugTpl, array $args = [], bool $echo = true): string
    {
        $renderResult = esc_attr($this->render($slugTpl, $args, false));
        if ($echo) {
            // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Already escaped via esc_attr
            echo $renderResult;
            return '';
        } else {
            return $renderResult;
        }
    }

    /**
     * Get hook unique from template slug
     *
     * @param string $slugTpl template slug
     *
     * @return string
     */
    public static function tplFileToHookSlug(string $slugTpl): string
    {
        return str_replace(['\\', '/', '.'], '_', $slugTpl);
    }

    /**
     * Return data hook from template slug
     *
     * @param string $slugTpl template slug
     *
     * @return non-empty-string
     */
    public static function getDataHook(string $slugTpl): string
    {
        return 'foldsnap_template_data_' . self::tplFileToHookSlug($slugTpl);
    }

    /**
     * Return render hook from template slug
     *
     * @param string $slugTpl template slug
     *
     * @return non-empty-string
     */
    public static function getRenderHook(string $slugTpl): string
    {
        return 'foldsnap_template_render_' . self::tplFileToHookSlug($slugTpl);
    }

    /**
     * Accept html or php extensions. If the file has unknown extension automatically add the php extension
     *
     * @param string $slugTpl template slug
     *
     * @return false|string return false if don't find the template file
     */
    protected function getFileTemplate(string $slugTpl)
    {
        /** @var string $fullPath */
        $fullPath = apply_filters('foldsnap_template_file', $this->mainFolder . $slugTpl . '.php', $slugTpl);

        if (file_exists($fullPath)) {
            return $fullPath;
        } else {
            return false;
        }
    }

    /**
     * Get input name
     *
     * @param string $field    field name
     * @param string $subInxed sub index
     *
     * @return string
     */
    public static function getInputName(string $field, string $subInxed = ''): string
    {
        return 'foldsnap_input_' . $field . (strlen($subInxed) ? '_' . $subInxed : '');
    }

    /**
     * Get input id
     *
     * @param string $field    field name
     * @param string $subInxed sub index
     *
     * @return string
     */
    public static function getInputId(string $field, string $subInxed = ''): string
    {
        return self::getInputName($field, $subInxed);
    }
}
