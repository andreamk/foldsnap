<?php

/**
 * Singleton class that manages the various controllers of the administration of WordPress
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Core\Controllers;

use FoldSnap\Controllers\MainPageController;
use FoldSnap\Utils\Sanitize;
use FoldSnap\Utils\SanitizeInput;

/**
 * ControllersManager
 */
final class ControllersManager
{
    const MAIN_MENU_SLUG               = 'foldsnap';
    const QUERY_STRING_MENU_KEY_L1     = 'page';
    const QUERY_STRING_MENU_KEY_L2     = 'tab';
    const QUERY_STRING_MENU_KEY_L3     = 'subtab';
    const QUERY_STRING_MENU_KEY_ACTION = 'action';
    const QUERY_STRING_INNER_PAGE      = 'inner_page';

    /** @var ?self */
    private static ?self $instance = null;

    /**
     * Return controller manager instance
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
    protected function __construct()
    {
        add_action('init', [$this, 'hookWpInit']);
    }

    /**
     * Method called on WordPress hook init action
     *
     * @return void
     */
    public function hookWpInit(): void
    {
        foreach (self::getMenuPages() as $menuPage) {
            if (!$menuPage->isEnabled()) {
                continue;
            }

            $menuPage->hookWpInit();
        }
    }

    /**
     * Return true if current page is a plugin page
     *
     * @return boolean
     */
    public function isPluginPage(): bool
    {
        foreach (self::getMenuPages() as $menuPage) {
            if (!$menuPage->isEnabled()) {
                continue;
            }

            if ($menuPage->isCurrentPage()) {
                return true;
            }
        }

        return false;
    }

    /**
     * Return current menu levels
     *
     * @return array<string, string>
     */
    public static function getMenuLevels(): array
    {
        return [
            self::QUERY_STRING_MENU_KEY_L1 => SanitizeInput::str(SanitizeInput::INPUT_REQUEST, self::QUERY_STRING_MENU_KEY_L1, '', Sanitize::STRIP_WHITESPACE),
            self::QUERY_STRING_MENU_KEY_L2 => SanitizeInput::str(SanitizeInput::INPUT_REQUEST, self::QUERY_STRING_MENU_KEY_L2, '', Sanitize::STRIP_WHITESPACE),
            self::QUERY_STRING_MENU_KEY_L3 => SanitizeInput::str(SanitizeInput::INPUT_REQUEST, self::QUERY_STRING_MENU_KEY_L3, '', Sanitize::STRIP_WHITESPACE),
        ];
    }

    /**
     * Return current action key or false if not exists
     *
     * @return string|false
     */
    public static function getAction()
    {
        $result = SanitizeInput::str(SanitizeInput::INPUT_REQUEST, self::QUERY_STRING_MENU_KEY_ACTION, '', Sanitize::STRIP_WHITESPACE);
        if (strlen($result) === 0) {
            return false;
        }
        return $result;
    }

    /**
     * Check current page
     *
     * @param string      $page  page key
     * @param null|string $tabL1 tab level 1 key, null not check
     * @param null|string $tabL2 tab level 2 key, null not check
     *
     * @return boolean
     */
    public static function isCurrentPage(string $page, ?string $tabL1 = null, ?string $tabL2 = null): bool
    {
        $levels = self::getMenuLevels();

        if ($page !== $levels[self::QUERY_STRING_MENU_KEY_L1]) {
            return false;
        }

        $controller = self::getPageControlleBySlug($page);
        if (false === $controller) {
            return false;
        }
        $menuSlugs = $controller->getCurrentMenuSlugs();

        if (!is_null($tabL1) && (!isset($menuSlugs[1]) || $tabL1 !== $menuSlugs[1])) {
            return false;
        }

        if (!is_null($tabL1) && !is_null($tabL2) && (!isset($menuSlugs[2]) || $tabL2 !== $menuSlugs[2])) {
            return false;
        }

        return true;
    }

    /**
     * Return unique id by levels page/tabs
     *
     * @param string      $page  page slug
     * @param null|string $tabL1 tab level 1 slug, null not set
     * @param null|string $tabL2 tab level 2 slug, null not set
     *
     * @return string
     */
    public static function getPageUniqueId(string $page, ?string $tabL1 = null, ?string $tabL2 = null): string
    {
        $result = 'foldsnap_id_' . $page;

        if (is_string($tabL1) && strlen($tabL1) > 0) {
            $result .= '_' . $tabL1;
        }

        if (is_string($tabL1) && strlen($tabL1) > 0 && is_string($tabL2) && strlen($tabL2) > 0) {
            $result .= '_' . $tabL2;
        }

        return $result;
    }

