<?php

/**
 * Plugin bootstrap
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Core;

use FoldSnap\Controllers\MediaLibraryController;
use FoldSnap\Controllers\RestApiController;
use FoldSnap\Core\Controllers\ControllersManager;
use FoldSnap\Services\TaxonomyService;

final class Bootstrap
{
    /**
     * Initialize the plugin
     *
     * @return void
     */
    public static function init(): void
    {
        add_action('init', [self::class, 'onInit']);
    }

    /**
     * Run plugin initialization on the WordPress init hook
     *
     * @return void
     */
    public static function onInit(): void
    {
        TaxonomyService::register();
        RestApiController::getInstance();
        MediaLibraryController::getInstance();

        if (is_admin()) {
            ControllersManager::getInstance();

            $menuHook = is_multisite() ? 'network_admin_menu' : 'admin_menu';
            add_action($menuHook, [self::class, 'menu']);
        }
    }

    /**
     * Register admin menu pages
     *
     * @return void
     */
    public static function menu(): void
    {
        ControllersManager::getInstance()->registerMenu();
    }
}
