<?php

/**
 * Main page controller
 *
 * Registers a "FoldSnap" sub-menu entry under Media. Hosts internal
 * maintenance tooling — currently exposes a Recount action that drives the
 * counter recalculator until completion. All media-library asset injection
 * stays in MediaLibraryController.
 *
 * @package FoldSnap
 */

declare(strict_types=1);

namespace FoldSnap\Controllers;

use FoldSnap\Core\Controllers\AbstractMenuPageController;
use FoldSnap\Core\Controllers\ControllersManager;
use FoldSnap\Core\Views\TplMng;

final class MainPageController extends AbstractMenuPageController
{
    private const ASSET_HANDLE = 'foldsnap-settings';

    /**
     * Class constructor
     */
    protected function __construct()
    {
        $this->pageSlug     = ControllersManager::MAIN_MENU_SLUG;
        $this->pageTitle    = __('FoldSnap', 'foldsnap');
        $this->menuLabel    = __('FoldSnap', 'foldsnap');
        $this->capatibility = 'manage_options';
        $this->menuPos      = 10;
        $this->parentSlug   = 'upload.php';

        add_action(
            'foldsnap_render_page_content_' . $this->pageSlug,
            [
                $this,
                'renderContent',
            ]
        );
    }

    /**
     * Render the settings page body via the template engine.
     *
     * @return void
     */
    public function renderContent(): void
    {
        TplMng::getInstance()->render('page/settings');
    }

    /**
     * Enqueue assets for the settings page (overrides AbstractSinglePageController hook target).
     *
     * @return void
     */
    public function pageScripts(): void
    {
        $assetFile = FOLDSNAP_PATH . '/assets/js/foldsnap-settings.asset.php';
        if (!file_exists($assetFile)) {
            return;
        }

        /** @var array{dependencies: string[], version: string} $asset */
        $asset = require $assetFile;

        wp_enqueue_script(
            self::ASSET_HANDLE,
            FOLDSNAP_PLUGIN_URL . '/assets/js/foldsnap-settings.js',
            $asset['dependencies'],
            $asset['version'],
            true
        );

        wp_set_script_translations(self::ASSET_HANDLE, 'foldsnap', FOLDSNAP_PATH . '/languages');
    }

    /**
     * Enqueue stylesheet for the settings page.
     *
     * @return void
     */
    public function pageStyles(): void
    {
        wp_enqueue_style(
            self::ASSET_HANDLE,
            FOLDSNAP_PLUGIN_URL . '/assets/css/foldsnap-settings.css',
            [],
            FOLDSNAP_VERSION
        );
    }
}
