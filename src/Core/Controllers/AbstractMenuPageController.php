<?php

/**
 * Abstract class that manages a menu page and sub-menus.
 * Rendering the page automatically generates the page wrapper and level 2 and 3 menus.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Core\Controllers;

use FoldSnap\Core\Views\TplMng;
use Error;
use Exception;

abstract class AbstractMenuPageController extends AbstractSinglePageController implements ControllerInterface
{
    protected string $parentSlug = '';
    protected string $menuLabel  = '';
    protected string $iconUrl    = '';
    protected int $menuPos       = 100;
    /** @var SubMenuItem[] */
    protected array $subMenus = [];

    /**
     * Get page menu link
     *
     * @param string               $subL2     sub menu level 2 (main page tabs)
     * @param string               $subL3     sub menu level 3 (sub tabs of tab)
     * @param array<string, mixed> $extraData extra query string values
     *
     * @return string
     */
    public function getMenuLink(?string $subL2 = null, ?string $subL3 = null, array $extraData = []): string
    {
        return ControllersManager::getMenuLink($this->pageSlug, $subL2, $subL3, $extraData);
    }

    /**
     * Set template data function.
     *
     * @return void
     */
    protected function setTemplateData(): void
    {
        parent::setTemplateData();

        $tplMng = TplMng::getInstance();

        $currentMenuSlugs  = $this->getCurrentMenuSlugs();
        $currentSubMenuObj = null;

        $menuItemsL2 = $this->getSubMenuItems('');
        for ($i = 0; $i < count($menuItemsL2); $i++) {
            $menuItemsL2[$i]->link   = ControllersManager::getMenuLink($this->pageSlug, $menuItemsL2[$i]->slug);
            $menuItemsL2[$i]->active = ($menuItemsL2[$i]->slug === $currentMenuSlugs[1]);
            if ($menuItemsL2[$i]->active) {
                $currentSubMenuObj = $menuItemsL2[$i];
            }
        }
        $tplMng->setGlobalValue('menuItemsL2', $menuItemsL2);

        $menuItemsL3 = $this->getSubMenuItems($currentMenuSlugs[1]);
        for ($i = 0; $i < count($menuItemsL3); $i++) {
            $menuItemsL3[$i]->link   = ControllersManager::getMenuLink($this->pageSlug, $currentMenuSlugs[1], $menuItemsL3[$i]->slug);
            $menuItemsL3[$i]->active = ($menuItemsL3[$i]->slug === $currentMenuSlugs[2]);
            if ($menuItemsL3[$i]->active) {
                $currentSubMenuObj = $menuItemsL3[$i];
            }
        }
        $tplMng->setGlobalValue('menuItemsL3', $menuItemsL3);
        $tplMng->setGlobalValue('currentSubMenuObj', $currentSubMenuObj);
    }

    /**
     * Return body header template. Can be overridden by child classes for custom header.
     *
     * @param string[] $currentLevelSlugs current menu slugs
     * @param string   $innerPage         current inner page, empty if not set
     *
     * @return string
     */
    protected function getBodyHeaderTpl(array $currentLevelSlugs, string $innerPage): string
    {
        return 'parts/admin_headers/wpbody_header';
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
            $tplMng->render('page/page_header');
            $tplMng->render('parts/messages');
            $tplMng->render(
                $this->getBodyHeaderTpl(
                    $this->getCurrentMenuSlugs(),
                    static::getCurrentInnerPage()
                )
            );
            $tplMng->render('parts/tabs_menu_l3');
            do_action(
                'foldsnap_render_page_content_' . $this->pageSlug,
                $this->getCurrentMenuSlugs(),
                static::getCurrentInnerPage()
            );
            $tplMng->render('page/page_footer');

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
     * Return current position
     *
     * @return int
     */
    public function getPosition(): int
    {
        return $this->menuPos;
    }

    /**
     * Register admin page
     *
     * @return false|string
     */
    public function registerMenu()
    {
        if (strlen($this->menuLabel) == 0) {
            return false;
        }

        if (!$this->isEnabled() || !current_user_can($this->capatibility)) {
            return false;
        }

        /** @var string $pageTitle */
        $pageTitle = apply_filters('foldsnap_page_title_' . $this->pageSlug, $this->pageTitle);
        /** @var string $menuLabel */
        $menuLabel = apply_filters('foldsnap_menu_label_' . $this->pageSlug, $this->menuLabel);

        add_action('admin_init', [$this, 'run']);

        if (strlen($this->parentSlug) > 0) {
            $this->menuHookSuffix = add_submenu_page(
                $this->parentSlug,
                $pageTitle,
                $menuLabel,
                $this->capatibility,
                $this->pageSlug,
                [
                    $this,
                    'render',
                ],
                $this->menuPos
            );
        } else {
            $this->menuHookSuffix = add_menu_page(
                $pageTitle,
                $menuLabel,
                $this->capatibility,
                $this->pageSlug,
                [
                    $this,
                    'render',
                ],
                $this->iconUrl,
                $this->menuPos
            );
        }

        add_action('admin_print_styles-' . $this->menuHookSuffix, [$this, 'pageStyles'], 20);
        add_action('admin_print_scripts-' . $this->menuHookSuffix, [$this, 'pageScripts'], 20);

        return $this->menuHookSuffix;
    }

    /**
     * Return true if this controller is main page
     *
     * @return boolean
     */
    public function isMainPage(): bool
    {
        return (strlen($this->parentSlug) === 0);
    }

    /**
     * Return sub menu full list
     *
     * @return SubMenuItem[]
     */
    protected function getSubMenuList(): array
    {
        /** @var SubMenuItem[] */
        return apply_filters('foldsnap_sub_menu_items_' . $this->pageSlug, []);
    }

    /**
     * Return list of sub menus of parent page
     *
     * @param string $parent parent page
     *
     * @return SubMenuItem[]
     */
    protected function getSubMenuItems(string $parent = ''): array
    {
        /** @var SubMenuItem[] */
        $subMenus = $this->getSubMenuList();

        $result = array_filter($subMenus, function (SubMenuItem $item) use ($parent): bool {
            if (!$item->userCan()) {
                return false;
            }
            return $item->parent === $parent;
        });

        uksort($result, function ($a, $b) use ($result): int {
            if ($result[$a]->position == $result[$b]->position) {
                if ($a == $b) {
                    return 0;
                } elseif ($a > $b) {
                    return 1;
                } else {
                    return -1;
                }
            } elseif ($result[$a]->position > $result[$b]->position) {
                return 1;
            } else {
                return -1;
            }
        });

        return array_values($result);
    }

    /**
     * Return current slugs.
     *
     * @param bool $checkSlugExists if true check slug is registered and return menu level false if don't exists
     *
     * @return string[]
     */
    public function getCurrentMenuSlugs(bool $checkSlugExists = true): array
    {
        $levels = ControllersManager::getMenuLevels();

        $result    = [];
        $result[0] = $levels[ControllersManager::QUERY_STRING_MENU_KEY_L1];
        if (strlen($levels[ControllersManager::QUERY_STRING_MENU_KEY_L2]) === 0) {
            $result[1] = $this->getDefaultSubMenuSlug('');
        } elseif ($checkSlugExists && !$this->slugExists($levels[ControllersManager::QUERY_STRING_MENU_KEY_L2], '')) {
            $result[1] = $this->getDefaultSubMenuSlug('');
        } else {
            $result[1] = $levels[ControllersManager::QUERY_STRING_MENU_KEY_L2];
        }

        if (strlen($levels[ControllersManager::QUERY_STRING_MENU_KEY_L3]) === 0) {
            $result[2] = $this->getDefaultSubMenuSlug($result[1]);
        } elseif ($checkSlugExists && !$this->slugExists($levels[ControllersManager::QUERY_STRING_MENU_KEY_L3], $result[1])) {
            $result[2] = $this->getDefaultSubMenuSlug($result[1]);
        } else {
            $result[2] = $levels[ControllersManager::QUERY_STRING_MENU_KEY_L3];
        }

        return $result;
    }

    /**
     * Capability check
     *
     * @return void
     */
    protected function capabilityCheck(): void
    {
        parent::capabilityCheck();

        $currentMenuSlugs = $this->getCurrentMenuSlugs();
        $checkSlug        = '';
        if (strlen($currentMenuSlugs[2]) > 0) {
            $checkSlug = $currentMenuSlugs[2];
        } elseif (strlen($currentMenuSlugs[1]) > 0) {
            $checkSlug = $currentMenuSlugs[1];
        } else {
            return;
        }

        $subMenus = $this->getSubMenuList();
        foreach ($subMenus as $item) {
            if ($item->slug != $checkSlug) {
                continue;
            }

            if (!$item->userCan()) {
                self::notPermsDie();
            }
        }
    }

    /**
     * Return sub menu slugs for given parent
     *
     * @param string $parent parent page
     *
     * @return string[]
     */
    protected function getSubMenuSlugs(string $parent = ''): array
    {
        $result = [];
        foreach ($this->getSubMenuItems($parent) as $item) {
            $result[] = $item->slug;
        }
        return $result;
    }

    /**
     * Check if $slug is child of $parent
     *
     * @param string $slug   slug page/tab
     * @param string $parent parent slug
     *
     * @return boolean
     */
    protected function slugExists(string $slug, string $parent = ''): bool
    {
        if (strlen($slug) === 0) {
            return false;
        }

        return in_array($slug, $this->getSubMenuSlugs($parent), true);
    }

    /**
     * Return default sub menu slug or empty string if don't exists
     *
     * @param string $parent slug page/tab
     *
     * @return string
     */
    protected function getDefaultSubMenuSlug(string $parent = ''): string
    {
        /** @var string|false $slug */
        $slug = apply_filters('foldsnap_sub_level_default_tab_' . $this->pageSlug, false, $parent);

        if (!is_string($slug) || strlen($slug) === 0 || !$this->slugExists($slug, $parent)) {
            $slugs = $this->getSubMenuSlugs($parent);
            return (count($slugs) === 0) ? '' : $slugs[0];
        }

        return $slug;
    }
}