    /**
     * Return unique id of current page
     *
     * @return string
     */
    public static function getUniqueIdOfCurrentPage(): string
    {
        $levels = self::getMenuLevels();
        return self::getPageUniqueId(
            $levels[self::QUERY_STRING_MENU_KEY_L1],
            $levels[self::QUERY_STRING_MENU_KEY_L2],
            $levels[self::QUERY_STRING_MENU_KEY_L3]
        );
    }

    /**
     * Return current menu page URL with inner page if is set
     *
     * @param array<string,string|int> $extraData extra value in query string key=val
     *
     * @return string
     */
    public static function getCurrentLink(array $extraData = []): string
    {
        $levels = self::getMenuLevels();

        if (!isset($extraData[self::QUERY_STRING_INNER_PAGE])) {
            $inner = SanitizeInput::strictStr(SanitizeInput::INPUT_REQUEST, self::QUERY_STRING_INNER_PAGE, '', '-_');
            if (strlen($inner) > 0) {
                $extraData[self::QUERY_STRING_INNER_PAGE] = $inner;
            }
        }

        return self::getMenuLink(
            $levels[self::QUERY_STRING_MENU_KEY_L1],
            $levels[self::QUERY_STRING_MENU_KEY_L2],
            $levels[self::QUERY_STRING_MENU_KEY_L3],
            $extraData
        );
    }

    /**
     * Return menu page URL
     *
     * @param string               $page      page slug
     * @param null|string          $subL2     tab level 1 slug, null not set
     * @param null|string          $subL3     tab level 2 slug, null not set
     * @param array<string, mixed> $extraData extra value in query string key=val
     * @param bool                 $relative  if true return relative path or absolute
     *
     * @return string
     */
    public static function getMenuLink(string $page, ?string $subL2 = null, ?string $subL3 = null, array $extraData = [], bool $relative = true): string
    {
        $data = (array) $extraData;

        $data[self::QUERY_STRING_MENU_KEY_L1] = $page;

        if (!empty($subL2)) {
            $data[self::QUERY_STRING_MENU_KEY_L2] = $subL2;
        }

        if (!empty($subL3)) {
            $data[self::QUERY_STRING_MENU_KEY_L3] = $subL3;
        }

        if ($relative) {
            $url = is_multisite() ? network_admin_url('admin.php', 'relative') : admin_url('admin.php', 'relative');
        } else {
            $url = is_multisite() ? network_admin_url('admin.php') : admin_url('admin.php');
        }
        return $url . '?' . http_build_query($data);
    }

    /**
     * Return menu pages list
     *
     * @return AbstractMenuPageController[]
     */
    public static function getMenuPages(): array
    {
        /** @var AbstractMenuPageController[]|null */
        static $basicMenuPages = null;

        if (is_null($basicMenuPages)) {
            $basicMenuPages   = [];
            $basicMenuPages[] = MainPageController::getInstance();
        }

        /** @var AbstractMenuPageController[] $pages */
        $pages = apply_filters('foldsnap_menu_pages', $basicMenuPages);

        return array_filter(
            $pages,
            fn(AbstractMenuPageController $menuPage): bool => $menuPage->isEnabled()
        );
    }

    /**
     * Return menu pages list sorted by position
     *
     * @return AbstractMenuPageController[]
     */
    protected static function getMenuPagesSortedByPos(): array
    {
        $menuPages = self::getMenuPages();

        uksort($menuPages, function ($a, $b) use ($menuPages): int {
            if ($menuPages[$a]->getPosition() == $menuPages[$b]->getPosition()) {
                if ($a == $b) {
                    return 0;
                } elseif ($a > $b) {
                    return 1;
                } else {
                    return -1;
                }
            } elseif ($menuPages[$a]->getPosition() > $menuPages[$b]->getPosition()) {
                return 1;
            } else {
                return -1;
            }
        });
        return array_values($menuPages);
    }

    /**
     * Return page controller by slug or false if don't exist
     *
     * @param string $slug page key
     *
     * @return false|AbstractMenuPageController
     */
    public static function getPageControlleBySlug(string $slug)
    {
        $menuPages = self::getMenuPages();
        foreach ($menuPages as $page) {
            if ($page->getSlug() === $slug) {
                return $page;
            }
        }

        return false;
    }

    /**
     * Register menu pages
     *
     * @return void
     */
    public function registerMenu(): void
    {
        $menuPages = self::getMenuPagesSortedByPos();

        // before register main pages
        foreach ($menuPages as $menuPage) {
            if (!$menuPage->isEnabled() || !$menuPage->isMainPage()) {
                continue;
            }

            $menuPage->registerMenu();
        }

        // after register secondary pages
        foreach ($menuPages as $menuPage) {
            if (!$menuPage->isEnabled() || $menuPage->isMainPage()) {
                continue;
            }

            $menuPage->registerMenu();
        }
    }
}
