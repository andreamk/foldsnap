<?php

/**
 * Abstract class that manages a single page in WordPress administration without an entry in the menu.
 * The basic render function doesn't handle anything and all content must be generated in the content, including the wrapper.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Core\Controllers;

use FoldSnap\Core\Views\TplMng;
use FoldSnap\Utils\Sanitize;
use FoldSnap\Utils\SanitizeInput;
use Error;
use Exception;

abstract class AbstractSinglePageController implements ControllerInterface
{
    /** @var static[] */
    private static array $instances = [];
    protected string $pageSlug      = '';
    protected string $pageTitle     = '';
    protected string $capatibility  = '';
    /** @var mixed[] */
    protected array $renderData = [];
    /** @var false|string */
    protected $menuHookSuffix = false;

    /**
     * Return controller instance
     *
     * @return static
     */
    public static function getInstance()
    {
        $class = static::class;
        if (!isset(self::$instances[$class])) {
            self::$instances[$class] = new static();
        }

        return self::$instances[$class];
    }

    /**
     * Class constructor
     */
    abstract protected function __construct();

    /**
     * Method called on WordPress hook init action
     *
     * @return void
     */
    public function hookWpInit(): void
    {
        // empty
    }

    /**
     *
     * @return boolean if is false the controller isn't initialized
     */
    public function isEnabled(): bool
    {
        return true;
    }

    /**
     * Return true if this controller is main page
     *
     * @return boolean
     */
    public function isMainPage(): bool
    {
        return true;
    }

    /**
     * Return menu position
     *
     * @return int
     */
    public function getPosition(): int
    {
        return 0;
    }

    /**
     * Set template global data values
     *
     * @return void
     */
    protected function setTemplateData(): void
    {
        $tplMng = TplMng::getInstance();
        $tplMng->setGlobalValue('pageTitle', $this->pageTitle);
        $tplMng->setGlobalValue('currentLevelSlugs', $this->getCurrentMenuSlugs());
        $tplMng->setGlobalValue('currentInnerPage', static::getCurrentInnerPage());
    }

    /**
     * Execute controller actions
     *
     * @return void
     */
    protected function runActions(): void
    {
        $resultData = [
            'actionsError'   => false,
            'errorMessage'   => '',
            'successMessage' => '',
        ];
        $tplMng     = TplMng::getInstance();

        try {
            do_action('foldsnap_before_run_actions_' . $this->pageSlug);
            $isActionCalled = false;
            if (($currentAction = ControllersManager::getAction()) !== false) {
                $actions = $this->getActions();
                foreach ($actions as $action) {
                    if (!$action instanceof PageAction) {
                        continue;
                    }
                    if ($action->isCurrentAction($this->getCurrentMenuSlugs(), static::getCurrentInnerPage(), $currentAction)) {
                        $action->exec($resultData);
                        $isActionCalled = true;
                    }
                }
            }
            do_action('foldsnap_after_run_actions_' . $this->pageSlug, $isActionCalled);
        } catch (Exception | Error $e) {
            $resultData['actionsError'] = true;
            $errorMsg                   = is_string($resultData['errorMessage']) ? $resultData['errorMessage'] : '';
            $resultData['errorMessage'] = $errorMsg
                . '<b>' . esc_html($e->getMessage()) . '</b><pre>' . esc_html($e->getTraceAsString()) . '</pre>';
        }

        $tplMng->updateGlobalData($resultData);
        if ($resultData['actionsError']) {
            add_filter('admin_body_class', function ($classes): string {
                return (is_string($classes) ? $classes : '') . ' foldsnap-actions-error';
            });
        }
    }

    /**
     * Set controller action
     *
     * @return void
     */
    protected function setActionsAvailables(): void
    {
        $actionsAvailables = [];
        $actions           = $this->getActions();
        foreach ($actions as $action) {
            if (!$action instanceof PageAction) {
                continue;
            }

            if ($action->isPageOfCurrentAction($this->getCurrentMenuSlugs())) {
                $actionsAvailables[$action->getKey()] = $action;
            }
        }
        TplMng::getInstance()->updateGlobalData(['actions' => $actionsAvailables]);
    }

    /**
     * Capability check
     *
     * @return void
     */
    protected function capabilityCheck(): void
    {
        if (!current_user_can($this->capatibility)) {
            self::notPermsDie();
        }
    }

    /**
     * Execute controller logic
     *
     * @return void
     */
    public function run(): void
    {
        if (
            !$this->isEnabled() ||
            SanitizeInput::str(SanitizeInput::INPUT_REQUEST, 'page') !== $this->pageSlug
        ) {
            return;
        }

        ob_start();
        $this->setTemplateData();
        $this->capabilityCheck();
        $tplMng = TplMng::getInstance();
        /** @var array<string, mixed> $tplData */
        $tplData = apply_filters('foldsnap_page_template_data_' . $this->pageSlug, $tplMng->getGlobalData());
        $tplMng->updateGlobalData($tplData);
        $this->setActionsAvailables();
        $this->runActions();

        $invalidOutput = Sanitize::obCleanAll();
        ob_end_clean();
        if (strlen($invalidOutput)) {
            $tplMng->setGlobalValue('invalidOutput', trim($invalidOutput));
        }
    }

    /**
     * Render page
     *
     * @return void
     */
    public function render(): void
    {
        try {
            do_action(
                'foldsnap_before_render_page_' . $this->pageSlug,
                $this->getCurrentMenuSlugs(),
                static::getCurrentInnerPage()
            );
            TplMng::setStripSpaces(true);
            $tplMng = TplMng::getInstance();
            $tplMng->render('parts/messages');
            do_action(
                'foldsnap_render_page_content_' . $this->pageSlug,
                $this->getCurrentMenuSlugs(),
                static::getCurrentInnerPage()
            );

            do_action(
                'foldsnap_after_render_page_' . $this->pageSlug,
                $this->getCurrentMenuSlugs(),
                static::getCurrentInnerPage()
            );
        } catch (Exception | Error $e) {
            echo '<pre>' . esc_html($e->getMessage() . "\n" . $e->getTraceAsString()) . '</pre>';
        }
    }

    /**
     * Return available actions
     *
     * @return PageAction[]
     */
    public function getActions(): array
    {
        /** @var PageAction[] */
        return apply_filters('foldsnap_page_actions_' . $this->pageSlug, []);
    }

    /**
     * Get action by key
     *
     * @param string $key Action key
     *
     * @return PageAction|false return false if not found
     */
    public function getActionByKey(string $key)
    {
        foreach ($this->getActions() as $action) {
            if ($action->getKey() == $key) {
                return $action;
            }
        }
        return false;
    }

    /**
     * Return page slug
     *
     * @return string
     */
    public function getSlug(): string
    {
        return $this->pageSlug;
    }

    /**
     * Return current main page link
     *
     * @return string
     */
    public function getPageUrl(): string
    {
        return ControllersManager::getMenuLink($this->pageSlug);
    }

    /**
     * Return menu page hook suffix or false if not set
     *
     * @return string|false
     */
    public function getMenuHookSuffix()
    {
        return $this->menuHookSuffix;
    }

    /**
     * Register admin page
     *
     * @return false|string
     */
    public function registerMenu()
    {
        if (!$this->isEnabled() || !current_user_can($this->capatibility)) {
            return false;
        }

        /** @var string $pageTitle */
        $pageTitle = apply_filters('foldsnap_page_title_' . $this->pageSlug, $this->pageTitle);
        add_action('admin_init', [$this, 'run']);

        $this->menuHookSuffix = add_submenu_page('', $pageTitle, '', $this->capatibility, $this->pageSlug, [$this, 'render']);
        add_action('admin_print_styles-' . $this->menuHookSuffix, [$this, 'pageStyles'], 20);
        add_action('admin_print_scripts-' . $this->menuHookSuffix, [$this, 'pageScripts'], 20);
        return $this->menuHookSuffix;
    }

    /**
     * Called on admin_print_styles-[page] hook
     *
     * @return void
     */
    public function pageStyles(): void
    {
    }

    /**
     * Called on admin_print_scripts-[page] hook
     *
     * @return void
     */
    public function pageScripts(): void
    {
    }

    /**
     * Return true if current page is this page
     *
     * @return bool
     */
    public function isCurrentPage(): bool
    {
        $levels = ControllersManager::getMenuLevels();
        return $levels[ControllersManager::QUERY_STRING_MENU_KEY_L1] === $this->pageSlug;
    }

    /**
     * Return current slugs.
     *
     * @return string[]
     */
    protected function getCurrentMenuSlugs(): array
    {
        $levels = ControllersManager::getMenuLevels();

        $result    = [];
        $result[0] = $levels[ControllersManager::QUERY_STRING_MENU_KEY_L1];

        return $result;
    }

    /**
     * Return current inner page, default string if is not set
     *
     * @param string $default Default value
     *
     * @return string
     */
    public static function getCurrentInnerPage(string $default = ''): string
    {
        $result = SanitizeInput::strictStr(SanitizeInput::INPUT_REQUEST, ControllersManager::QUERY_STRING_INNER_PAGE, '', '-_');
        return strlen($result) > 0 ? $result : $default;
    }

    /**
     * Die script with not access message
     *
     * @return void
     */
    protected static function notPermsDie(): void
    {
        wp_die(esc_html__('You do not have sufficient permissions to access this page.', 'foldsnap'));
    }
}
